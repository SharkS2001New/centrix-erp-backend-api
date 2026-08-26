<?php

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Services\Ai\AiInsightCatalog;
use App\Services\Ai\AiInsightDataBuilder;
use App\Services\Ai\Tools\Concerns\HasAiPeriodParameters;
use App\Services\Ai\Tools\Concerns\ResolvesAiToolOrganization;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use Illuminate\Validation\ValidationException;

/**
 * Run Centrix AI Insights data slices from chat (anomaly, forecast, margin watchdog, etc.).
 */
class RunInsightTool implements AiToolInterface
{
    use HasAiPeriodParameters;
    use ResolvesAiToolOrganization;

    public function __construct(
        protected AiInsightDataBuilder $insightData,
        protected ErpContext $erp,
        protected UserPermissionService $permissions,
    ) {}

    public function name(): string
    {
        return 'run_insight';
    }

    public function description(): string
    {
        return 'Run a Centrix AI Insight and return its data slice for analysis. Use for anomaly_detection, '
            .'forecast_light, margin_discount_watchdog, exception_radar, customer_360, procurement_companion, '
            .'collections_playbook, branch_till_benchmarks, product_demand, and other insight types. '
            .'customer_360 requires customer_num. Prefer this over inventing anomaly/forecast/margin data.';
    }

    public function parametersSchema(): array
    {
        $types = array_filter(
            AiInsightCatalog::allTypes(),
            fn (string $t) => $t !== 'explain_screen',
        );

        return [
            'type' => 'object',
            'properties' => [
                'insight_type' => [
                    'type' => 'string',
                    'enum' => array_values($types),
                    'description' => 'Insight catalog type (e.g. anomaly_detection, forecast_light).',
                ],
                'lookback_days' => [
                    'type' => 'integer',
                    'description' => 'Days of history (1–90). Default from insight catalog.',
                ],
                'customer_num' => [
                    'type' => 'string',
                    'description' => 'Required for customer_360.',
                ],
                'product_code' => [
                    'type' => 'string',
                    'description' => 'Optional focus SKU for product_demand.',
                ],
            ],
            'required' => ['insight_type'],
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

        $gate = $this->erp->gateForUser($user);
        if (! $this->permissions->hasPermission($user, 'ai.assist', $gate)
            && ! $this->permissions->hasPermission($user, 'reports.view', $gate)) {
            return [
                'error' => true,
                'message' => 'You do not have permission to run AI insights.',
            ];
        }

        $type = str_replace('-', '_', trim((string) ($arguments['insight_type'] ?? '')));
        if (! AiInsightCatalog::isKnown($type) || $type === 'explain_screen') {
            $valid = implode(', ', array_values(array_filter(
                AiInsightCatalog::allTypes(),
                fn (string $t) => $t !== 'explain_screen',
            )));

            return [
                'error' => true,
                'message' => 'Unknown or unsupported insight type'
                    .($type !== '' ? " \"{$type}\"" : '')
                    .'. Valid insight_type values: '.$valid
                    .'. For what a customer has been buying / purchase mix, call get_customer_statement instead (not run_insight).',
                'suggest_tool' => 'get_customer_statement',
                'valid_insight_types' => array_values(array_filter(
                    AiInsightCatalog::allTypes(),
                    fn (string $t) => $t !== 'explain_screen',
                )),
            ];
        }

        if ($type === 'customer_360' && trim((string) ($arguments['customer_num'] ?? '')) === '') {
            return [
                'error' => true,
                'message' => 'customer_num is required for customer_360 insight.',
            ];
        }

        $defaultLookback = (int) (AiInsightCatalog::definitions()[$type]['default_lookback'] ?? 7);
        $lookback = (int) ($arguments['lookback_days'] ?? $defaultLookback);
        $lookback = max(1, min(90, $lookback));

        $options = array_filter([
            'customer_num' => $arguments['customer_num'] ?? null,
            'product_code' => $arguments['product_code'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $slice = $this->insightData->insightDataSlice($organization, $user, $type, $lookback, $options);
        if (! empty($slice['error'])) {
            return $slice;
        }

        return array_merge($slice, [
            'insight_type' => $type,
            'insight_label' => AiInsightCatalog::label($type),
            'organization_id' => (int) $organization->id,
            'currency' => 'KES',
            'screens' => [
                ['label' => 'AI Insights', 'path' => '/reports'],
            ],
        ]);
    }
}
