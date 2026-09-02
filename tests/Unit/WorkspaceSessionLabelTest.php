<?php

namespace Tests\Unit;

use App\Services\Erp\WorkspaceSessionLabel;
use Tests\TestCase;

class WorkspaceSessionLabelTest extends TestCase
{
    public function test_manager_login_channel_uses_centrix_manager_label(): void
    {
        $this->assertSame('Centrix Manager', WorkspaceSessionLabel::for(null, 'manager'));
    }

    public function test_unknown_login_channel_falls_back_to_backoffice_label(): void
    {
        $this->assertSame(
            (string) config('erp_workspaces.backoffice.label', 'Backoffice'),
            WorkspaceSessionLabel::for(null, 'backoffice'),
        );
    }

    public function test_hospitality_null_workspace_uses_hotel_backoffice_not_retail_backoffice(): void
    {
        $this->assertSame(
            'Hotel Backoffice',
            WorkspaceSessionLabel::for(null, 'backoffice', 'hospitality'),
        );
    }

    public function test_hospitality_workspace_ids_use_hotel_labels(): void
    {
        $this->assertSame(
            'Hotel POS',
            WorkspaceSessionLabel::for('hotel_bar_pos', 'backoffice', 'hospitality'),
        );
        $this->assertSame(
            'Hotel Backoffice',
            WorkspaceSessionLabel::for('hospitality_backoffice', 'backoffice', 'hospitality'),
        );
    }

    public function test_stale_retail_workspace_on_hospitality_tenant_is_remapped(): void
    {
        $this->assertSame(
            'Hotel Backoffice',
            WorkspaceSessionLabel::for('backoffice', 'backoffice', 'hospitality'),
        );
        $this->assertSame(
            'Hotel POS',
            WorkspaceSessionLabel::for('pos', 'backoffice', 'hospitality'),
        );
    }
}
