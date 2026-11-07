# Centrix ERP Backend API — Recent Changes

Branch: `main` · Working tree: clean · Generated from `git log` on 2026-11-07.

Window covered: **2026-10-28 → 2026-11-07** (last ~40 commits).

---

## Session summary — 2026-11-07 DB performance work

Six commits shipped to `origin/main` today in a focused push on database
query performance. All deployed to production via the migration-job Helm
hook on each push. Post-deploy verification done via `SHOW INDEX`, EXPLAIN,
and slow-log sampling on `centrix-db`.

| Commit | Area | Headline impact |
|---|---|---|
| `c4809dff` | till reports | ~37× faster debtor-collections SUM (10 ms → 0.27 ms) |
| `0ba25dba` | migrations hygiene | Correctness-only; prevents future drift |
| `75a86d52` | docs | Changelog captures above two commits |
| `020bbc69` | permissions | Per-request permissions queries: ~140/hr → ~24/hr (~83% drop) |
| `ea7e64d5` | organizations | `company_code` lookup: function-scan → PK-equivalent `const` |
| `97553afb` | sales | `legacy_import` JSON predicate indexed via generated column |

Slow-log hourly entry counts over the deploy window:

| Hour (UTC) | Count | Note |
|---|---:|---|
| 08:00 | 367 | pre-deploys |
| 09:00 | 427 | peak, mid-deploy |
| 10:00 | 348 | permissions catalog deployed ~10:37 |
| 11:00 | 215 | ~50% drop from peak |
| 12:00 | 210 | holding |
| 13:00 | 169 | — |
| 14:00 | 238 | — |
| 15:00 | 260 | — |
| 16:00 | 193 | — |
| 17:00 | 163 | — |

Net: **~50-60% reduction in slow-log hourly rate**, maintained across the
full post-deploy window. The remaining floor is dominated by polling queries
(`platform_speed_synthetic_checks`, `attendance_clock_devices`,
`hikvision_access_events`) and the `PermissionCatalog` bulk prime — all
`<1 ms` queries that only appear because `log_queries_not_using_indexes` is
on.

### Also investigated, no fix shipped

- **Sales-by-\* report views (`v_sales_by_user`, `v_sales_by_product`, etc.)**
  — 1-2 sec per `COUNT(*)`. Tested three fix paths on Docker MySQL 8.4.13 with
  a restored 43k-row production dump: (1) rewriting the JSON predicate via the
  new `legacy_materialized` column, (2) forcing `idx_sales_legacy_pipeline`
  via `FROM sales s FORCE INDEX (...)` in the view definitions, (3) tuning
  `sort_buffer_size` / `join_buffer_size`. None moved the needle. Root cause
  is the GROUP BY + JOIN downstream work on 40k rows, not the `legacy_import`
  filter. Real fix is caching (Redis `Cache::remember` or materialized table
  via cron) — app-code change, deferred.
- **DB server tuning** — buffer pool is 8.6× larger than the entire 464 MB
  dataset, hit ratio 99.9944%, zero tmp-table disk spills, zero evictions.
  No MySQL config change available that measurably helps the remaining
  workload.
- **Redis cache** — 5 MB of 768 MB used, hit ratio 12% (app-level strategy
  issue, not infra). Cache is architected for historical review but most
  reads are today's data, which it deliberately refuses to serve.
- **PHP opcache** — 99.87% hit rate on Apache/mod_php, zero wasted memory,
  zero OOM restarts. Nothing to tune.
- **Apache MPM** — `prefork` with `MaxRequestWorkers=150`. At current pod
  memory limit (1.5 GiB) that ceiling is a latent OOM landmine — 150
  workers × 66 MB ≈ 9.9 GB. Current steady state uses only 10 workers so no
  immediate risk, but worth narrowing or switching to `event` MPM + PHP-FPM
  in a future container-image change.

---

## 1. Performance (Nov 7)

High-impact hot-path fixes landed in a single burst after the Symfony CVE
patch unblocked CI.

