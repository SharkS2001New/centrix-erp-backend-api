<?php

namespace App\Services\Fulfillment;

use App\Models\DispatchTrip;
use App\Models\Driver;
use App\Models\PaymentMethod;
use App\Models\PodRecord;
use App\Models\Sale;
use App\Models\CustomerReturn;
use App\Models\Organization;
use App\Models\SalePayment;
use App\Models\User;
use App\Http\Controllers\Api\V1\Operations\OrderWorkflowController;
use App\Services\Accounting\CustomerInvoiceService;
use App\Services\Accounting\CustomerPaymentJournalService;
use App\Services\Erp\ErpContext;
use App\Services\Erp\OrderWorkflowService;
use App\Services\Erp\SalePaymentColumnMapper;
use App\Services\Notifications\CustomerNotificationService;
use App\Services\Sales\CustomerReturnService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MobileDriverService
{
    public function __construct(
        protected ErpContext $erp,
        protected PodService $podService,
        protected TripFinancialSummaryService $financials,
        protected DispatchTripService $dispatchTrips,
        protected CustomerReturnService $customerReturns,
    ) {}

    public function resolveDriver(User $user): ?Driver
    {
        return Driver::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();
    }

    public function requireDriver(User $user): Driver
    {
        $driver = $this->resolveDriver($user);
        if (! $driver) {
            throw new InvalidArgumentException(
                'No active driver profile is linked to your account. Ask your administrator to link your user to a driver record.',
            );
        }

        return $driver;
    }

    /** @return array<string, mixed> */
    public function todayTrips(User $user): array
    {
        $driver = $this->requireDriver($user);
        $today = now()->toDateString();

        $trips = DispatchTrip::query()
            ->with(['route', 'routes', 'driver', 'vehicle'])
            ->withCount('sales')
            ->where('driver_id', $driver->id)
            ->whereDate('scheduled_date', $today)
            ->whereNotIn('status', ['cancelled'])
            ->orderByRaw("FIELD(status, 'in_transit', 'loading', 'draft', 'completed')")
            ->orderBy('id')
            ->get();

        $summaries = $this->financials->summarizeForTripIds(
            $trips->pluck('id')->map(fn ($id) => (int) $id)->all(),
        );

        return [
            'driver' => $this->presentDriver($driver),
            'scheduled_date' => $today,
            'trips' => $trips->map(
                fn (DispatchTrip $trip) => $this->presentTripSummary(
                    $trip,
                    $summaries[(int) $trip->id] ?? null,
                ),
            )->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function showTrip(User $user, int $tripId): array
    {
        $driver = $this->requireDriver($user);
        $trip = $this->findDriverTrip($driver, $tripId);
        $trip->load(['route', 'routes', 'driver', 'vehicle']);
        $trip->loadCount('sales');

        $summary = $this->financials->summarizeForTrip($trip->load('sales'));
        $stops = $this->buildStopsPayload($trip);

        return [
            'trip' => $this->presentTripSummary($trip, $summary),
            'stops' => $stops,
            'stop_counts' => [
                'total' => count($stops),
                'delivered' => collect($stops)->where('is_delivered', true)->count(),
                'pending' => collect($stops)->where('is_delivered', false)->count(),
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function tripStops(User $user, int $tripId): array
    {
        $driver = $this->requireDriver($user);
        $trip = $this->findDriverTrip($driver, $tripId);

        return $this->buildStopsPayload($trip);
    }

    /** @return array<string, mixed> */
    public function showStop(User $user, int $saleId): array
    {
        $driver = $this->requireDriver($user);
        $sale = $this->findDriverStop($driver, $saleId);
        $sale->load(['items.product', 'customer']);

        return $this->presentStop($sale, includeLines: true);
    }

    /**
     * Capture POD (when provided) and mark the stop delivered.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function deliverStop(User $user, int $saleId, array $data, ?UploadedFile $photo = null): array
    {
        $driver = $this->requireDriver($user);
        $sale = $this->findDriverStop($driver, $saleId);
        $sale->loadMissing(['items.product', 'customer']);
        $tripId = (int) (($sale->fulfillment_meta ?? [])['trip_id'] ?? 0);
        $outcome = $this->normalizeDeliveryOutcome($data['delivery_outcome'] ?? null);
        $linePayloads = $this->normalizeDeliveryLines($sale, $data['lines'] ?? [], $outcome);

        $recipient = trim((string) ($data['recipient_name'] ?? ''));
        $gate = $this->erp->gateForUser($user);
        $distributionSettings = $gate->distributionSettings();
        $requirePod = ! empty($distributionSettings['require_pod_on_delivered']);

        if ($outcome !== 'failed' && $recipient === '') {
            throw new InvalidArgumentException('Enter who received the delivery.');
        }

        if ($requirePod && $outcome !== 'failed' && ! $photo) {
            throw new InvalidArgumentException(
                'Invoice/photo proof is required before marking this order as delivered.',
            );
        }

        if ($outcome !== 'failed') {
            $this->collectDeliveryPayment($user, $sale, $data, $distributionSettings);
        }

        if ($recipient !== '' || $photo !== null || $linePayloads !== []) {
            $this->podService->capture($user, $sale, array_merge($data, [
                'photo' => $photo,
                'trip_id' => $tripId > 0 ? $tripId : null,
                'recipient_name' => $recipient !== '' ? $recipient : 'Customer',
                'status' => $this->podStatusForOutcome($outcome),
                'lines' => $linePayloads,
            ]));
            $sale = $sale->fresh();
        } elseif ($requirePod && ! $this->podService->hasPod($sale)) {
            throw new InvalidArgumentException(
                'Proof of delivery is required before marking this order as delivered.',
            );
        }

        $return = $this->recordDriverReturns($user, $sale->fresh(['items.product', 'customer']), $linePayloads, $data);
        $meta = array_merge($sale->fulfillment_meta ?? [], [
            'driver_id' => $driver->id,
            'trip_id' => $tripId > 0 ? $tripId : null,
            'driver_delivery_outcome' => $outcome,
            'driver_delivery_reason' => $data['failure_reason'] ?? $data['return_reason'] ?? null,
            'driver_delivery_recorded_at' => now()->toIso8601String(),
            'pod_captured' => $this->podService->hasPod($sale->fresh()),
        ]);

        if (in_array($outcome, ['partial', 'failed'], true) && ! $return) {
            throw new InvalidArgumentException(
                'A return record is required for partial or failed delivery.',
            );
        }

        if ($return) {
            $meta['driver_return_id'] = (int) $return->id;
            $meta['driver_return_no'] = $return->return_no;
        }

        if ($outcome === 'failed') {
            $sale->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'fulfillment_meta' => $meta,
            ]);
            $sale = $sale->fresh(['items.product', 'customer']);
        } elseif ((string) $sale->status !== 'delivered') {
            app(OrderWorkflowController::class)->transitionSaleForUser(
                $sale,
                'delivered',
                $user,
                $meta,
            );
            $sale = $sale->fresh(['items.product', 'customer']);
        } else {
            $sale->update(['fulfillment_meta' => $meta]);
        }

        return $this->presentStop($sale->load(['items.product', 'customer']), includeLines: true);
    }

    /** @return array<string, mixed> */
    public function settleTrip(User $user, int $tripId, array $data): array
    {
        $driver = $this->requireDriver($user);
        $trip = $this->findDriverTrip($driver, $tripId);
        $trip = $this->dispatchTrips->settleTrip($trip, $user, $data);
        $summary = $this->financials->summarizeForTrip($trip->load('sales'));

        return [
            'trip' => $this->presentTripSummary($trip, $summary),
            'message' => 'Trip cash settlement recorded.',
        ];
    }

    protected function normalizeDeliveryOutcome(mixed $value): string
    {
        $outcome = strtolower(trim((string) ($value ?: 'complete')));

        return in_array($outcome, ['complete', 'partial', 'failed'], true)
            ? $outcome
            : 'complete';
    }

    protected function podStatusForOutcome(string $outcome): string
    {
        return match ($outcome) {
            'partial' => 'partial',
            'failed' => 'refused',
            default => 'complete',
        };
    }

    /**
     * @param  array<int, array<string, mixed>>|mixed  $lines
     * @return list<array<string, mixed>>
     */
    protected function normalizeDeliveryLines(Sale $sale, mixed $lines, string $outcome): array
    {
        $sale->loadMissing('items.product');
        $input = is_array($lines) ? collect($lines) : collect();
        $payloads = [];

        foreach ($sale->items as $item) {
            $lineData = $input->first(function ($line) use ($item) {
                if (! is_array($line)) {
                    return false;
                }
                if (! empty($line['sale_item_id']) && (int) $line['sale_item_id'] === (int) $item->id) {
                    return true;
                }

                return (string) ($line['product_code'] ?? '') === (string) $item->product_code;
            }) ?? [];

            $qtyOrdered = max(0, (float) $item->quantity);
            $qtyDelivered = match ($outcome) {
                'failed' => 0.0,
                'partial' => array_key_exists('qty_delivered', $lineData)
                    ? max(0, (float) $lineData['qty_delivered'])
                    : $qtyOrdered,
                default => $qtyOrdered,
            };
            $qtyDelivered = min($qtyDelivered, $qtyOrdered);

            $qtyRefused = array_key_exists('qty_refused', $lineData)
                ? max(0, (float) $lineData['qty_refused'])
                : max(0, $qtyOrdered - $qtyDelivered);
            $qtyRefused = min($qtyRefused, $qtyOrdered);

            $payloads[] = [
                'sale_item_id' => (int) $item->id,
                'product_code' => (string) $item->product_code,
                'product_name' => $item->product?->product_name ?? $item->product_code,
                'qty_ordered' => $qtyOrdered,
                'qty_delivered' => $qtyDelivered,
                'qty_refused' => $qtyRefused,
                'reason' => $lineData['reason'] ?? null,
                'unit_price' => (float) ($item->unit_price ?? $item->selling_price ?? 0),
                'amount' => (float) $item->amount,
            ];
        }

        return $payloads;
    }

    /** @param list<array<string, mixed>> $linePayloads */
    protected function recordDriverReturns(
        User $user,
        Sale $sale,
        array $linePayloads,
        array $data,
    ): ?CustomerReturn {
        $returnLines = collect($linePayloads)
            ->filter(fn ($line) => (float) ($line['qty_refused'] ?? 0) > 0)
            ->map(function ($line) {
                $returnQty = (float) $line['qty_refused'];
                $unitPrice = (float) ($line['unit_price'] ?? 0);

                return [
                    'sale_item_id' => (int) $line['sale_item_id'],
                    'product_code' => (string) $line['product_code'],
                    'product_name' => (string) ($line['product_name'] ?? $line['product_code']),
                    'return_qty' => $returnQty,
                    'unit_price' => $unitPrice,
                    'amount' => round($returnQty * $unitPrice, 2),
                    'reason' => $line['reason'] ?? null,
                ];
            })
            ->values()
            ->all();

        if ($returnLines === []) {
            return null;
        }

        $reason = trim((string) ($data['return_reason'] ?? $data['failure_reason'] ?? 'Driver delivery return'));

        return $this->customerReturns->create($user, [
            'sale_id' => (int) $sale->id,
            'branch_id' => (int) $sale->branch_id,
            'customer_num' => $sale->customer_num,
            'return_date' => now()->toDateString(),
            'refund_method' => 'CREDIT_NOTE',
            'reason' => $reason !== '' ? $reason : 'Driver delivery return',
            'notes' => $data['notes'] ?? null,
            'lines' => $returnLines,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $distributionSettings
     */
    protected function collectDeliveryPayment(
        User $user,
        Sale $sale,
        array $data,
        array $distributionSettings,
    ): void {
        if (empty($distributionSettings['enable_cod_reconciliation'])) {
            return;
        }

        $amount = round((float) ($data['collect_amount'] ?? 0), 2);
        if ($amount <= 0) {
            return;
        }

        $balanceDue = max(0, round((float) $sale->order_total - (float) $sale->amount_paid, 2));
        if ($balanceDue <= 0) {
            return;
        }

        if ($amount > $balanceDue + 0.01) {
            throw new InvalidArgumentException(
                "Collection amount ({$amount}) exceeds balance due ({$balanceDue}).",
            );
        }

        $code = strtoupper(trim((string) ($data['payment_method_code'] ?? 'CASH')));
        $method = PaymentMethod::query()
            ->where('organization_id', $user->organization_id)
            ->where('method_code', $code)
            ->where('is_active', true)
            ->first();

        if (! $method) {
            throw new InvalidArgumentException("Payment method {$code} is not available.");
        }

        DB::transaction(function () use ($sale, $user, $amount, $method, $data, $code) {
            SalePayment::create([
                'sale_id' => $sale->id,
                'payment_method_id' => $method->id,
                'amount' => $amount,
                'reference_number' => $data['payment_reference'] ?? null,
            ]);

            $newPaid = round((float) $sale->amount_paid + $amount, 2);
            $paymentStatus = $newPaid + 0.01 >= (float) $sale->order_total ? 'paid' : 'partial';

            $gate = $this->erp->gateForUser($user);
            $workflow = OrderWorkflowService::forGate($gate);
            $salesSettings = $gate->moduleSettings('sales');

            $orderStatus = $workflow->resolveStatusAfterPayment(
                (string) $sale->channel,
                (string) $sale->status,
                $newPaid,
                (float) $sale->order_total,
                (bool) $sale->is_credit_sale,
                $code,
                ! empty($salesSettings['allow_credit_pay_now']),
            );

            $updates = [
                'amount_paid' => $newPaid,
                'payment_status' => $paymentStatus,
            ];

            if ($sale->status !== 'cancelled' && $sale->status !== 'held') {
                $updates['status'] = $orderStatus;
            }

            $sale->update($updates);
            SalePaymentColumnMapper::applyToSale($sale->fresh(), $code, $amount);

            if ($sale->customer_num) {
                $invoice = app(CustomerInvoiceService::class)->ensureForSale(
                    $sale->fresh(),
                    $user,
                    (float) $sale->order_total,
                    $newPaid,
                );
                if ($invoice) {
                    \App\Models\CustomerInvoicePayment::create([
                        'customer_invoice_id' => $invoice->id,
                        'customer_num' => $sale->customer_num,
                        'payment_method_id' => $method->id,
                        'amount_paid' => $amount,
                        'date_paid' => now()->toDateString(),
                        'received_by' => $user->id,
                        'organization_id' => $sale->organization_id,
                        'reference_number' => $data['payment_reference'] ?? null,
                    ]);
                }
            }

            app(CustomerPaymentJournalService::class)->postIfEnabled(
                $sale->fresh(),
                $user,
                $gate,
                $amount,
                (int) $method->id,
            );
        });

        $sale->refresh();
        $organization = Organization::query()->find((int) $sale->organization_id);
        if ($organization) {
            app(CustomerNotificationService::class)->notifyDebtorPayment($sale, $organization, $amount);
        }
    }

    protected function findDriverTrip(Driver $driver, int $tripId): DispatchTrip
    {
        $trip = DispatchTrip::query()
            ->where('id', $tripId)
            ->where('driver_id', $driver->id)
            ->first();

        if (! $trip) {
            throw new InvalidArgumentException('Trip not found on your schedule.');
        }

        return $trip;
    }

    protected function findDriverStop(Driver $driver, int $saleId): Sale
    {
        $sale = Sale::query()
            ->where('id', $saleId)
            ->whereHas('dispatchTrips', fn ($q) => $q->where('dispatch_trips.driver_id', $driver->id))
            ->first();

        if (! $sale) {
            throw new InvalidArgumentException('Delivery stop not found on your trips.');
        }

        return $sale;
    }

    /** @return list<array<string, mixed>> */
    protected function buildStopsPayload(DispatchTrip $trip): array
    {
        $trip->load([
            'sales' => fn ($q) => $q->with(['customer'])->orderBy('dispatch_trip_sales.stop_seq'),
        ]);

        return $trip->sales
            ->map(fn (Sale $sale) => $this->presentStop($sale))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    protected function presentDriver(Driver $driver): array
    {
        return [
            'id' => (int) $driver->id,
            'driver_code' => $driver->driver_code,
            'full_name' => $driver->full_name,
            'phone' => $driver->phone,
        ];
    }

    /** @return array<string, mixed> */
    protected function presentTripSummary(DispatchTrip $trip, ?array $financialSummary = null): array
    {
        $routeNames = $trip->relationLoaded('routes') && $trip->routes->isNotEmpty()
            ? $trip->routes->pluck('route_name')->values()->all()
            : ($trip->route ? [$trip->route->route_name] : []);

        return [
            'id' => (int) $trip->id,
            'trip_code' => $trip->trip_code,
            'status' => $trip->status,
            'scheduled_date' => $trip->scheduled_date?->toDateString(),
            'route_names' => $routeNames,
            'vehicle' => $trip->vehicle ? [
                'id' => (int) $trip->vehicle->id,
                'label' => $trip->vehicle->plate_number
                    ?: $trip->vehicle->vehicle_name
                    ?: $trip->vehicle->vehicle_code,
            ] : null,
            'sales_count' => (int) ($trip->sales_count ?? $trip->sales()->count()),
            'departed_at' => $trip->departed_at?->toIso8601String(),
            'expected_cash' => $trip->expected_cash !== null ? (float) $trip->expected_cash : null,
            'collected_cash' => $trip->collected_cash !== null ? (float) $trip->collected_cash : null,
            'cash_variance' => $trip->cash_variance !== null ? (float) $trip->cash_variance : null,
            'cash_settled' => $trip->settled_at !== null,
            'financial_summary' => $financialSummary ?? $this->financials->emptySummary(),
        ];
    }

    /** @return array<string, mixed> */
    protected function presentStop(Sale $sale, bool $includeLines = false): array
    {
        $customer = $sale->customer;
        $meta = $sale->fulfillment_meta ?? [];
        $balanceDue = max(0, (float) $sale->order_total - (float) $sale->amount_paid);
        $isDelivered = in_array((string) $sale->status, ['delivered', 'completed'], true);

        $payload = [
            'sale_id' => (int) $sale->id,
            'order_num' => $sale->order_num,
            'status' => (string) $sale->status,
            'is_delivered' => $isDelivered,
            'stop_seq' => (int) ($sale->pivot->stop_seq ?? $meta['stop_seq'] ?? 0),
            'customer_num' => $sale->customer_num,
            'customer_name' => $sale->customer_name_override ?: ($customer?->customer_name ?? ''),
            'phone_number' => $customer?->phone_number,
            'town' => $customer?->town,
            'latitude' => $customer?->latitude,
            'longitude' => $customer?->longitude,
            'has_location' => $customer?->has_location ?? (
                $customer?->latitude !== null && $customer?->longitude !== null
            ),
            'order_total' => (float) $sale->order_total,
            'amount_paid' => (float) $sale->amount_paid,
            'balance_due' => round($balanceDue, 2),
            'pod_captured' => ! empty($meta['pod_captured']) || PodRecord::query()->where('sale_id', $sale->id)->exists(),
            'trip_id' => isset($meta['trip_id']) ? (int) $meta['trip_id'] : null,
            'delivery_outcome' => $meta['driver_delivery_outcome'] ?? ($isDelivered ? 'complete' : null),
            'delivery_reason' => $meta['driver_delivery_reason'] ?? null,
            'return_no' => $meta['driver_return_no'] ?? null,
        ];

        if ($includeLines) {
            $payload['lines'] = $sale->items->map(function ($item) {
                $productName = trim((string) ($item->product?->product_name ?? ''));
                if ($productName === '') {
                    $productName = (string) $item->product_code;
                }

                return [
                    'sale_item_id' => (int) $item->id,
                    'product_code' => $item->product_code,
                    'product_name' => $productName,
                    'uom' => $item->uom,
                    'quantity' => (float) $item->quantity,
                    'unit_price' => (float) ($item->unit_price ?? $item->selling_price ?? 0),
                    'amount' => (float) $item->amount,
                ];
            })->values()->all();
        }

        return $payload;
    }
}
