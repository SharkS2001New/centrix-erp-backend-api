<?php

namespace App\Services\Erp;

class WorkspaceSessionLabel
{
    public static function for(?string $workspaceId, ?string $loginChannel): string
    {
        if (is_string($workspaceId) && $workspaceId !== '') {
            $label = config("erp_workspaces.{$workspaceId}.label");
            if (is_string($label) && $label !== '') {
                return $label;
            }
        }

        return match ($loginChannel) {
            'pos' => (string) config('erp_workspaces.pos.label', 'External POS'),
            'mobile' => 'Mobile',
            default => (string) config('erp_workspaces.backoffice.label', 'Backoffice'),
        };
    }
}
