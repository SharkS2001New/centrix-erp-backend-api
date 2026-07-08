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
}
