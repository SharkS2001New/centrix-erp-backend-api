<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_returns') && ! Schema::hasColumn('customer_returns', 'proof_file_path')) {
            Schema::table('customer_returns', function (Blueprint $table) {
                $table->string('proof_file_path', 500)->nullable()->after('reason');
                $table->string('proof_file_name', 255)->nullable()->after('proof_file_path');
                $table->string('proof_file_mime_type', 100)->nullable()->after('proof_file_name');
                $table->unsignedInteger('proof_file_size')->nullable()->after('proof_file_mime_type');
            });
        }

        if (Schema::hasTable('supplier_return_documents') && ! Schema::hasColumn('supplier_return_documents', 'proof_file_path')) {
            Schema::table('supplier_return_documents', function (Blueprint $table) {
                $table->string('proof_file_path', 500)->nullable()->after('return_reason');
                $table->string('proof_file_name', 255)->nullable()->after('proof_file_path');
                $table->string('proof_file_mime_type', 100)->nullable()->after('proof_file_name');
                $table->unsignedInteger('proof_file_size')->nullable()->after('proof_file_mime_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('customer_returns') && Schema::hasColumn('customer_returns', 'proof_file_path')) {
            Schema::table('customer_returns', function (Blueprint $table) {
                $table->dropColumn([
                    'proof_file_path',
                    'proof_file_name',
                    'proof_file_mime_type',
                    'proof_file_size',
                ]);
            });
        }

        if (Schema::hasTable('supplier_return_documents') && Schema::hasColumn('supplier_return_documents', 'proof_file_path')) {
            Schema::table('supplier_return_documents', function (Blueprint $table) {
                $table->dropColumn([
                    'proof_file_path',
                    'proof_file_name',
                    'proof_file_mime_type',
                    'proof_file_size',
                ]);
            });
        }
    }
};
