<?php

namespace App\Services\Erp;

use App\Services\Auth\UserLoginChannelService;

class WorkspaceSessionLabel
{
    public static function for(
        ?string $workspaceId,
        ?string $loginChannel,
        ?string $industry = null,
        ?string $computerId = null,
    ): string {
        $workspaceId = is_string($workspaceId) ? trim($workspaceId) : '';
        $loginChannel = is_string($loginChannel) ? strtolower(trim($loginChannel)) : '';
        $industry = is_string($industry) ? strtolower(trim($industry)) : null;
        $computerId = is_string($computerId) ? trim($computerId) : '';

        // Centrix AS Mobile app client ids — even if an older build stored a wrong channel.
        if ($computerId !== '' && str_starts_with(strtoupper($computerId), 'ASMOBILE_')) {
            return 'CentrixAsMobile';
        }

        // Dedicated mobile apps: channel label wins over any leftover web workspace id.
        if ($loginChannel === UserLoginChannelService::CENTRIX_AS_MOBILE) {
            return 'CentrixAsMobile';
        }
        if ($loginChannel === UserLoginChannelService::MOBILE) {
            return 'CentrixFieldSales';
        }
        if ($loginChannel === UserLoginChannelService::MANAGER) {
            return 'Centrix Manager';
        }

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
            default => $industry === 'hospitality'
                // Without an active workspace, prefer Hotel Backoffice over retail "Backoffice".
                ? (string) config('erp_workspaces.hospitality_backoffice.label', 'Hotel Backoffice')
                : (string) config('erp_workspaces.backoffice.label', 'Backoffice'),
        };
    }
}
