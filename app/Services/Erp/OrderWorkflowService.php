<?php

namespace App\Services\Erp;

class OrderWorkflowService
{
    /** @var list<string> */
    public const ALL_STATUSES = [
        'draft',
        'held',
        'booked',
        'pending',
        'unpaid',
        'pending_payment',
        'paid',
        'processed',
        'delivered',
        'completed',
        'cancelled',
    ];

    public function __construct(protected CapabilityGate $gate) {}

    public static function forGate(CapabilityGate $gate): self
    {
        return new self($gate);
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        $defaults = config('erp.default_order_workflow', []);
        $sales = $this->gate->moduleSettings('sales');
        $custom = is_array($sales['order_workflow'] ?? null) ? $sales['order_workflow'] : [];

        return $this->normalizeConfig(array_replace_recursive($defaults, $custom));
    }

    /** @return array<string, mixed> */
    public function forChannel(string $channel): array
    {
        $config = $this->config();
        $channelConfig = config("erp.workflows.{$channel}", []);
        $channelStatuses = $channelConfig['statuses'] ?? self::ALL_STATUSES;
        $enabled = $this->enabledStatuses($config);
        $statuses = array_values(array_unique(array_merge(
            array_intersect($channelStatuses, $enabled),
            array_intersect(['draft', 'held', 'cancelled'], $channelStatuses),
        )));

        $pipeline = $this->pipelineSteps($config, $statuses);
        $labels = $this->statusLabels($config);
        $transitions = $this->transitions($config, $statuses);

        return [
            'statuses' => $statuses,
            'labels' => $labels,
            'pipeline' => $pipeline,
            'transitions' => $transitions,
            'save_status' => $this->saveStatusForChannel($channel, $config, $statuses),
            'checkout' => [
                'full_paid' => $this->checkoutStatusForChannel($channel, $config, 'full_paid', $statuses),
                'partial' => $this->pickStatus($config['checkout']['partial'] ?? 'pending_payment', $statuses),
                'unpaid' => $this->checkoutStatusForChannel($channel, $config, 'unpaid', $statuses),
            ],
            'deduct_stock_on' => $this->pickStatus(
                $config['deduct_stock_on'] ?? 'completed',
                $statuses,
                'completed',
            ),
            'pay_on_complete' => (bool) ($channelConfig['pay_on_complete'] ?? false),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function workflowsByChannel(): array
    {
        $out = [];
        foreach ($this->gate->allowedChannels() as $channel) {
            $out[$channel] = $this->forChannel($channel);
        }

        return $out;
    }

    /** @return list<string> */
    public function allowedTransitions(string $from, ?string $channel = null): array
    {
        if ($channel) {
            $workflow = $this->forChannel($channel);
            $transitions = $workflow['transitions'][$from] ?? [];
            if ($transitions !== []) {
                return $transitions;
            }

            $pipeline = array_column($workflow['pipeline'], 'key');
            if (! in_array($from, $pipeline, true) && in_array($from, ['held', 'draft'], true)) {
                return $pipeline !== [] ? [$pipeline[0]] : [];
            }

            return [];
        }

        $config = $this->config();
        $statuses = $this->enabledStatuses($config);

        return $this->transitions($config, $statuses)[$from] ?? [];
    }

    public function canTransition(string $from, string $to, ?string $channel = null): bool
    {
        if ($to === 'cancelled') {
            return $from !== 'cancelled' && $from !== 'completed';
        }

        return in_array($to, $this->allowedTransitions($from, $channel), true);
    }

    public function isImmediatePaymentMethod(string $paymentMethodCode, bool $isCredit = false): bool
    {
        if ($isCredit) {
            return false;
        }

        $code = strtoupper($paymentMethodCode);
        if ($code === '' || str_contains($code, 'CREDIT')) {
            return false;
        }

        foreach (['CASH', 'MPESA', 'M-PESA', 'EQUITY', 'KCB', 'BANK', 'CHEQUE', 'OTHER'] as $token) {
            if (str_contains($code, $token)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $workflow */
    public function pickEnabledStatus(string $preferred, array $workflow): string
    {
        $enabled = $workflow['statuses'] ?? [];
        $fallbacks = [
            'completed' => ['completed', 'delivered', 'paid', 'processed'],
            'delivered' => ['delivered', 'completed', 'processed', 'paid'],
            'paid' => ['paid', 'completed', 'delivered', 'processed'],
            'processed' => ['processed', 'delivered', 'paid', 'completed'],
            'pending_payment' => ['pending_payment', 'unpaid', 'pending'],
            'unpaid' => ['unpaid', 'pending', 'booked'],
            'pending' => ['pending', 'booked', 'unpaid'],
            'booked' => ['booked', 'pending', 'unpaid'],
        ];

        foreach ($fallbacks[$preferred] ?? [$preferred] as $status) {
            if (in_array($status, $enabled, true)) {
                return $status;
            }
        }

        $pipeline = $workflow['pipeline'] ?? [];
        if ($pipeline !== []) {
            return (string) ($pipeline[array_key_last($pipeline)]['key'] ?? $preferred);
        }

        return $preferred;
    }

    public function resolveCheckoutStatus(
        string $channel,
        bool $isCredit,
        float $payNow,
        float $total,
        string $paymentMethodCode = 'CASH',
        bool $allowPartialPayment = false,
    ): string {
        $workflow = $this->forChannel($channel);
        $checkout = $workflow['checkout'];
        $fullyPaid = $payNow + 0.01 >= $total && $total > 0;
        $partialPay = $payNow > 0.01 && ! $fullyPaid;

        if ($fullyPaid) {
            if ($isCredit) {
                return $this->pickEnabledStatus((string) ($checkout['full_paid'] ?? 'paid'), $workflow);
            }
            if ($this->isImmediatePaymentMethod($paymentMethodCode, $isCredit) && $channel === 'pos') {
                return $this->pickEnabledStatus('completed', $workflow);
            }

            return $this->pickEnabledStatus((string) ($checkout['full_paid'] ?? 'paid'), $workflow);
        }

        if ($partialPay) {
            return $this->pickEnabledStatus((string) ($checkout['partial'] ?? 'pending_payment'), $workflow);
        }

        if ($isCredit || $allowPartialPayment) {
            return $this->pickEnabledStatus((string) ($checkout['unpaid'] ?? 'unpaid'), $workflow);
        }

        return $this->pickEnabledStatus((string) ($checkout['unpaid'] ?? 'unpaid'), $workflow);
    }

    public function resolveSaveStatus(string $channel, bool $hold = false): string
    {
        if ($hold) {
            return 'held';
        }

        $workflow = $this->forChannel($channel);

        return $this->pickEnabledStatus((string) ($workflow['save_status'] ?? 'unpaid'), $workflow);
    }

    public function shouldDeductStockOn(string $status, ?string $channel = null): bool
    {
        $target = $channel
            ? ($this->forChannel($channel)['deduct_stock_on'] ?? 'completed')
            : ($this->config()['deduct_stock_on'] ?? 'completed');

        return $status === $target;
    }

    public function isAllowedStatus(string $status, string $channel): bool
    {
        return in_array($status, $this->forChannel($channel)['statuses'] ?? [], true);
    }

    /** @param array<string, mixed> $config */
    public function normalize(array $config): array
    {
        return $this->normalizeConfig($config);
    }

    /** @param array<string, mixed> $config */
    protected function normalizeConfig(array $config): array
    {
        $defaults = config('erp.default_order_workflow', []);
        $steps = $config['steps'] ?? $defaults['steps'] ?? [];
        if (! is_array($steps)) {
            $steps = [];
        }

        $normalizedSteps = [];
        $seen = [];
        foreach ($steps as $step) {
            if (! is_array($step) || empty($step['status'])) {
                continue;
            }
            $status = (string) $step['status'];
            if (! in_array($status, self::ALL_STATUSES, true) || isset($seen[$status])) {
                continue;
            }
            $seen[$status] = true;
            $normalizedSteps[] = [
                'status' => $status,
                'label' => trim((string) ($step['label'] ?? $status)) ?: $status,
                'enabled' => array_key_exists('enabled', $step) ? (bool) $step['enabled'] : true,
            ];
        }

        $config['steps'] = $normalizedSteps !== []
            ? $normalizedSteps
            : $this->normalizeDefaultSteps($defaults['steps'] ?? []);
        unset($config['transitions']);
        $config['save_status'] = is_array($config['save_status'] ?? null)
            ? $config['save_status']
            : ($defaults['save_status'] ?? []);
        $config['checkout'] = is_array($config['checkout'] ?? null)
            ? $config['checkout']
            : ($defaults['checkout'] ?? []);
        $config['deduct_stock_on'] = (string) ($config['deduct_stock_on'] ?? $defaults['deduct_stock_on'] ?? 'completed');

        return $config;
    }

    /** @param list<array<string, mixed>> $defaults */
    /** @return list<array{status: string, label: string, enabled: bool}> */
    protected function normalizeDefaultSteps(array $defaults): array
    {
        $out = [];
        foreach ($defaults as $step) {
            if (! is_array($step) || empty($step['status'])) {
                continue;
            }
            $status = (string) $step['status'];
            if (! in_array($status, self::ALL_STATUSES, true)) {
                continue;
            }
            $out[] = [
                'status' => $status,
                'label' => trim((string) ($step['label'] ?? $status)) ?: $status,
                'enabled' => (bool) ($step['enabled'] ?? true),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, list<string>> $transitions
     * @param list<array{status: string, label: string, enabled: bool}> $steps
     * @return array<string, list<string>>
     */
    protected function pruneTransitions(array $transitions, array $steps): array
    {
        $present = [];
        foreach ($steps as $step) {
            $present[$step['status']] = true;
        }

        $out = [];
        foreach ($transitions as $from => $targets) {
            if (! is_string($from) || ! isset($present[$from]) || ! is_array($targets)) {
                continue;
            }
            $filtered = array_values(array_filter(
                $targets,
                fn ($to) => is_string($to) && isset($present[$to]),
            ));
            if ($filtered !== []) {
                $out[$from] = $filtered;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $config */
    /** @return list<string> */
    protected function enabledStatuses(array $config): array
    {
        $enabled = [];
        foreach ($config['steps'] as $step) {
            if (($step['enabled'] ?? true) && in_array($step['status'], self::ALL_STATUSES, true)) {
                $enabled[] = $step['status'];
            }
        }

        return $enabled ?: ['booked', 'pending', 'unpaid', 'pending_payment', 'paid', 'processed', 'delivered', 'completed'];
    }

    /** @param array<string, mixed> $config */
    /** @param list<string> $allowedStatuses */
    /** @return list<array{key: string, label: string}> */
    protected function pipelineSteps(array $config, array $allowedStatuses): array
    {
        $out = [];
        foreach ($config['steps'] as $step) {
            if (! ($step['enabled'] ?? true)) {
                continue;
            }
            $status = $step['status'];
            if (! in_array($status, $allowedStatuses, true)) {
                continue;
            }
            $out[] = [
                'key' => $status,
                'label' => $step['label'] ?? $status,
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $config */
    /** @return array<string, string> */
    protected function statusLabels(array $config): array
    {
        $labels = config('erp.order_status_labels', []);
        foreach ($config['steps'] as $step) {
            $labels[$step['status']] = $step['label'] ?? $step['status'];
        }

        return $labels;
    }

    /** @param array<string, mixed> $config */
    /** @param list<string> $allowedStatuses */
    /** @return array<string, list<string>> */
    protected function transitions(array $config, array $allowedStatuses): array
    {
        $pipeline = $this->pipelineSteps($config, $allowedStatuses);
        $keys = array_map(fn (array $step) => $step['key'], $pipeline);
        $out = [];

        foreach ($keys as $index => $from) {
            $targets = [];
            if ($index > 0) {
                $targets[] = $keys[$index - 1];
            }
            if ($index < count($keys) - 1) {
                $targets[] = $keys[$index + 1];
            }
            if ($targets !== []) {
                $out[$from] = $targets;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $config */
    /** @param list<string> $allowedStatuses */
    protected function saveStatusForChannel(string $channel, array $config, array $allowedStatuses): string
    {
        $map = $config['save_status'] ?? [];
        $configured = is_array($map) ? ($map[$channel] ?? $map['default'] ?? null) : null;
        $status = $configured ?? $this->firstPipelineStatus($config) ?? 'unpaid';

        return $this->pickStatus((string) $status, $allowedStatuses, 'unpaid');
    }

    /** @param array<string, mixed> $config */
    protected function firstPipelineStatus(array $config): ?string
    {
        foreach ($config['steps'] as $step) {
            if (($step['enabled'] ?? true) && in_array($step['status'], self::ALL_STATUSES, true)) {
                return (string) $step['status'];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $config */
    /** @param list<string> $allowedStatuses */
    protected function checkoutStatusForChannel(
        string $channel,
        array $config,
        string $key,
        array $allowedStatuses,
    ): string {
        $map = $config['checkout'][$key] ?? [];
        $status = is_array($map)
            ? ($map[$channel] ?? $map['default'] ?? 'booked')
            : (string) $map;

        $fallback = match ($key) {
            'full_paid' => 'paid',
            'unpaid' => 'unpaid',
            default => 'booked',
        };

        return $this->pickStatus((string) $status, $allowedStatuses, $fallback);
    }

    /** @param list<string> $allowedStatuses */
    protected function pickStatus(string $status, array $allowedStatuses, string $fallback = 'booked'): string
    {
        if (in_array($status, $allowedStatuses, true)) {
            return $status;
        }

        if (in_array($fallback, $allowedStatuses, true)) {
            return $fallback;
        }

        return $allowedStatuses[0] ?? $fallback;
    }
}
