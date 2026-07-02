<?php

namespace App\Services\Erp;

class OrderWorkflowService
{
    /** Statuses staff may cancel via workflow transition (before partial/full payment). */
    public const CANCELLABLE_ORDER_STATUSES = ['booked', 'pending', 'unpaid'];

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
        'expired',
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

        return $this->normalizeConfig($this->mergeWorkflowConfig($defaults, $custom));
    }

    /**
     * Merge saved workflow over defaults.
     * Steps are replaced wholesale — array_replace_recursive would keep trailing default stages.
     *
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $custom
     * @return array<string, mixed>
     */
    public function mergeWorkflowConfig(array $defaults, array $custom): array
    {
        $customWithoutSteps = $custom;
        unset($customWithoutSteps['steps']);
        $merged = array_replace_recursive($defaults, $customWithoutSteps);
        if (array_key_exists('steps', $custom) && is_array($custom['steps'])) {
            $merged['steps'] = array_values($custom['steps']);
        }

        return $merged;
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
            array_intersect(['draft', 'held', 'cancelled', 'expired'], $channelStatuses),
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
            'deduct_stock_on' => $this->channelStatusForChannel(
                $config,
                'deduct_stock_on',
                $channel,
                $statuses,
                'completed',
            ),
            'reserve_stock_on' => $this->channelStatusForChannel(
                $config,
                'reserve_stock_on',
                $channel,
                $statuses,
                'unpaid',
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

    public function orderCancellationEnabled(): bool
    {
        $sales = $this->gate->moduleSettings('sales');

        return ($sales['order_cancellation_enabled'] ?? true) !== false;
    }

    public function isCancellableStatus(string $status, ?string $channel = null): bool
    {
        if (in_array($status, ['cancelled', 'expired', 'held', 'draft'], true)) {
            return false;
        }

        $aligned = $this->alignStatusToPipeline($status, $channel);

        return in_array($aligned, self::CANCELLABLE_ORDER_STATUSES, true);
    }

    public function canTransition(string $from, string $to, ?string $channel = null): bool
    {
        if ($to === 'cancelled') {
            return $this->orderCancellationEnabled() && $this->isCancellableStatus($from, $channel);
        }

        if ($to === 'expired') {
            return ! in_array($from, ['cancelled', 'expired'], true)
                && ! $this->isTerminalStatus($from, $channel);
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

    public function lastPipelineStatus(?string $channel = null): ?string
    {
        if ($channel) {
            $pipeline = $this->forChannel($channel)['pipeline'] ?? [];
        } else {
            $config = $this->config();
            $pipeline = $this->pipelineSteps($config, $this->enabledStatuses($config));
        }

        if ($pipeline === []) {
            return null;
        }

        $last = (string) ($pipeline[array_key_last($pipeline)]['key'] ?? '');

        return $last !== '' ? $last : null;
    }

    /**
     * Map stored statuses outside the org pipeline (e.g. completed) to the nearest enabled step.
     */
    public function alignStatusToPipeline(string $status, ?string $channel = null): string
    {
        if (in_array($status, ['cancelled', 'held', 'draft'], true)) {
            return $status;
        }

        $workflow = $channel ? $this->forChannel($channel) : $this->config();
        $pipelineKeys = array_column($workflow['pipeline'] ?? [], 'key');

        if (in_array($status, $pipelineKeys, true)) {
            return $status;
        }

        return $this->pickEnabledStatus($status, $workflow);
    }

    /** @return list<string> */
    public function statusesForQueueFilter(string $queueStatus, ?string $channel = null): array
    {
        if ($queueStatus === '' || $queueStatus === 'all') {
            return [];
        }

        if ($queueStatus === 'cancelled') {
            return ['cancelled'];
        }

        if ($queueStatus === 'expired') {
            return ['expired'];
        }

        $matches = [$queueStatus];
        foreach (['completed', 'delivered', 'processed', 'booked', 'pending', 'draft', 'held'] as $alias) {
            if ($this->alignStatusToPipeline($alias, $channel) === $queueStatus) {
                $matches[] = $alias;
            }
        }

        return array_values(array_unique($matches));
    }

    public function isTerminalStatus(string $status, ?string $channel = null): bool
    {
        if (in_array($status, ['cancelled', 'expired', 'held', 'draft'], true)) {
            return false;
        }

        $last = $this->lastPipelineStatus($channel);
        if ($last === null) {
            return false;
        }

        return $this->alignStatusToPipeline($status, $channel) === $last;
    }

    public function normalizeSalesChannel(string $channel): string
    {
        return match (strtolower($channel)) {
            'backoffice' => 'backend',
            default => strtolower($channel),
        };
    }

    /**
     * Statuses stored on sales after a fully-paid checkout for this channel (org workflow).
     *
     * @return list<string>
     */
    public function checkoutCompleteStatuses(?string $channel = null): array
    {
        $channel = $this->normalizeSalesChannel($channel ?? 'backend');
        $workflow = $this->forChannel($channel);
        $fullPaid = (string) ($workflow['checkout']['full_paid'] ?? 'paid');
        $terminal = $this->lastPipelineStatus($channel);
        $statuses = [$fullPaid];

        if ($terminal) {
            $statuses = array_merge($statuses, $this->statusesForQueueFilter($terminal, $channel));
        }

        return array_values(array_unique($statuses));
    }

    /**
     * Statuses an order may have to be restored back into a cart for editing.
     *
     * @return list<string>
     */
    public function restorableToCartStatuses(string $channel, bool $allowCheckoutReEdit = false): array
    {
        $channel = $this->normalizeSalesChannel($channel);
        $workflow = $this->forChannel($channel);
        $allowed = $workflow['statuses'] ?? [];
        $terminal = $this->lastPipelineStatus($channel);
        $restorable = ['held', 'draft'];

        foreach ($allowed as $status) {
            if ($status === 'cancelled') {
                continue;
            }
            if ($terminal !== null && $status === $terminal && ! $allowCheckoutReEdit) {
                continue;
            }
            $restorable[] = $status;
        }

        return array_values(array_unique($restorable));
    }

    public function isRestorableToCartStatus(
        string $status,
        string $channel,
        bool $allowCheckoutReEdit = false,
    ): bool {
        $channel = $this->normalizeSalesChannel($channel);
        $restorable = $this->restorableToCartStatuses($channel, $allowCheckoutReEdit);
        $normalized = (string) $status;

        if (in_array($normalized, $restorable, true)) {
            return true;
        }

        $aligned = $this->alignStatusToPipeline($normalized, $channel);

        return in_array($aligned, $restorable, true);
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
            : $this->channelStatusFromConfig($this->config(), 'deduct_stock_on', $channel ?? 'backend', 'completed');

        return $status === $target;
    }

    public function shouldReserveStockOn(string $status, ?string $channel = null): bool
    {
        $target = $channel
            ? ($this->forChannel($channel)['reserve_stock_on'] ?? 'unpaid')
            : $this->channelStatusFromConfig($this->config(), 'reserve_stock_on', $channel ?? 'backend', 'unpaid');

        return $status === $target;
    }

    /**
     * Whether an order at this status should hold stock in reservation (at or past reserve_stock_on).
     */
    public function shouldHaveStockReserved(string $status, ?string $channel = null): bool
    {
        if (in_array($status, ['cancelled', 'expired', 'held', 'draft'], true)) {
            return false;
        }

        $channel = $this->normalizeSalesChannel($channel ?? 'backend');
        $target = (string) (
            $this->forChannel($channel)['reserve_stock_on']
            ?? $this->channelStatusFromConfig($this->config(), 'reserve_stock_on', $channel, 'unpaid')
        );

        return $this->isAtOrPastStatus($status, $target, $channel);
    }

    /** Whether $current has reached or passed $target in the channel workflow pipeline. */
    public function isAtOrPastStatus(string $current, string $target, string $channel): bool
    {
        if ($current === $target) {
            return true;
        }

        if ($current === 'cancelled') {
            return false;
        }

        $workflow = $this->forChannel($channel);
        $enabled = array_column($workflow['pipeline'] ?? [], 'key');
        if ($enabled === []) {
            $enabled = $this->enabledStatuses($this->config());
        }
        $currentIdx = array_search($current, $enabled, true);
        $targetIdx = array_search($target, $enabled, true);

        if ($currentIdx === false || $targetIdx === false) {
            return false;
        }

        return $currentIdx >= $targetIdx;
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
        $config['deduct_stock_on'] = $this->normalizeChannelStatusMap(
            $config['deduct_stock_on'] ?? $defaults['deduct_stock_on'] ?? 'completed',
            $defaults['deduct_stock_on'] ?? 'completed',
        );
        $config['reserve_stock_on'] = $this->normalizeChannelStatusMap(
            $config['reserve_stock_on'] ?? $defaults['reserve_stock_on'] ?? 'unpaid',
            $defaults['reserve_stock_on'] ?? 'unpaid',
        );

        return $this->sanitizeWorkflowReferences($config);
    }

    /**
     * @param  string|array<string, string>|null  $value
     * @param  string|array<string, string>  $default
     * @return array<string, string>
     */
    public function normalizeChannelStatusMap(mixed $value, mixed $default): array
    {
        $channels = ['pos', 'mobile', 'backend'];
        $defaultMap = is_array($default)
            ? $default
            : ['pos' => (string) $default, 'mobile' => (string) $default, 'backend' => (string) $default];
        $legacy = is_string($value) ? $value : null;
        $map = is_array($value) ? $value : [];

        $out = [];
        foreach ($channels as $channel) {
            $out[$channel] = (string) ($map[$channel] ?? $legacy ?? $defaultMap[$channel] ?? $defaultMap['backend'] ?? 'unpaid');
        }

        return $out;
    }

    /** @param array<string, mixed> $config */
    protected function sanitizeWorkflowReferences(array $config): array
    {
        $enabled = $this->enabledStatuses($config);
        $first = $enabled[0] ?? 'unpaid';
        $last = $enabled[array_key_last($enabled)] ?? 'paid';

        $pick = function (string $status) use ($enabled, $first, $last): string {
            if (in_array($status, $enabled, true)) {
                return $status;
            }

            foreach (['completed', 'delivered', 'paid', 'processed', 'pending_payment', 'unpaid'] as $fallback) {
                if (in_array($fallback, $enabled, true)) {
                    return $fallback;
                }
            }

            return $first;
        };

        if (is_array($config['save_status'] ?? null)) {
            foreach ($config['save_status'] as $channel => $status) {
                if (is_string($status)) {
                    $config['save_status'][$channel] = $pick($status);
                }
            }
        }

        if (is_array($config['checkout'] ?? null)) {
            if (is_string($config['checkout']['partial'] ?? null)) {
                $config['checkout']['partial'] = $pick($config['checkout']['partial']);
            }
            foreach (['full_paid', 'unpaid'] as $key) {
                if (! is_array($config['checkout'][$key] ?? null)) {
                    continue;
                }
                foreach ($config['checkout'][$key] as $channel => $status) {
                    if (is_string($status)) {
                        $config['checkout'][$key][$channel] = $pick($status);
                    }
                }
            }
        }

        $config['deduct_stock_on'] = $this->sanitizeChannelStatusMap(
            $config['deduct_stock_on'] ?? [],
            $pick,
            (string) ($enabled[array_key_last($enabled)] ?? 'paid'),
        );
        $config['reserve_stock_on'] = $this->sanitizeChannelStatusMap(
            $config['reserve_stock_on'] ?? [],
            $pick,
            $first,
        );

        return $config;
    }

    /**
     * @param  array<string, string>  $map
     * @param  callable(string): string  $pick
     * @return array<string, string>
     */
    protected function sanitizeChannelStatusMap(array $map, callable $pick, string $fallback): array
    {
        $out = [];
        foreach (['pos', 'mobile', 'backend'] as $channel) {
            $status = is_string($map[$channel] ?? null) ? $map[$channel] : $fallback;
            $out[$channel] = $pick($status);
        }

        return $out;
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

        $processedIdx = array_search('processed', $keys, true);
        if ($processedIdx !== false) {
            $skipFrom = ['cancelled', 'expired', 'draft', 'held', 'processed', 'delivered', 'completed'];
            foreach ($keys as $index => $from) {
                if ($index >= $processedIdx || in_array($from, $skipFrom, true)) {
                    continue;
                }
                $out[$from] = array_values(array_unique(array_merge($out[$from] ?? [], ['processed'])));
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $config */
    protected function channelStatusForChannel(
        array $config,
        string $key,
        string $channel,
        array $allowedStatuses,
        string $fallback,
    ): string {
        return $this->pickStatus(
            $this->channelStatusFromConfig($config, $key, $channel, $fallback),
            $allowedStatuses,
            $fallback,
        );
    }

    /** @param array<string, mixed> $config */
    protected function channelStatusFromConfig(array $config, string $key, string $channel, string $fallback): string
    {
        $map = $this->normalizeChannelStatusMap($config[$key] ?? $fallback, $fallback);

        return (string) ($map[$channel] ?? $fallback);
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