| Commit | Area | Change | Measured impact |
|---|---|---|---|
| `97553afb` | sales reports (JSON predicate) | Two migrations plus helper change for the `legacy_import` exclusion that runs on every sales-by-\* report query. `2026_06_19_000001_add_sales_legacy_materialized_column` (back-dated to land before the earliest view-creating migration) adds VIRTUAL INVISIBLE `sales.legacy_materialized` — a TINYINT projection of `fulfillment_meta->legacy_import` with CASE semantics matching the new `CentrixSalesScope::isLegacyImportFlag()` helper exactly (`true` / `1` / `"1"` / `"true"` → legacy, everything else → live). `2026_11_07_000006_add_sales_legacy_pipeline_index` adds composite index `(organization_id, legacy_materialized, status, archived, effective_sale_date)`. `CentrixSalesScope::excludeLegacyMaterialized` and `legacyExcludeSql` rewritten to emit `legacy_materialized = 0`; the 3 PHP reader sites (`SaleMergeService`, `BackofficeOrderLineEditService`, `PosOrderEditService`) plus `Sale::scopeLegacyMaterialized` migrated to the strict helper (closes a PHP/SQL truthiness mismatch where loose `(bool)` cast accepted `int 2` / `"TRUE"` / arrays as legacy while the SQL CASE never would). 5-pass oracle review caught: eager-loading regression, wrong invalidation boundary, PHP/SQL contract divergence, migration-ordering bug (index references `effective_sale_date` added in `2026_07_05_100001`, forced the split). 20-test regression suite (`SalesLegacyMaterializedScopeTest`) covers normalizer parity, MySQL ↔ PHP truthiness, index-existence, scope semantics; validated against MySQL 8.4.13 in Docker (38 assertions, 6m03s). | Index deployed and verified live via EXPLAIN against prod. Note: MySQL cost model picks `uq_org_order_num` over `idx_sales_legacy_pipeline` (planner wart on covering-vs-ref costs); direct test with FORCE INDEX showed 121 ms → 15 ms (8×), but FORCE INDEX added to view definitions did not transfer the speedup to the view queries themselves. Correctness win is real (strict contract alignment); sales-by-\* latency unchanged and deferred to app-layer caching. |
| `ea7e64d5` | organizations (login hot path) | New migration `2026_11_07_000004_add_organizations_company_code_normalized.php` adds a STORED INVISIBLE generated column on `organizations` with expression `UPPER(REGEXP_REPLACE(company_code, '[^A-Za-z0-9]', ''))`, plus a UNIQUE index `uq_organizations_company_code_normalized`. `Organization::findByCompanyCodeIdentifier()` rewired to query the indexed column instead of the previous function-wrapped predicate (`UPPER(REPLACE(REPLACE(REPLACE(company_code, '-', ''), ' ', ''), '_', ''))`). Same method also fixed to normalize lowercase correctly (previous `[^A-Z0-9]` regex silently stripped lowercase letters pre-uppercase; `moon-co` → `''` was a real bug). Provisioning path updated: `OrganizationProvisioningService::provision()` now calls `OrganizationCompanyCodeService::assertCodeAvailable()` before create and translates late `UniqueConstraintViolationException` into a 422 `ValidationException` scoped tightly to the `Organization::create()` call only. Called from every login, session resume, passkey challenge, mobile attendance clock-in, M-Pesa paybill C2B callback, and password reset. 5-pass oracle review caught: PHP normalizer regex bug, column visibility leak (`STORED INVISIBLE` added), catch too broadly scoped around the full transaction, missing Unicode test, missing HTTP-response invisibility assertion. Prod data preflight on 2026-11-07 confirmed zero normalized-collision across all 15 live tenants. | EXPLAIN: `type ALL` (full scan of 15 rows) → `type const` (1 row); the column is now matched via unique index. At 15 tenants the wall-clock win today is negligible (<2 ms either way), but the predicate scales linearly with tenant count — at 10k tenants it would be a per-login wall-clock cost. Latent fix; ships correctness alignment and prevents that ceiling. |
| `020bbc69` | permissions (per-request) | New `App\Services\Auth\PermissionCatalog` bound as `scoped` in `AppServiceProvider` — one instance per HTTP / queue request, discarded between requests. Adaptive priming: narrow routes pay one indexed `WHERE permission_code = ?` point lookup and memoize; routes that call `all()` / `forModule()` / `forIds()` hydrate the whole 461-row table once and serve all subsequent lookups from memory. Keys normalized via `strtolower()` so MySQL `utf8mb4_0900_ai_ci` matches align with PHP byte-exact array keys. Negative hits cached as `false` sentinel. 10 call sites in `UserPermissionService` rewired (`hasPermission`, `hasDirectPermission`, `hasRoleAssignedPermission`, `grantOrgAdminEnabledModulePermissions`, etc.); 4 read-only sites in `PermissionMatrixService` (after `ensureCached()`); `RoleController`, `SalesReportUserScope`, `RoleTemplateService`. Deliberately NOT rewired: anything inside `ensure()`'s write cascade (would hold stale rows mid-write). Mutation-boundary flush: `ensure()` and `healLoginGrantsCached()` wrap in `try/finally` with `app(PermissionCatalog::class)->flush()` so a cold-cache `ensureCached()` during a `RoleController` request doesn't let the same request read pre-write state. 4-pass oracle review caught: eager-loading regression, wrong invalidation boundary, `byCode()` cache incomplete, login-heal bypass, N-query `idsForCodes`, gated path still 2 queries, case-insensitive collation vs exact PHP keys. | Slow-log permissions queries: `~140/hour` pre-fix → `~24/hour` post-deploy (projected rate from first 25 min). All four pre-fix shapes (`SELECT * WHERE id IN (…)`, `permission_code WHERE module = ?`, `SELECT *`, `SELECT id WHERE module = ?`) **have zero post-cutover occurrences**. Remaining `SELECT * FROM permissions` entries are the catalog's single bulk prime per request (one per minute, expected). Narrow API routes drop from ~20-40 permissions queries per request to ~1-3 point queries. Gated `hasPermission` check: 2 queries → 1 (the `hasDirectPermission` → `permissionAllowedByGate` sequence now shares one cached model). |
| `75a86d52` | docs | Changelog captures the till-reports covering index + the migration hygiene fix (both from earlier the same day). Replaces the previous `_(uncommitted)_` placeholders with real commit hashes. | Documentation-only. |
| `0ba25dba` | migrations hygiene | Hardened `2026_06_20_000001_sale_payments_float_session.php`: the original wrapped both the column add and the index create inside one `Schema::hasColumn` gate, so if the column pre-existed via another path the `$table->index('float_session_id')` call was silently skipped — the exact failure mode observed on prod (migration recorded in batch 4, but `SHOW INDEX FROM sale_payments` had no `float_session_id` entry). Split into two independent idempotent guards (one for the column, one for the narrow index via an `indexExists()` helper matching `2026_11_07_000002`'s pattern), and reordered `down()` to drop the index before the column. Prod isn't re-run (Laravel only applies each migration once) but the covering index from `000003` already supersedes the narrow one there; the fix matters for `migrate:fresh` on local / CI / new tenants, which now reproducibly get both indexes. | Correctness-only; no runtime timing delta. Prevents future drift of the same class on any env that runs `migrate:fresh`. |
| `c4809dff` | till reports | New migration `2026_11_07_000003_add_sale_payments_float_session_covering_index.php` adds covering composite index `idx_sp_float_sale_amount (float_session_id, sale_id, amount)` on `sale_payments`. Serves `TillReportMetrics::applyDebtorCollectionFilter` (`SUM(sp.amount) … WHERE sp.float_session_id = ? …`) fired by every x-report poll. Prod EXPLAIN on 2026-11-07 showed `sp` type `ALL` — the single-column `float_session_id` index from `2026_06_20_000001` was missing on prod despite the migration having run (batch 4). Covering composite answers the SUM straight from the index. Applied by hand on prod ahead of this migration landing; migration is idempotent so `migrate --force` is a no-op there. | `float_session_id=95` (15 matching rows of 43,682): type `ALL` → `ref`, rows examined 43,511 → 15, Extra `Using where` → `Using index`, warm query **10 ms → 0.27 ms** (~37×). Also drops out of the slow log entirely (now under the 0.5s `long_query_time`; only appeared because `log_queries_not_using_indexes` was on). |
| `f1ed374d` | background tasks | New migration `2026_11_07_000002_add_background_tasks_status_index.php` adds a **status-only index** on `background_tasks`. The existing `(organization_id, type, status)` composite could not serve `SELECT * … WHERE status IN ('pending','running')` because its leading column was unmatched. | Slow log `Rows_examined` 928 → ~0 per poll. |
| `9bd2d967` | sales / stock | New migration `2026_11_07_000001_add_sales_pending_stock_deduct_index.php` adds a **VIRTUAL INVISIBLE** generated column mapping `fulfillment_meta->pending_stock_deduct` (true / 1 / 'true') to TINYINT, plus composite index `(stock_balanced, pending_stock_deduct, deleted_at)`. `FinalizePendingSaleStockCommand` rewritten to query the indexed column. | Full-table scan of ~43k rows (~0.7s) → indexed lookup. `VIRTUAL` chosen to avoid the `ALGORITHM=COPY` rebuild `STORED` would force on MySQL 8.4. `INVISIBLE` keeps `Model::replicate()` and API serializers clean. |
| `9e6049b4` | reports | `ReportController::reportFromViewOverall` no longer calls Laravel's `->paginate()` (which runs its own `COUNT(*)` against the expensive view subquery). Replaced with a hand-built `LengthAwarePaginator` that reuses `summary.row_count` from `aggregateFilteredReportSummary`. | salesByProduct (30-day, 476 rows): 3 view executions → 2 (~-33% DB time). |
| `8aa9824e` | reports | `aggregateFilteredReportSummary` now skips the sample-row probe when `effectiveSumColumns` is passed explicitly (the view-overall path always does). Direct callers passing `null` keep the probe + `summableColumnsForView` discovery. | salesByProduct: 4 queries / ~8070 ms → 3 queries / ~6000 ms (-25%). |
| `82ab8e81` | permissions | `PermissionMatrixService::enabledPermissionIds`, `groupedForUi`, and `buildRegistryModuleGroup` switched from `self::ensure()` to `self::ensureCached()`. `UserController::permissions()` also moved to the cached path. | `/roles/permissions/matrix`: was running the 15-step heal cascade 3× per request (~15s / 5630 queries). |
| `ea29c5d3` | permissions | `RoleController::permissionMatrix` switched to `ensureCached()`. The cache is keyed by registry version, 7-day TTL, so a deploy that bumps the registry still runs `ensure()` exactly once on cold boot. | Cold `ensure()`: 5107 ms / 1405 queries → `ensureCached()`: **6.5 ms / 0 queries**. Fixes slow `/admin/roles` page. |

Provisioning service and artisan commands still call `self::ensure()` directly,
so they force-refresh on deploy / tenant provisioning.

---

## 2. Security (Nov 7)

- **`adcdf5c6` — Symfony CVE patch (disclosed 2026-11-04).**
  `composer audit` had flagged 10 advisories against `symfony/http-foundation`,
  `http-kernel`, `routing`, `serializer`, `filesystem`, which hard-fails the CI
  deploy workflow and was blocking every backend push. Patch-level bumps
  within existing major.minor pins (`composer update --with-dependencies`):

  - `symfony/filesystem` v8.1.0 → v8.1.11
  - `symfony/http-foundation` v7.4.20 → v7.4.23
  - `symfony/http-kernel` v7.4.20 → v7.4.23
  - `symfony/routing` v7.4.20 → v7.4.23
  - `symfony/serializer` v8.1.1 → v8.1.11

  `composer.lock` only; no behavior changes. `composer audit` now clean.

- **`f4868211` (Oct 28)** — earlier housekeeping bump of
  `symfony/mime` and `symfony/polyfill-mbstring`.

---

## 3. Infrastructure / Deploy (Nov 4–6)

- **`df26673f`** — `config/database.php` now enables PDO TLS when
  `MYSQL_ATTR_SSL_CA` is set, with a new sibling env knob
  `MYSQL_ATTR_SSL_VERIFY_SERVER_CERT` (defaults `true`; flip to `false` for
  self-signed server certs whose SAN does not cover the connection host).
  MySQL 8.4 enforces TLS for the `caching_sha2_password` handshake even when
  `require_secure_transport` is OFF, so without this PDO/mysqlnd fails with
  `SQLSTATE[HY000] [1045] Access denied` regardless of credentials.
  Verified against the Ansible-provisioned MySQL at `10.0.0.1`.
- **`9f34c1ae`** — CI dispatch step now uses `CENTRIXERPK3SETUP_DISPATCH_TOKEN`.
- **`b9a73607`** — Helm deploy is dispatched via `repository_dispatch` instead
  of being inlined.
- **`edf3c3db`** — Dockerfile and CI workflows tuned.
- **`23808c8e`** — Centrix version handling unified across the Docker
  workflow and API responses.

---

## 4. Database migrations landed since Oct 28

Listed newest-first. Back-dated filenames are flagged — Laravel orders
migrations lexically by filename, not creation time, so back-dating is the
canonical way to force an ordering dependency (details in each migration's
PHPDoc).

| Migration | Purpose |
|---|---|
| `2026_11_07_000006_add_sales_legacy_pipeline_index.php` | Composite index `(organization_id, legacy_materialized, status, archived, effective_sale_date)` on `sales`. Pairs with the back-dated column migration below. |
| `2026_11_07_000004_add_organizations_company_code_normalized.php` | STORED INVISIBLE generated column + UNIQUE index for tenant lookup. Used by `Organization::findByCompanyCodeIdentifier()` on every login / session resume / password reset / paybill callback. |
| `2026_11_07_000003_add_sale_payments_float_session_covering_index.php` | Covering composite index `(float_session_id, sale_id, amount)` on `sale_payments`. Supersedes the single-column index from `2026_06_20_000001`. |
| `2026_11_07_000002_add_background_tasks_status_index.php` | Status-only index on `background_tasks` for in-flight polling. |
| `2026_11_07_000001_add_sales_pending_stock_deduct_index.php` | `pending_stock_deduct` virtual column + composite index on `sales`. |
| `2026_06_19_000001_add_sales_legacy_materialized_column.php` | **Back-dated** (written 2026-11-07, filed before the earliest view-creating migration at `2026_06_20_120000`). Adds VIRTUAL INVISIBLE `sales.legacy_materialized` so `CentrixSalesScope::legacyExcludeSql()` can emit `s.legacy_materialized = 0`. Index for the column lives in `2026_11_07_000006` because it references `effective_sale_date` which isn't added until `2026_07_05_100001`. |
| `4b13da80` (Nov 4) | Drops the legacy unique index on `employee_leave_days` and ensures the `employee_id` index exists. |
| `f520d4d5` (Oct 29) | KRA agent command expiry index. |

---

## 5. M-Pesa / payments (Oct 30 – Nov 4)

Significant surface changes in the M-Pesa integration:

- **`62b28ee1`** — M-Pesa STK push settings + core functionality added.
- **`c830d8fb`** — C2B URL registration endpoint added.
- **`2d464fd5`** — C2B URL registration returns a detailed response and logs;
  new **STK query service**.
- **`953a3e0f`** — Reduced connection / timeout settings for the M-Pesa URL
  registration service.
- **`c4900ce3`** — STK error handling: detailed logging and response messages.
- **`94054fbf`** — Phone number validation now requires **Kenyan format**;
  friendlier error message.
- **`31484cd0`** — STK request **locking** to prevent duplicate prompts; better
  handling of pending requests.
- **`2a2a21bb`** — Test: manual payment is exposed in organization billing when
  a paybill is configured.

---

## 6. Billing / organizations

- **`5391febf`** — Subscription and contract handling improved across billing
  services and controllers.
- **`df40460b`** — Organizations now carry a **`registration_source`**; new
  **failed jobs endpoint** added.

---

## 7. KRA / mobile sales (Oct 28–29)

- **`c4351ec3`** — Organization-specific KRA **Comstore API key** handling,
  with tests updated.
- **`307d1b1e`** — Workflow PLU line handling for fractional quantities;
  product-name sanitisation.
- **`f520d4d5`** — Mobile sales route handling + KRA agent command expiry
  index.
- **`9ff6dc41`** — Mobile sales and product listing pagination that does **not**
  count total items (cheap "next page?" style).
- **`f50580cb`** — Version bump to `1.0.34`.

---

## 8. Marketing / blog / portfolio

- **`f232922f`** — Image generation functionality removed from blog and
  portfolio services; related routes and tests updated.
- **`395d5ee5`** — While it still existed: better error messages for image
  generation + test for the 404 response.
- Several `Add new product image for DEMO organization` commits
  (`21908a3b`, `ec3cde47`, `972b4c55`, `0cbb9838`, `fc384392`, `a592e639`,
  `e91490ec`, `0bd707d0`) — demo-data image drops, no code impact.

---

## Deploy / rollout checklist

1. Run migrations (`php artisan migrate`) — picks up the two Nov-7 index
   migrations and the Nov-4 `employee_leave_days` migration.
2. Confirm `MYSQL_ATTR_SSL_CA` (and optionally
   `MYSQL_ATTR_SSL_VERIFY_SERVER_CERT=false`) are set in the deployment env if
   the target MySQL is 8.4 with `caching_sha2_password`.
3. After deploy, the first `/roles/permissions/matrix` and
   `/admin/roles` hits will warm the permission-matrix cache (one-time
   `ensure()` run keyed by registry version).
4. `composer audit` should report **no advisories**; CI deploy will fail
   otherwise.
