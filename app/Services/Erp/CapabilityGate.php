<?php

namespace App\Services\Erp;

use App\Models\Organization;
use App\Models\SystemSetting;

class CapabilityGate
{
    public function __construct(
        protected ?Organization $organization = null,
    ) {}

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

        return array_merge($defaults, is_array($custom) ? $custom : []);
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
        }

        return [
            'organization_id' => $this->organization?->id,
            'deployment_profile' => $profile,
            'profile_label' => $profileConfig['label'] ?? $profile,
            'modules' => $this->allModules(),
            'channels' => $this->allowedChannels(),
            'workflows' => $this->workflowForOrg(),
            'module_settings' => $moduleSettings,
            'allow_negative_stock' => (bool) ($system?->allow_below_stock ?? false),
        ];
    }

    /** @return array<string, mixed> */
    protected function workflowForOrg(): array
    {
        return OrderWorkflowService::forGate($this)->workflowsByChannel();
    }
}
