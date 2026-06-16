<?php

namespace App\Services\Erp;

use App\Models\Organization;
use App\Models\SystemSetting;
use App\Services\Ai\AiSettingsResolver;
use App\Services\Erp\GeneralSettingsResolver;
use App\Services\Mpesa\MpesaSettingsResolver;

class CapabilityGate
{
    public function __construct(
        protected ?Organization $organization = null,
    ) {}

    public function organization(): ?Organization
    {
        return $this->organization;
    }

    public function forOrganization(Organization $organization): self
    {
        return new self($organization);
    }

    public function enabled(string $moduleKey): bool
    {
        if (! $this->organization) {
            return false;
        }

        $overrides = $this->organization->enabled_modules ?? [];
        if (is_array($overrides) && array_key_exists($moduleKey, $overrides)) {
            return (bool) $overrides[$moduleKey];
        }

        $profile = $this->organization->deployment_profile ?? 'wholesale_retail';
        $profiles = config('erp.profiles', []);

        return (bool) ($profiles[$profile]['modules'][$moduleKey] ?? false);
    }

    /** @return array<string, bool> */
    public function allModules(): array
    {
        $keys = array_keys(config('erp.modules', []));
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->enabled($key);
        }
        return $out;
    }

    /** @return list<string> */
    public function allowedChannels(): array
    {
        $channels = [];
        if ($this->enabled('sales.pos')) {
            $channels[] = 'pos';
        }
        if ($this->enabled('sales.mobile')) {
            $channels[] = 'mobile';
        }
        if ($this->enabled('sales.backend')) {
            $channels[] = 'backend';
        }
        return $channels;
    }

    public function channelEnabled(string $channel): bool
    {
        return match ($channel) {
            'pos' => $this->enabled('sales.pos'),
            'mobile' => $this->enabled('sales.mobile'),
            'backend' => $this->enabled('sales.backend'),
            default => false,
        };
    }

    public function moduleSettings(string $section = 'sales'): array
    {
        $defaults = config("erp.module_settings_defaults.{$section}", []);
        $custom = $this->organization?->module_settings[$section] ?? [];
        $merged = array_merge($defaults, is_array($custom) ? $custom : []);

        if ($section === 'finance') {
            $defaultMpesa = is_array($defaults['mpesa'] ?? null) ? $defaults['mpesa'] : [];
            $customMpesa = is_array($custom['mpesa'] ?? null) ? $custom['mpesa'] : [];
            $merged['mpesa'] = array_merge($defaultMpesa, $customMpesa);
        }

        return $merged;
    }

    /** When inventory is reduced: order_completed (workflow status), trip_load, or trip_depart. */
    public function stockDeductTiming(): string
    {
        $sales = $this->moduleSettings('sales');
        if (array_key_exists('stock_deduct_on', $sales)) {
            $timing = (string) $sales['stock_deduct_on'];
            if (in_array($timing, ['order_completed', 'trip_load', 'trip_depart'], true)) {
                return $timing;
            }
        }

        $dist = is_array($this->organization?->module_settings['distribution'] ?? null)
            ? $this->organization->module_settings['distribution']
            : [];
        $legacy = (string) ($dist['deduct_stock_on'] ?? 'order_completed');

        return in_array($legacy, ['order_completed', 'trip_load', 'trip_depart'], true)
            ? $legacy
            : 'order_completed';
    }

    public function shouldDeferStockToTrip(): bool
    {
        return $this->distributionOpsEnabled()
            && in_array($this->stockDeductTiming(), ['trip_load', 'trip_depart'], true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $profile = $this->organization?->deployment_profile ?? 'wholesale_retail';
        $profileConfig = config("erp.profiles.{$profile}", []);

        $system = $this->organization
            ? SystemSetting::query()
                ->where('organization_id', $this->organization->id)
                ->orderBy('id')
                ->first()
            : null;

        $moduleSettings = array_merge(
            config('erp.module_settings_defaults', []),
            $this->organization?->module_settings ?? [],
        );
        if ($this->organization) {
            $sales = is_array($moduleSettings['sales'] ?? null) ? $moduleSettings['sales'] : [];
            $sales['order_workflow'] = OrderWorkflowService::forGate($this)->config();
            $moduleSettings['sales'] = $sales;

            $moduleSettings['general'] = GeneralSettingsResolver::forGate($this);

            $finance = is_array($moduleSettings['finance'] ?? null) ? $moduleSettings['finance'] : [];
            if (isset($finance['mpesa']) && is_array($finance['mpesa'])) {
                $finance['mpesa'] = MpesaSettingsResolver::maskForClient($finance['mpesa']);
                $moduleSettings['finance'] = $finance;
            }

            $ai = is_array($moduleSettings['ai'] ?? null) ? $moduleSettings['ai'] : [];
            $moduleSettings['ai'] = AiSettingsResolver::maskForClient(
                array_merge(AiSettingsResolver::defaults(), $ai)
            );
        }

        return [
            'organization_id' => $this->organization?->id,
            'deployment_profile' => $profile,
            'profile_label' => $profileConfig['label'] ?? $profile,
            'distribution_ops_enabled' => $this->distributionOpsEnabled(),
            'modules' => $this->allModules(),
            'channels' => $this->allowedChannels(),
            'workflows' => $this->workflowForOrg(),
            'module_settings' => $moduleSettings,
            'ai_assistant' => $this->organization
                ? AiSettingsResolver::clientCapabilities($this)
                : ['enabled' => false, 'available' => false],
            'allow_negative_stock' => (bool) ($system?->allow_below_stock ?? false),
            'stock_alert_mode' => $system?->stock_alert_mode ?? 'per_product',
            'global_low_stock_threshold' => $system?->global_low_stock_threshold,
            'general' => $this->organization
                ? GeneralSettingsResolver::forGate($this)
                : GeneralSettingsResolver::normalize(GeneralSettingsResolver::defaults()),
            'session_idle_minutes' => $this->organization
                ? \App\Services\Auth\SecuritySettingsResolver::forGate($this)['session_idle_minutes']
                : (int) config('erp.session_idle_minutes', 15),
        ];
    }

    /** @return array<string, mixed> */
    protected function workflowForOrg(): array
    {
        return OrderWorkflowService::forGate($this)->workflowsByChannel();
    }

    public function distributionOpsEnabled(): bool
    {
        $dist = $this->distributionSettings();
        if (array_key_exists('enable_distribution_ops', $dist)) {
            return (bool) $dist['enable_distribution_ops'];
        }

        return ($this->organization?->deployment_profile ?? '') === 'distribution';
    }

    /** @return array<string, mixed> */
    public function distributionSettings(): array
    {
        $defaults = config('erp.module_settings_defaults.distribution', []);
        $custom = $this->organization?->module_settings['distribution'] ?? [];
        $merged = array_merge($defaults, is_array($custom) ? $custom : []);

        $legacyKeys = [
            'enable_distribution_ops',
            'inherit_customer_route',
            'assign_on_status',
            'auto_assign_truck',
            'auto_assign_driver',
            'require_weight_on_load',
            'set_delivery_date_on',
            'require_pod_on_delivered',
        ];
        $sales = is_array($this->organization?->module_settings['sales'] ?? null)
            ? $this->organization->module_settings['sales']
            : [];

        foreach ($legacyKeys as $key) {
            if (! array_key_exists($key, $custom) && array_key_exists($key, $sales)) {
                $merged[$key] = $sales[$key];
            }
        }

        return $merged;
    }
}
