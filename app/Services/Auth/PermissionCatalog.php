<?php

namespace App\Services\Auth;

use App\Models\Permission;
use Illuminate\Support\Collection;

/**
 * Request-scoped in-memory catalog of the permissions table.
 *
 * Slow log on 2026-11-07 (~30-min window on prod `centrix`) showed the 461-row
 * `permissions` table hit per authenticated request:
 *   - 29× SELECT * WHERE id IN (...)
 *   - 27× SELECT permission_code WHERE module = ?
 *   - 17× SELECT *
 *   - 10× SELECT id WHERE module = ?
 *
 * Each is <1ms (buffer-pool hot), but log_queries_not_using_indexes is on and the
 * sheer per-request count dominates query noise.
 *
 * ## Design — adaptive, not eager
 *
 * The first iteration of this class primed every lookup from a single
 * SELECT *, so even a simple `idByCode('sales.view')` on a route with one
 * permission check pulled 461 Eloquent models (~2.5 MiB resident) and a
 * full-table scan. Oracle review (2026-11-07) flagged this as a regression vs
 * status quo for narrow routes.
 *
 * Fix: lookups are now adaptive.
 *
 *   - idByCode() / codeById() / byCode() / idsForCodes() do indexed
 *     point lookups and memoize per code if the full catalog has not been
 *     primed yet. A route that only checks one permission issues one
 *     indexed `WHERE permission_code = ?` query and caches the result.
 *   - all() / allIds() / forIds() / forModule() / idsForModule() /
 *     codesForModule() still fall back to SELECT * because they need
 *     every row. Once primed, all subsequent lookups serve from memory.
 *
 * After a bulk path primes the catalog, point lookups also serve from the
 * primed maps — so admin/matrix paths pay one SELECT * and zero follow-ups,
 * while narrow API routes pay 1-3 indexed point queries and stay cheap.
 *
 * ## Scope and invalidation
 *
 * Bound as `scoped` in AppServiceProvider — one instance per HTTP / queue
 * request. Framework resets scoped instances between queue jobs
 * (QueueServiceProvider::forgetScopedInstances) so no cross-request bleed.
 *
 * Not a persistent cache: PermissionMatrixService::ensureCached() already
 * covers the registry heal cascade. The wasted work here is N queries per
 * request, not DB time itself.
 *
 * Call flush() from any code path that writes to the permissions table in
 * the same process — PermissionMatrixService::ensure() wraps itself in
 * try/finally to guarantee this.
 */
class PermissionCatalog
{
    private ?Collection $permissions = null;

    /** @var array<string, int|false> code → id, or false when a point lookup missed */
    private array $idByCode = [];

    /** @var array<int, string> id → code; only populated when all() has been primed */
    private array $codeById = [];

    /**
     * Narrow-path model cache for byCode(). Uses `false` for negative hits so a
     * missing code doesn't re-query on every call. Populated separately from the
     * $permissions keyed collection because that is only filled by all().
     *
     * @var array<string, Permission|false>
     */
    private array $modelByCode = [];

    /** @var array<string, list<int>>|null module → list<id>; only populated when all() has been primed */
    private ?array $idsByModule = null;

    /** @var list<int>|null cached allIds() output; only populated when all() has been primed */
    private ?array $allIds = null;

    /**
     * Clear memoized state. Call after writing to the permissions table within
     * the same process so later reads see the just-written rows.
     */
    public function flush(): void
    {
        $this->permissions = null;
        $this->idByCode = [];
        $this->codeById = [];
        $this->modelByCode = [];
        $this->idsByModule = null;
        $this->allIds = null;
    }

    /** @return Collection<int, Permission> keyed by id */
    public function all(): Collection
    {
        if ($this->permissions === null) {
            $this->permissions = Permission::query()->get()->keyBy('id');
            // Re-prime point-lookup maps from the now-authoritative row set,
            // so a missed point lookup earlier in the request does not shadow
            // a row that genuinely exists. Keys are normalized via normalizeCode()
            // because MySQL `utf8mb4_0900_ai_ci` matches case-insensitively but
            // PHP array keys are byte-exact — without normalization, a caller
            // using mixed-case would miss the cache and fall to the primed-miss
            // branch, returning null for a row that genuinely exists.
            $this->idByCode = [];
            $this->codeById = [];
            $this->modelByCode = [];
            foreach ($this->permissions as $id => $permission) {
                $canonical = (string) $permission->permission_code;
                $key = $this->normalizeCode($canonical);
                $this->idByCode[$key] = (int) $id;
                $this->codeById[(int) $id] = $canonical;
                $this->modelByCode[$key] = $permission;
            }
        }

        return $this->permissions;
    }

    /** @return list<int> */
    public function allIds(): array
    {
        if ($this->allIds === null) {
            $this->allIds = $this->all()
                ->keys()
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
        }

        return $this->allIds;
    }

    public function idByCode(string $permissionCode): ?int
    {
        $permission = $this->byCode($permissionCode);

        return $permission === null ? null : (int) $permission->id;
    }

    public function codeById(int $id): ?string
    {
        if (isset($this->codeById[$id])) {
            return $this->codeById[$id];
        }

        if ($this->permissions !== null) {
            return null;
        }

        $code = Permission::query()->where('id', $id)->value('permission_code');

        if ($code === null) {
            return null;
        }

        $this->codeById[$id] = (string) $code;
        $this->idByCode[$this->normalizeCode((string) $code)] = $id;

        return (string) $code;
    }

