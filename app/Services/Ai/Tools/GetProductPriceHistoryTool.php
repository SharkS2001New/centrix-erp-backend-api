<?php

namespace App\Services\Ai\Tools;

use App\Models\PriceHistory;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\AiNearMissHelper;
use App\Services\Ai\Tools\Concerns\ResolvesAiToolOrganization;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Formal product price-change history from Centrix price_history (not realized sales averages).
 */
class GetProductPriceHistoryTool implements AiToolInterface
{
    use ResolvesAiToolOrganization;

    public function __construct(
        protected ErpContext $erp,
        protected UserPermissionService $permissions,
    ) {}

    public function name(): string
    {
        return 'get_product_price_history';
    }

    public function description(): string
    {
        return 'Get Centrix formal price history for a product (unit price, cost price, discount %, who changed it, when). '
            .'Use for "price history", "when did the price change", "previous selling price", or price-change audit questions. '
            .'This is the /price-history ledger — not realized averages from sales. '
            .'Pass product_code from @Product mentions when available. Never claim Centrix lacks price history.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'product_code' => [
                    'type' => 'string',
                    'description' => 'Exact product SKU / product_code when known (from @Product).',
                ],
                'product_name' => [
                    'type' => 'string',
                    'description' => 'Product name search when product_code is unknown.',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Fallback search (name or SKU).',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max history rows (default 40, max 100).',
                ],
                'days' => [
                    'type' => 'integer',
                    'description' => 'Optional lookback window in days (e.g. 90). Omit for full history up to limit.',
                ],
            ],
        ];
    }

    public function execute(User $user, array $arguments): array
    {
        $organization = $this->resolveOrganizationForUser($user);
        if (! $organization) {
            throw ValidationException::withMessages([
                'organization' => ['Your account is not linked to an organization.'],
            ]);
        }
        if (! $this->assertSameOrganization($user, $organization)) {
            throw ValidationException::withMessages([
                'organization' => ['You cannot query another organization.'],
            ]);
        }

        $orgId = (int) $organization->id;
        $gate = $this->erp->gateForUser($user);
        $canView = $this->permissions->hasPermission($user, 'ai.assist', $gate)
            || $this->permissions->hasPermission($user, 'pricing_tax.price_history.view', $gate)
            || $this->permissions->hasPermission($user, 'catalogue.products.view', $gate)
            || $this->permissions->hasPermission($user, 'inventory.stock.view', $gate)
            || $this->permissions->hasPermission($user, 'sales.pos', $gate);
        if (! $canView) {
            return [
                'error' => true,
                'message' => 'You do not have permission to view price history.',
                'screens' => $this->screens(null),
            ];
        }

        if (! Schema::hasTable('price_history')) {
            return [
                'error' => true,
                'message' => 'Price history is not available in this Centrix installation.',
                'screens' => $this->screens(null),
            ];
        }

        $code = trim((string) ($arguments['product_code'] ?? ''));
        $name = trim((string) ($arguments['product_name'] ?? $arguments['query'] ?? ''));
        if ($code === '' && $name === '') {
            return [
                'error' => true,
                'message' => 'Provide product_code or product_name (e.g. from an @Product mention).',
                'screens' => $this->screens(null),
            ];
        }

        $resolved = $this->resolveProduct($orgId, $code, $name);
        if (($resolved['error'] ?? false) === true) {
            return array_merge($resolved, ['screens' => $this->screens(null)]);
        }

        /** @var Product $product */
        $product = $resolved['product'];
        $productCode = (string) $product->product_code;
        $limit = max(1, min(100, (int) ($arguments['limit'] ?? 40)));
        $days = (int) ($arguments['days'] ?? 0);

        $query = PriceHistory::query()
            ->where('organization_id', $orgId)
            ->where('product_code', $productCode)
            ->with(['changedByUser:id,username,full_name'])
            ->orderByDesc('changed_at')
            ->orderByDesc('id');

        if ($days > 0) {
            $query->where('changed_at', '>=', now()->subDays(max(1, min(3650, $days)))->startOfDay());
        }

        $rows = $query->limit($limit)->get();

        // Chronological (oldest → newest) so previous_* deltas are correct for the reply.
        $chronological = $rows->sortBy(function (PriceHistory $row) {
            return [(string) $row->changed_at, (int) $row->id];
        })->values();

        $history = [];
        $prevUnit = null;
        $prevCost = null;
        foreach ($chronological as $row) {
            $unit = $row->unit_price !== null ? round((float) $row->unit_price, 4) : null;
            $cost = $row->cost_price !== null ? round((float) $row->cost_price, 4) : null;
            $userName = $row->changedByUser;
            $changedByName = $userRel
                ? trim((string) ($userRel->full_name ?: $userRel->username))
                : null;

            $history[] = [
                'changed_at' => $row->changed_at?->toIso8601String(),
                'changed_at_label' => $row->changed_at
                    ? $row->changed_at->format('d M Y H:i')
                    : null,
                'unit_price' => $unit,
                'previous_unit_price' => $prevUnit,
                'unit_price_delta' => ($unit !== null && $prevUnit !== null) ? round($unit - $prevUnit, 4) : null,
                'cost_price' => $cost,
                'previous_cost_price' => $prevCost,
                'cost_price_delta' => ($cost !== null && $prevCost !== null) ? round($cost - $prevCost, 4) : null,
                'discount_pct' => $row->discount_pct !== null ? round((float) $row->discount_pct, 4) : 0,
                'changed_by_name' => $changedByName !== '' ? $changedByName : null,
            ];

            if ($unit !== null) {
                $prevUnit = $unit;
            }
            if ($cost !== null) {
                $prevCost = $cost;
            }
        }

        // Newest first for the model/UI table.
        $historyNewestFirst = array_reverse($history);

        return [
            'currency' => 'KES',
            'source' => 'price_history',
            'product' => [
                'product_code' => $productCode,
                'product_name' => (string) $product->product_name,
                'current_unit_price' => $product->unit_price !== null ? (float) $product->unit_price : null,
                'current_last_cost_price' => $product->last_cost_price !== null ? (float) $product->last_cost_price : null,
                'profile_path' => '/products/'.rawurlencode($productCode),
            ],
            'count' => count($historyNewestFirst),
            'history' => $historyNewestFirst,
            'screens' => $this->screens($productCode),
            'tip' => 'Answer from this formal Centrix price_history ledger. Show a markdown table: Date | Unit price | Cost price | Discount % | Changed by. '
                .'Name the product; never show product_code in the user-facing table. '
                .'Link /price-history and the product card. Do NOT say Centrix lacks a price-change log. '
                .'Do not substitute realized sales averages for this question unless the user also asks for average selling price.',
        ];
    }

    /**
     * @return array{error?: bool, message?: string, near_miss?: bool, closest_match?: mixed, alternatives?: mixed, customer?: never, product?: Product}
     */
    protected function resolveProduct(int $organizationId, string $code, string $name): array
    {
        $base = Product::query()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at');

        if ($code !== '') {
            $exact = (clone $base)->where('product_code', $code)->first();
            if ($exact) {
                return ['product' => $exact];
            }
        }

        $needle = $name !== '' ? $name : $code;
        $matches = (clone $base)
            ->where(function ($q) use ($needle) {
                $q->where('product_name', 'like', '%'.$needle.'%')
                    ->orWhere('product_code', 'like', '%'.$needle.'%');
            })
            ->orderBy('product_name')
            ->limit(8)
            ->get(['product_code', 'product_name']);

        if ($matches->count() === 1) {
            return ['product' => $matches->first()];
        }

        if ($matches->isEmpty()) {
            $alternatives = $this->suggestClosest($organizationId, $needle);

            return AiNearMissHelper::noExact(
                $needle,
                $alternatives['closest'],
                $alternatives['alternatives'],
                $this->screens(null),
                'Check the product name or SKU, or open /price-history.',
            );
        }

        return [
            'error' => true,
            'near_miss' => true,
            'message' => 'Several products match "'.$needle.'". Pick one:',
            'candidates' => $matches->map(fn (Product $p) => [
                'label' => (string) $p->product_name,
                'product_code' => (string) $p->product_code,
            ])->all(),
        ];
    }

    /**
     * @return array{closest: ?array<string, mixed>, alternatives: list<array<string, mixed>>}
     */
    protected function suggestClosest(int $organizationId, string $searched): array
    {
        $tokens = AiNearMissHelper::tokens($searched);
        if ($tokens === []) {
            return ['closest' => null, 'alternatives' => []];
        }

        $rows = Product::query()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($tokens) {
                foreach ($tokens as $token) {
                    if (mb_strlen($token) < 2) {
                        continue;
                    }
                    $like = '%'.$token.'%';
                    $q->orWhere('product_code', 'like', $like)
                        ->orWhere('product_name', 'like', $like);
                }
            })
            ->orderBy('product_name')
            ->limit(10)
            ->get(['product_code', 'product_name']);

        $ranked = $rows->map(function (Product $p) use ($searched) {
            return [
                'label' => (string) $p->product_name,
                'reason' => AiNearMissHelper::matchReason($searched, (string) $p->product_name),
                'score' => AiNearMissHelper::scoreNameMatch($searched, (string) $p->product_name),
                'product_code' => (string) $p->product_code,
            ];
        })->sortByDesc('score')->values()->all();

        return AiNearMissHelper::splitRankedMatches($ranked);
    }

    /**
     * @return list<array{label: string, path: string}>
     */
    protected function screens(?string $productCode): array
    {
        $screens = [
            ['label' => 'Price history', 'path' => '/price-history'],
            ['label' => 'Products', 'path' => '/products'],
        ];
        if ($productCode) {
            array_unshift($screens, [
                'label' => 'Product',
                'path' => '/products/'.rawurlencode($productCode),
            ]);
        }

        return $screens;
    }
}
