<?php

namespace App\Services\Fulfillment;

use App\Support\UploadedImageProcessor;

use App\Models\Organization;
use App\Models\PodLine;
use App\Models\PodRecord;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class PodService
{
    public function __construct(protected FulfillmentNotificationService $notifications) {}

    /** @param  array<string, mixed>  $data */
    public function capture(User $user, Sale $sale, array $data): PodRecord
    {
        $recipient = trim((string) ($data['recipient_name'] ?? $data['pod_signer_name'] ?? ''));
        if ($recipient === '') {
            throw new InvalidArgumentException('Recipient name is required for proof of delivery.');
        }

        $record = DB::transaction(function () use ($user, $sale, $data, $recipient) {
            $tripId = isset($data['trip_id'])
                ? (int) $data['trip_id']
                : (int) (($sale->fulfillment_meta ?? [])['trip_id'] ?? 0);

            $photoPath = $this->storeUpload($data['photo'] ?? null, $sale->id, 'photo');
            $signaturePath = $this->storeUpload($data['signature'] ?? null, $sale->id, 'signature');

            $items = SaleItem::query()->where('sale_id', $sale->id)->get();
            $linePayloads = [];
            $status = (string) ($data['status'] ?? '');

            foreach ($items as $item) {
                $lineData = collect($data['lines'] ?? [])->firstWhere('sale_item_id', $item->id);
                $qtyOrdered = (float) $item->quantity;
                $qtyDelivered = isset($lineData['qty_delivered'])
                    ? (float) $lineData['qty_delivered']
                    : $qtyOrdered;
                $qtyRefused = (float) ($lineData['qty_refused'] ?? max(0, $qtyOrdered - $qtyDelivered));

                $linePayloads[] = [
                    'sale_item_id' => $item->id,
                    'qty_ordered' => $qtyOrdered,
                    'qty_delivered' => $qtyDelivered,
                    'qty_refused' => $qtyRefused,
                    'reason' => $lineData['reason'] ?? null,
                ];
            }

            if (! in_array($status, ['complete', 'partial', 'refused'], true)) {
                $status = $this->resolveStatus($linePayloads);
            }

            $record = PodRecord::create([
                'branch_id' => $sale->branch_id,
                'sale_id' => $sale->id,
                'trip_id' => $tripId > 0 ? $tripId : null,
                'captured_at' => now(),
                'captured_by' => $user->id,
                'recipient_name' => $recipient,
                'notes' => $data['notes'] ?? $data['pod_notes'] ?? null,
                'signature_path' => $signaturePath ?? ($data['signature_path'] ?? null),
                'photo_path' => $photoPath ?? ($data['photo_path'] ?? null),
                'status' => $status,
                'gps_lat' => $data['gps_lat'] ?? null,
                'gps_lng' => $data['gps_lng'] ?? null,
            ]);

            foreach ($linePayloads as $line) {
                PodLine::create(array_merge($line, ['pod_record_id' => $record->id]));
            }

            $meta = array_merge($sale->fulfillment_meta ?? [], [
                'pod_captured' => true,
                'pod_record_id' => $record->id,
                'pod_signer_name' => $recipient,
                'pod_notes' => $record->notes,
                'pod_captured_at' => $record->captured_at?->toIso8601String(),
                'pod_status' => $record->status,
            ]);
            $sale->update(['fulfillment_meta' => $meta]);

            return $record->load(['lines.saleItem', 'sale', 'trip']);
        });

        $org = Organization::find($user->organization_id);
        if ($org && $record->status !== 'refused') {
            $this->notifications->notifyOrderDelivered($sale->fresh(), $org);
        }

        return $record;
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    protected function resolveStatus(array $lines): string
    {
        if ($lines === []) {
            return 'complete';
        }

        $allRefused = true;
        $anyPartial = false;

        foreach ($lines as $line) {
            $ordered = (float) $line['qty_ordered'];
            $delivered = (float) $line['qty_delivered'];
            $refused = (float) $line['qty_refused'];

            if ($delivered > 0) {
                $allRefused = false;
            }
            if ($delivered < $ordered || $refused > 0) {
                $anyPartial = true;
            }
        }

        if ($allRefused) {
            return 'refused';
        }

        return $anyPartial ? 'partial' : 'complete';
    }

    protected function storeUpload(mixed $file, int $saleId, string $kind): ?string
    {
        if (! $file instanceof UploadedFile) {
            return null;
        }

        $processor = app(UploadedImageProcessor::class);
        if ($processor->isProcessableImage($file)) {
            return $processor->storePublicImage($file, "pod/{$saleId}/{$kind}")['path'];
        }

        return $file->store("pod/{$saleId}/{$kind}", 'public');
    }

    public function hasPod(Sale $sale): bool
    {
        if (! empty(($sale->fulfillment_meta ?? [])['pod_captured'])) {
            return true;
        }

        return PodRecord::query()->where('sale_id', $sale->id)->exists();
    }

    /**
     * @param  list<int>  $saleIds
     * @return \Illuminate\Support\Collection<int, PodRecord>
     */
    public function latestBySaleIds(array $saleIds): \Illuminate\Support\Collection
    {
        if ($saleIds === []) {
            return collect();
        }

        return PodRecord::query()
            ->whereIn('sale_id', $saleIds)
            ->orderByDesc('captured_at')
            ->get()
            ->unique('sale_id')
            ->keyBy('sale_id');
    }

    /** @return array<string, mixed>|null */
    public function presentSummary(?PodRecord $record): ?array
    {
        if (! $record) {
            return null;
        }

        return [
            'id' => (int) $record->id,
            'recipient_name' => $record->recipient_name,
            'captured_at' => $record->captured_at?->toIso8601String(),
            'status' => $record->status,
            'notes' => $record->notes,
            'has_photo' => ! empty($record->photo_path),
            'has_signature' => ! empty($record->signature_path),
        ];
    }
}
