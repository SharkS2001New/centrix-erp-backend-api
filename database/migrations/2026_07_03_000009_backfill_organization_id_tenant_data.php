<?php

use App\Services\Organization\OrganizationTenantDataBackfillService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(OrganizationTenantDataBackfillService::class)->run();
    }

    public function down(): void
    {
        // Non-destructive data repair — no rollback.
    }
};
