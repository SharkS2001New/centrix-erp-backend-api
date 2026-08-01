<?php

namespace App\Services\Hospitality;

use App\Models\HospitalityRoom;
use App\Models\Organization;
use Illuminate\Validation\ValidationException;

class HospitalityHousekeepingService
{
    public const STATUSES = ['vacant', 'occupied', 'dirty', 'clean', 'ooo'];

    public function board(Organization $org): array
    {
        $rooms = HospitalityRoom::query()
            ->with(['roomType:id,code,name'])
            ->where('organization_id', $org->id)
            ->where('is_active', true)
            ->orderBy('floor')
            ->orderBy('room_number')
            ->get();

        $byStatus = [];
        foreach (self::STATUSES as $status) {
            $byStatus[$status] = 0;
        }

        $items = $rooms->map(function (HospitalityRoom $room) use (&$byStatus) {
            $status = (string) $room->status;
            if (isset($byStatus[$status])) {
                $byStatus[$status]++;
            }

            return [
                'id' => $room->id,
                'room_number' => $room->room_number,
                'floor' => $room->floor,
                'status' => $status,
                'room_type_name' => $room->roomType?->name,
            ];
        })->values()->all();

        return [
            'rooms' => $items,
            'counts' => $byStatus,
        ];
    }

    public function setStatus(Organization $org, int $roomId, string $status): array
    {
        $status = strtolower(trim($status));
        if (! in_array($status, self::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => ['Invalid housekeeping status.']]);
        }

        $room = HospitalityRoom::query()
            ->where('organization_id', $org->id)
            ->where('id', $roomId)
            ->firstOrFail();

        // Do not silently free an occupied room via HK — use front desk check-out.
        if ($room->status === 'occupied' && $status === 'vacant') {
            throw ValidationException::withMessages([
                'status' => ['Occupied rooms must be checked out at Front desk before marking vacant.'],
            ]);
        }

        $room->update(['status' => $status]);

        return [
            'id' => $room->id,
            'room_number' => $room->room_number,
            'floor' => $room->floor,
            'status' => $room->status,
        ];
    }
}
