<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lpo_attachments', function (Blueprint $table) {
            if (! Schema::hasColumn('lpo_attachments', 'file_path')) {
                $table->string('file_path', 500)->nullable()->after('file_name');
            }
            if (! Schema::hasColumn('lpo_attachments', 'mime_type')) {
                $table->string('mime_type', 100)->nullable()->after('file_path');
            }
            if (! Schema::hasColumn('lpo_attachments', 'file_size')) {
                $table->unsignedInteger('file_size')->nullable()->after('mime_type');
            }
            if (! Schema::hasColumn('lpo_attachments', 'uploaded_by')) {
                $table->unsignedBigInteger('uploaded_by')->nullable()->after('file_size');
            }
            if (! Schema::hasColumn('lpo_attachments', 'created_at')) {
                $table->timestamp('created_at')->nullable()->after('uploaded_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('lpo_attachments', function (Blueprint $table) {
            foreach (['file_path', 'mime_type', 'file_size', 'uploaded_by', 'created_at'] as $column) {
                if (Schema::hasColumn('lpo_attachments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
