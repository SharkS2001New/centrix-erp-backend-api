<?php

namespace App\Services\Erp;

class WorkspaceSessionLabel
{
    public static function for(?string $workspaceId, ?string $loginChannel, ?string $industry = null): string
    {
        $workspaceId = is_string($workspaceId) ? trim($workspaceId) : '';
        $industry = is_string($industry) ? strtolower(trim($industry)) : null;

        // Stale retail workspace ids on hospitality tenants.
        if ($industry === 'hospitality' && ($workspaceId === '' || $workspaceId === 'backoffice' || $workspaceId === 'pos')) {
            $workspaceId = $workspaceId === 'pos' ? 'hotel_bar_pos' : '';
        }

        if ($workspaceId !== '') {
            $label = config("erp_workspaces.{$workspaceId}.label");
            if (is_string($label) && $label !== '') {
                return $label;
            }
        }

        return match ($loginChannel) {
            'pos' => $industry === 'hospitality'
                ? (string) config('erp_workspaces.hotel_bar_pos.label', 'Hotel POS')
                : (string) config('erp_workspaces.pos.label', 'External POS'),
            'mobile' => 'Mobile',
            'manager' => 'Centrix Manager',
            default => $industry === 'hospitality'
                // Without an active workspace, prefer Hotel Backoffice over retail "Backoffice".
                ? (string) config('erp_workspaces.hospitality_backoffice.label', 'Hotel Backoffice')
                : (string) config('erp_workspaces.backoffice.label', 'Backoffice'),
        };
    }
}
