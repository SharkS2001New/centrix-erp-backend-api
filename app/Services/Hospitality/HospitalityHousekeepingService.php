<?php

namespace App\Services\Hospitality;

use App\Models\HospitalityRoom;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class HospitalityHousekeepingService
{
    public const STATUSES = ['vacant', 'occupied', 'dirty', 'clean', 'ooo'];

    public function board(Organization $org, ?Request $request = null): array
    {
        $includeStaff = $request ? $request->boolean('include_staff', true) : true;
        $statusFilter = $request ? strtolower(trim((string) $request->input('status', ''))) : '';
        $floorFilter = $request ? trim((string) $request->input('floor', '')) : '';

        $query = HospitalityRoom::query()
            ->with(['roomType:id,code,name'])
            ->where('organization_id', $org->id)
            ->where('is_active', true)
            ->orderBy('floor')
            ->orderBy('room_number');

        if ($statusFilter !== '' && in_array($statusFilter, self::STATUSES, true)) {
            $query->where('status', $statusFilter);
        }
        if ($floorFilter !== '') {
            $query->where('floor', $floorFilter);
        }

        $rooms = $query->get();

        // Counts always cover the full active board (not the filtered slice).
        $countRows = HospitalityRoom::query()
            ->where('organization_id', $org->id)
            ->where('is_active', true)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $byStatus = [];
        foreach (self::STATUSES as $status) {
            $byStatus[$status] = (int) ($countRows[$status] ?? 0);
        }

        $assigneeIds = $rooms
            ->pluck('housekeeping_assigned_to')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        $assignees = [];
        if ($assigneeIds && Schema::hasColumn('hospitality_rooms', 'housekeeping_assigned_to')) {
            $assignees = User::query()
                ->where('organization_id', $org->id)
                ->whereIn('id', $assigneeIds)
                ->get(['id', 'full_name', 'username'])
                ->keyBy('id');
        }

        $staff = [];
        if ($includeStaff) {
            $staff = User::query()
                ->where('organization_id', $org->id)
                ->where('is_active', true)
                ->orderByRaw('CASE WHEN full_name IS NULL OR full_name = ? THEN 1 ELSE 0 END', [''])
                ->orderBy('full_name')
                ->orderBy('username')
                ->limit(100)
                ->get(['id', 'full_name', 'username'])
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => trim((string) ($u->full_name ?: $u->username ?: '')),
                ])
                ->values()
                ->all();
        }

        $floors = HospitalityRoom::query()
            ->where('organization_id', $org->id)
            ->where('is_active', true)
            ->whereNotNull('floor')
            ->where('floor', '!=', '')
            ->distinct()
            ->orderBy('floor')
            ->pluck('floor')
            ->map(fn ($f) => (string) $f)
            ->values()
            ->all();

        $items = $rooms->map(function (HospitalityRoom $room) use ($assignees) {
            $status = (string) $room->status;
            $assigneeId = $room->housekeeping_assigned_to
                ? (int) $room->housekeeping_assigned_to
                : null;
            $assignee = $assigneeId ? ($assignees[$assigneeId] ?? null) : null;

            return [
                'id' => $room->id,
                'room_number' => $room->room_number,
                'floor' => $room->floor,
                'status' => $status,
                'room_type_name' => $room->roomType?->name,
                'housekeeping_assigned_to' => $assigneeId,
                'housekeeping_assignee_name' => $assignee
                    ? trim((string) ($assignee->full_name ?: $assignee->username ?: ''))
                    : null,
                'housekeeping_notes' => $room->housekeeping_notes,
                'occupancy_source' => $room->sold_check_id
                    ? 'pos_room_sale'
                    : ($status === 'occupied' ? 'pms_folio' : null),
            ];
        })->values()->all();

        return [
            'rooms' => $items,
            'counts' => $byStatus,
            'floors' => $floors,
            'staff' => $staff,
        ];
    }

    public function setStatus(Organization $org, int $roomId, string $status, array $extra = []): array
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

        $payload = ['status' => $status];
        if (array_key_exists('housekeeping_assigned_to', $extra)
            && Schema::hasColumn('hospitality_rooms', 'housekeeping_assigned_to')) {
            $assigneeId = $extra['housekeeping_assigned_to'];
            if ($assigneeId === null || $assigneeId === '' || (int) $assigneeId === 0) {
                $payload['housekeeping_assigned_to'] = null;
            } else {
                $exists = User::query()
                    ->where('organization_id', $org->id)
                    ->where('id', (int) $assigneeId)
                    ->exists();
                if (! $exists) {
                    throw ValidationException::withMessages([
                        'housekeeping_assigned_to' => ['Assignee is not a user in this organization.'],
                    ]);
                }
                $payload['housekeeping_assigned_to'] = (int) $assigneeId;
            }
        }
        if (array_key_exists('housekeeping_notes', $extra)
            && Schema::hasColumn('hospitality_rooms', 'housekeeping_notes')) {
            $notes = trim((string) ($extra['housekeeping_notes'] ?? ''));
            $payload['housekeeping_notes'] = $notes !== '' ? mb_substr($notes, 0, 500) : null;
        }

        $room->update($payload);
        $room->refresh();

        return [
            'id' => $room->id,
            'room_number' => $room->room_number,
            'floor' => $room->floor,
            'status' => $room->status,
            'housekeeping_assigned_to' => $room->housekeeping_assigned_to
                ? (int) $room->housekeeping_assigned_to
                : null,
            'housekeeping_notes' => $room->housekeeping_notes,
        ];
    }
}
