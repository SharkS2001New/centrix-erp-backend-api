<?php

namespace App\Support;

use App\Models\Sale;
use App\Models\User;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\OrderWorkflowService;
use Illuminate\Database\Eloquent\Builder;

class SalesOrderQueuePermissions
{
    /** @return array<string, array<string, mixed>> */
    public static function definitions(): array
    {
        return config('order_queue_permissions', []);
    }

    public static function featureKeyForSlug(string $slug): string
    {
        $normalized = strtolower(trim($slug));

        return 'order_queue_'.str_replace('-', '_', $normalized);
    }

    public static function permissionCodeForSlug(string $slug): string
    {
        return 'sales.'.self::featureKeyForSlug($slug).'.view';
    }

    /** @return list<string> */
    public static function allViewPermissionCodes(): array
    {
        return array_map(
            fn (string $slug) => self::permissionCodeForSlug($slug),
            array_keys(self::definitions()),
        );
    }

    /** @return array<string, string> feature key => display label */
    public static function registryFeatures(): array
    {
        $features = [];
        foreach (self::definitions() as $slug => $def) {
            $key = self::featureKeyForSlug($slug);
            $features[$key] = [
                'label' => (string) ($def['label'] ?? ucfirst($slug)),
                'actions' => ['view'],
            ];
        }

        return $features;
    }

    /** @return list<string> feature keys visible for this org's workflow/settings */
    public static function activeFeatureKeys(?CapabilityGate $gate = null): array
    {
        $keys = [];
        $sales = $gate?->moduleSettings('sales') ?? [];
        $workflow = OrderWorkflowService::forGate($gate ?? app(CapabilityGate::class));
        $enabledStatuses = self::enabledPipelineStatuses($sales, $workflow);

        foreach (self::definitions() as $slug => $def) {
            $key = self::featureKeyForSlug($slug);

            if (! empty($def['always'])) {
                $keys[] = $key;
                continue;
            }

            if (! empty($def['pipeline'])) {
                if (in_array($slug, $enabledStatuses, true)) {
                    $keys[] = $key;
                }
                continue;
            }

            if (! empty($def['terminal'])) {
                if (in_array($slug, ['pending_approval', 'editable'], true)) {
                    if (app(\App\Services\Sales\DiscountApprovalService::class)->discountApprovalEnabled($sales)) {
                        $keys[] = $key;
                    }
                    continue;
                }

                if (! empty($def['requires_any_setting']) && is_array($def['requires_any_setting'])) {
                    if (self::anySalesSettingEnabled($sales, $def['requires_any_setting'])) {
                        $keys[] = $key;
                    }
                    continue;
                }

                $setting = (string) ($def['requires_setting'] ?? '');
                if ($setting === '' || self::salesSettingEnabled($sales, $setting)) {
                    $keys[] = $key;
                }
                continue;
            }

            if (! empty($def['mobile_channel'])) {
                if ($gate !== null && $gate->mobileSalesEnabled()) {
                    $keys[] = $key;
                }
            }
        }

        return $keys;
    }

    /** @return array<string, string> feature key => sidebar label from org workflow */
    public static function labelsForGate(?CapabilityGate $gate = null): array
    {
        $labels = [];
        $sales = $gate?->moduleSettings('sales') ?? [];
        $workflow = OrderWorkflowService::forGate($gate ?? app(CapabilityGate::class));
        $enabledStatuses = self::enabledPipelineStatuses($sales, $workflow);

        foreach (self::definitions() as $slug => $def) {
            $key = self::featureKeyForSlug($slug);
            if ($slug === 'all') {
                $labels[$key] = 'All orders';
                continue;
            }
            if (! empty($def['pipeline']) && in_array($slug, $enabledStatuses, true)) {
                $channelWorkflow = $workflow->forChannel('backend');
                $labels[$key] = (string) (($channelWorkflow['labels'] ?? [])[$slug] ?? $def['label'] ?? ucfirst($slug));
                continue;
            }
            $labels[$key] = (string) ($def['label'] ?? ucfirst($slug));
        }

        return $labels;
    }