    public function byCode(string $permissionCode): ?Permission
    {
        $key = $this->normalizeCode($permissionCode);

        if (array_key_exists($key, $this->modelByCode)) {
            $cached = $this->modelByCode[$key];

            return $cached === false ? null : $cached;
        }

        if ($this->permissions !== null) {
            // Bulk catalog primed and this code was absent.
            $this->modelByCode[$key] = false;
            $this->idByCode[$key] = false;

            return null;
        }

        // Narrow point lookup — avoid priming the whole catalog.
        $permission = Permission::query()
            ->where('permission_code', $permissionCode)
            ->first();

        if ($permission === null) {
            $this->modelByCode[$key] = false;
            $this->idByCode[$key] = false;

            return null;
        }

        // Store the model's canonical permission_code (not the caller-provided one)
        // in codeById so the reverse map reflects DB truth under case-insensitive
        // collation. Point-lookup maps are keyed by the normalized form so later
        // lookups with different casing still hit the cache.
        $canonical = (string) $permission->permission_code;
        $this->modelByCode[$key] = $permission;
        $this->idByCode[$key] = (int) $permission->id;
        $this->codeById[(int) $permission->id] = $canonical;

        return $permission;
    }

    /**
     * Normalize a permission code for byte-exact PHP array keying under MySQL's
     * utf8mb4_0900_ai_ci (case- and accent-insensitive) collation. Permission
     * codes are ASCII-only lowercase by convention; strtolower is enough.
     */
    private function normalizeCode(string $code): string
    {
        return strtolower($code);
    }

    /**
     * @param  iterable<int|string>  $ids
     * @return Collection<int, Permission> keyed by id
     */
    public function forIds(iterable $ids): Collection
    {
        $all = $this->all();
        $out = collect();
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($all->has($id)) {
                $out->put($id, $all->get($id));
            }
        }

        return $out;
    }

    /** @return list<int> */
    public function idsForModule(string $module): array
    {
        $this->primeModuleMap();

        return $this->idsByModule[$module] ?? [];
    }

    /** @return list<string> */
    public function codesForModule(string $module): array
    {
        $ids = $this->idsForModule($module);
        $all = $this->all();
        $codes = [];
        foreach ($ids as $id) {
            $permission = $all->get($id);
            if ($permission !== null) {
                $codes[] = (string) $permission->permission_code;
            }
        }

        return $codes;
    }

    /** @return Collection<int, Permission> permissions belonging to $module, keyed by id */
    public function forModule(string $module): Collection
    {
        return $this->forIds($this->idsForModule($module));
    }

    /**
     * Resolve many permission codes to ids in a single query (unresolved codes only).
     * Preserves input order, drops codes with no matching row, and does not
     * deduplicate — SQL `WHERE IN` would return each id once, but callers of this
     * method either feed unique arrays (SalesReportUserScope) or hand the result
     * to insertOrIgnore (RoleTemplateService), so duplicate-tolerance is a no-op.
     *
     * Keys use the normalized (lowercased) form so SQL's case-insensitive
     * `utf8mb4_0900_ai_ci` match aligns with PHP's byte-exact array keys.
     *
     * @param  iterable<string>  $permissionCodes
     * @return list<int>
     */
    public function idsForCodes(iterable $permissionCodes): array
    {
        $inputKeys = [];
        foreach ($permissionCodes as $code) {
            $inputKeys[] = $this->normalizeCode((string) $code);
        }
        if ($inputKeys === []) {
            return [];
        }

        // Batch-resolve any codes not already in the point-cache. Use the
        // normalized key for lookups and for the whereIn (SQL's collation
        // will match any casing of the stored permission_code).
        $missing = [];
        foreach ($inputKeys as $key) {
            if (array_key_exists($key, $this->idByCode)) {
                continue;
            }
            if ($this->permissions !== null) {
                // Bulk primed but code absent — mark as miss to skip a DB round-trip.
                $this->idByCode[$key] = false;

                continue;
            }
            $missing[$key] = true;
        }
        if ($missing !== []) {
            // Fetch full models so byCode() hits the same cache entry.
            // The 461-row table makes the extra columns per batch call cheap,
            // and the alternative (scalar id/code only) forces any byCode() call
            // later in the request to re-query for the model.
            $rows = Permission::query()
                ->whereIn('permission_code', array_keys($missing))
                ->get();
            foreach ($rows as $row) {
                $canonical = (string) $row->permission_code;
                $key = $this->normalizeCode($canonical);
                $this->idByCode[$key] = (int) $row->id;
                $this->codeById[(int) $row->id] = $canonical;
                $this->modelByCode[$key] = $row;
                unset($missing[$key]);
            }
            // Codes still in $missing were not found — cache the negative hit.
            foreach ($missing as $key => $_) {
                $this->idByCode[$key] = false;
                $this->modelByCode[$key] = false;
            }
        }

        $out = [];
        foreach ($inputKeys as $key) {
            $cached = $this->idByCode[$key] ?? false;
            if ($cached !== false) {
                $out[] = $cached;
            }
        }

        return $out;
    }

    private function primeModuleMap(): void
    {
        if ($this->idsByModule !== null) {
            return;
        }
        $this->idsByModule = [];
        foreach ($this->all() as $id => $permission) {
            $module = (string) $permission->module;
            $this->idsByModule[$module][] = (int) $id;
        }
    }
}
