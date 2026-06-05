<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_return_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('supplier_id');
            $table->unsignedInteger('branch_id');
            $table->enum('source_type', ['manual', 'lpo']);
            $table->unsignedBigInteger('lpo_no')->nullable();
            $table->enum('status', ['pending_approval', 'approved', 'rejected'])->default('pending_approval');
            $table->text('notes')->nullable();
            $table->unsignedInteger('returned_by');
            $table->unsignedInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedInteger('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['supplier_id', 'status']);
            $table->index('lpo_no');
        });

        Schema::table('supplier_returns', function (Blueprint $table) {
            $table->unsignedBigInteger('document_id')->nullable()->after('id');
            $table->index('document_id');
        });

        $legacy = DB::table('supplier_returns')->whereNull('document_id')->get();
        foreach ($legacy as $row) {
            $docId = DB::table('supplier_return_documents')->insertGetId([
                'supplier_id' => $row->supplier_id,
                'branch_id' => $row->branch_id,
                'source_type' => $row->reference_type === 'lpo' ? 'lpo' : 'manual',
                'lpo_no' => $row->reference_type === 'lpo' ? $row->reference_id : null,
                'status' => 'approved',
                'notes' => $row->reason,
                'returned_by' => $row->returned_by,
                'approved_by' => $row->returned_by,
                'approved_at' => $row->created_at ?? now(),
                'created_at' => $row->created_at ?? now(),
            ]);
            DB::table('supplier_returns')->where('id', $row->id)->update(['document_id' => $docId]);
        }
    }

    public function down(): void
    {
        Schema::table('supplier_returns', function (Blueprint $table) {
            $table->dropIndex(['document_id']);
            $table->dropColumn('document_id');
        });
        Schema::dropIfExists('supplier_return_documents');
    }
};
