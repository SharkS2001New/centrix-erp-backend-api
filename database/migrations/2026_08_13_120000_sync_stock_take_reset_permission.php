<?php

use App\Services\Erp\PermissionMatrixService;
use Illuminate\Database\Migrations\Migration;

/**
 * Ensure inventory.stock_take.reset exists in permissions (Roles matrix Reset column).
 */
return new class extends Migration
{
    public function up(): void
    {
        PermissionMatrixService::ensure();
    }

    public function down(): void
    {
        // Registry permissions are not removed on rollback.
    }
};