    /** @return list<string> */
    protected static function enabledPipelineStatuses(array $sales, OrderWorkflowService $workflow): array
    {
        $custom = $sales['order_workflow']['steps'] ?? null;
        if (is_array($custom) && $custom !== []) {
            return collect($custom)
                ->filter(fn ($step) => ($step['enabled'] ?? true) !== false)
                ->pluck('status')
                ->filter()
                ->map(fn ($status) => (string) $status)
                ->unique()
                ->values()
                ->all();
        }

        $config = $workflow->forChannel('backend');

        return $config['statuses'] ?? [];
    }

    public static function userCanViewSale(
        User $user,
        Sale $sale,
        CapabilityGate $gate,
        UserPermissionService $permissions,
    ): bool {
        if ($permissions->hasPermission($user, self::permissionCodeForSlug('all'), $gate)) {
            return true;
        }

        $channel = (string) ($sale->channel ?: 'backend');
        $status = (string) $sale->status;
        $workflow = OrderWorkflowService::forGate($gate);

        foreach (array_keys(self::definitions()) as $slug) {
            if ($slug === 'all') {
                continue;
            }
            if (! $permissions->hasPermission($user, self::permissionCodeForSlug($slug), $gate)) {
                continue;
            }
            if ($slug === 'mobile' && $channel === 'mobile') {
                return true;
            }
            if (in_array($status, $workflow->statusesForQueueFilter($slug, $channel), true)) {
                return true;
            }
        }

        return false;
    }

    /** @param  Builder<Sale>  $query */
    public static function applyIndexScope(
        Builder $query,
        User $user,
        CapabilityGate $gate,
        UserPermissionService $permissions,
        string $channel = 'backend',
    ): void {
        if ($permissions->hasPermission($user, self::permissionCodeForSlug('all'), $gate)) {
            return;
        }

        $workflow = OrderWorkflowService::forGate($gate);
        $allowedStatuses = [];
        $includeMobile = false;

        foreach (array_keys(self::definitions()) as $slug) {
            if ($slug === 'all') {
                continue;
            }
            if (! $permissions->hasPermission($user, self::permissionCodeForSlug($slug), $gate)) {
                continue;
            }
            if ($slug === 'mobile') {
                $includeMobile = true;
                continue;
            }
            $allowedStatuses = array_merge(
                $allowedStatuses,
                $workflow->statusesForQueueFilter($slug, $channel),
            );
        }

        $allowedStatuses = array_values(array_unique(array_filter($allowedStatuses)));

        $query->where(function (Builder $sub) use ($allowedStatuses, $includeMobile) {
            $applied = false;
            if ($allowedStatuses !== []) {
                $sub->whereIn('sales.status', $allowedStatuses);
                $applied = true;
            }
            if ($includeMobile) {
                if ($applied) {
                    $sub->orWhere('sales.channel', 'mobile');
                } else {
                    $sub->where('sales.channel', 'mobile');
                    $applied = true;
                }
            }
            if (! $applied) {
                $sub->whereRaw('1 = 0');
            }
        });
    }

    /** @param  list<string>  $keys */
    protected static function anySalesSettingEnabled(array $sales, array $keys): bool
    {
        foreach ($keys as $key) {
            if (self::salesSettingEnabled($sales, (string) $key)) {
                return true;
            }
        }

        return false;
    }

    protected static function salesSettingEnabled(array $sales, string $key): bool
    {
        if ($key === 'discount_approval_enabled_mobile' || $key === 'discount_approval_enabled_backoffice') {
            if (array_key_exists($key, $sales)) {
                return ($sales[$key] ?? false) !== false && ! empty($sales[$key]);
            }

            // Legacy orgs only stored the combined flag.
            return ($sales['discount_approval_enabled'] ?? false) !== false
                && ! empty($sales['discount_approval_enabled'] ?? false);
        }

        if ($key === 'discount_approval_enabled') {
            $mobile = array_key_exists('discount_approval_enabled_mobile', $sales)
                ? ! empty($sales['discount_approval_enabled_mobile'])
                : null;
            $backoffice = array_key_exists('discount_approval_enabled_backoffice', $sales)
                ? ! empty($sales['discount_approval_enabled_backoffice'])
                : null;
            if ($mobile !== null || $backoffice !== null) {
                return (bool) $mobile || (bool) $backoffice;
            }

            return ($sales['discount_approval_enabled'] ?? false) !== false
                && ! empty($sales['discount_approval_enabled'] ?? false);
        }

        return ($sales[$key] ?? true) !== false;
    }
}
