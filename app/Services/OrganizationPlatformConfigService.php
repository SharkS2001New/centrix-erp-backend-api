<?php

namespace App\Services;

use App\Models\Organization;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\OrderWorkflowService;

class OrganizationPlatformConfigService
{
    /** @return list<string> */
    public function platformControlledSalesKeys(): array
    {
        return config('erp.platform_controlled.sales', []);
    }

    /** @return list<string> */
    public function platformControlledDistributionKeys(): array
    {
        return config('erp.platform_controlled.distribution', []);
    }

    /**
     * @param  array<string, mixed>  $salesPlatform
     */
    public function applySalesPlatformConfig(Organization $org, array $salesPlatform): Organization
    {
        if ($salesPlatform === []) {
            return $org;
        }

        $gate = app(CapabilityGate::class)->forOrganization($org);
        $workflowService = OrderWorkflowService::forGate($gate);
        $currentSales = $gate->moduleSettings('sales');
        $nextSales = $currentSales;

        foreach ($this->platformControlledSalesKeys() as $key) {
            if (array_key_exists($key, $salesPlatform)) {
                $nextSales[$key] = $salesPlatform[$key];
            }
        }

        if (array_key_exists('order_workflow', $salesPlatform) && is_array($salesPlatform['order_workflow'])) {
            $defaults = config('erp.default_order_workflow', []);
            $nextSales['order_workflow'] = $workflowService->normalize(
                $workflowService->mergeWorkflowConfig($defaults, $salesPlatform['order_workflow']),
            );
        }

        $moduleSettings = $org->module_settings ?? [];
        $moduleSettings['sales'] = $nextSales;
        $org->forceFill(['module_settings' => $moduleSettings])->save();

        return $org->fresh();
    }

    /**
     * Default platform sales config for a new tenant.
     *
     * @return array<string, mixed>
     */
    public function defaultSalesPlatformConfig(string $deploymentProfile = 'wholesale_retail'): array
    {
        return [
            'show_checkout_on_create_order' => true,
            'enable_mobile_orders' => true,
            'stock_deduct_on' => 'order_completed',
            'order_workflow' => config('erp.default_order_workflow', []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function salesPlatformConfigForOrganization(Organization $org): array
    {
        $gate = app(CapabilityGate::class)->forOrganization($org);
        $sales = $gate->moduleSettings('sales');
        $workflow = OrderWorkflowService::forGate($gate)->config();

        return [
            'show_checkout_on_create_order' => (bool) ($sales['show_checkout_on_create_order'] ?? true),
            'enable_mobile_orders' => (bool) ($sales['enable_mobile_orders'] ?? true),
            'stock_deduct_on' => (string) ($sales['stock_deduct_on'] ?? 'order_completed'),
            'order_workflow' => $workflow,
        ];
    }

    /**
     * Strip keys tenant managers cannot change via org settings API.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function filterOrgManagerSalesPayload(array $data): array
    {
        foreach ($this->platformControlledSalesKeys() as $key) {
            unset($data[$key]);
        }
        unset($data['order_workflow']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function filterOrgManagerDistributionPayload(array $data): array
    {
        foreach ($this->platformControlledDistributionKeys() as $key) {
            unset($data[$key]);
        }
        unset($data['enable_distribution_ops']);

        return $data;
    }
}
