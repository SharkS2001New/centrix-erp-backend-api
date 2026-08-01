<?php

namespace App\Services\Hospitality;

use App\Models\HospitalityRatePlan;
use App\Models\HospitalityRoomType;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HospitalityRatePlanService
{
    public function list(Organization $org, ?int $roomTypeId = null): array
    {
        $query = HospitalityRatePlan::query()
            ->with(['roomType:id,code,name'])
            ->where('organization_id', $org->id)
            ->orderBy('room_type_id')
            ->orderBy('name');

        if ($roomTypeId) {
            $query->where('room_type_id', $roomTypeId);
        }

        return $query->get()->map(fn (HospitalityRatePlan $p) => $this->toArray($p))->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function upsert(Organization $org, array $data, ?int $id = null): HospitalityRatePlan
    {
        $roomTypeId = (int) ($data['room_type_id'] ?? 0);
        $type = HospitalityRoomType::query()
            ->where('organization_id', $org->id)
            ->where('id', $roomTypeId)
            ->first();
        if (! $type) {
            throw ValidationException::withMessages(['room_type_id' => ['Room type not found.']]);
        }

        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        $name = trim((string) ($data['name'] ?? ''));
        if ($code === '' || $name === '') {
            throw ValidationException::withMessages(['code' => ['Code and name are required.']]);
        }

        return DB::transaction(function () use ($org, $data, $id, $roomTypeId, $code, $name) {
            if ($id) {
                $plan = HospitalityRatePlan::query()
                    ->where('organization_id', $org->id)
                    ->where('id', $id)
                    ->firstOrFail();
            } else {
                $plan = new HospitalityRatePlan(['organization_id' => $org->id]);
            }

            $isDefault = (bool) ($data['is_default'] ?? $plan->is_default ?? false);
            if ($isDefault) {
                HospitalityRatePlan::query()
                    ->where('organization_id', $org->id)
                    ->where('room_type_id', $roomTypeId)
                    ->when($id, fn ($q) => $q->where('id', '!=', $id))
                    ->update(['is_default' => false]);
            }

            $plan->fill([
                'room_type_id' => $roomTypeId,
                'code' => $code,
                'name' => $name,
                'amount' => round((float) ($data['amount'] ?? 0), 2),
                'is_default' => $isDefault,
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
            ]);
            $plan->save();

            return $plan->fresh('roomType');
        });
    }

    public function delete(Organization $org, int $id): void
    {
        HospitalityRatePlan::query()
            ->where('organization_id', $org->id)
            ->where('id', $id)
            ->firstOrFail()
            ->delete();
    }

    public function toArray(HospitalityRatePlan $p): array
    {
        return [
            'id' => $p->id,
            'room_type_id' => $p->room_type_id,
            'room_type_name' => $p->roomType?->name,
            'code' => $p->code,
            'name' => $p->name,
            'amount' => (float) $p->amount,
            'is_default' => (bool) $p->is_default,
            'is_active' => (bool) $p->is_active,
        ];
    }
}
