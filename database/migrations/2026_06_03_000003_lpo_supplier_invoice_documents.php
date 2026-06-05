<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lpo_supplier_invoices')) {
            return;
        }

        Schema::table('lpo_supplier_invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('lpo_supplier_invoices', 'file_path')) {
                $table->string('file_path', 500)->nullable()->after('invoice_amount');
            }
            if (! Schema::hasColumn('lpo_supplier_invoices', 'file_name')) {
                $table->string('file_name', 255)->nullable()->after('file_path');
            }
            if (! Schema::hasColumn('lpo_supplier_invoices', 'mime_type')) {
                $table->string('mime_type', 100)->nullable()->after('file_name');
            }
            if (! Schema::hasColumn('lpo_supplier_invoices', 'file_size')) {
                $table->unsignedInteger('file_size')->nullable()->after('mime_type');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('lpo_supplier_invoices')) {
            return;
        }

        Schema::table('lpo_supplier_invoices', function (Blueprint $table) {
            $columns = array_filter(
                ['file_path', 'file_name', 'mime_type', 'file_size'],
                fn (string $col) => Schema::hasColumn('lpo_supplier_invoices', $col),
            );
            if ($columns !== []) {
                $table->dropColumn(array_values($columns));
            }
        });
    }
};
