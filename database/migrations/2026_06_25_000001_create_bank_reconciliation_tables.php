<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bank_reconciliations')) {
            return;
        }

        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->integer('organization_id');
            $table->integer('chart_of_account_id');
            $table->string('title', 120)->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('opening_balance', 14, 2)->nullable();
            $table->decimal('statement_balance', 14, 2);
            $table->decimal('book_balance', 14, 2)->default(0);
            $table->decimal('outstanding_receipts', 14, 2)->default(0);
            $table->decimal('outstanding_payments', 14, 2)->default(0);
            $table->decimal('adjusted_book_balance', 14, 2)->default(0);
            $table->decimal('variance', 14, 2)->default(0);
            $table->enum('status', ['in_progress', 'completed', 'void'])->default('in_progress');
            $table->text('notes')->nullable();
            $table->string('imported_filename', 255)->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('completed_by')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'chart_of_account_id', 'status'], 'idx_bank_recon_org_account_status');
            $table->index(['organization_id', 'period_end'], 'idx_bank_recon_org_period');
        });

        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_reconciliation_id')->constrained('bank_reconciliations')->cascadeOnDelete();
            $table->date('line_date');
            $table->string('description', 500)->nullable();
            $table->string('reference', 120)->nullable();
            $table->decimal('amount', 14, 2);
            $table->enum('match_status', ['unmatched', 'matched', 'excluded'])->default('unmatched');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['bank_reconciliation_id', 'match_status'], 'idx_bank_stmt_line_recon_status');
            $table->index(['bank_reconciliation_id', 'line_date'], 'idx_bank_stmt_line_recon_date');
        });

        Schema::create('bank_reconciliation_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_reconciliation_id')->constrained('bank_reconciliations')->cascadeOnDelete();
            $table->foreignId('bank_statement_line_id')->nullable()->constrained('bank_statement_lines')->nullOnDelete();
            $table->unsignedBigInteger('journal_entry_line_id');
            $table->enum('match_type', ['auto', 'manual'])->default('manual');
            $table->decimal('matched_amount', 14, 2);
            $table->integer('matched_by')->nullable();
            $table->timestamp('matched_at')->nullable();
            $table->timestamps();

            $table->unique(['bank_reconciliation_id', 'bank_statement_line_id'], 'uq_bank_recon_stmt_line');
            $table->unique(['bank_reconciliation_id', 'journal_entry_line_id'], 'uq_bank_recon_je_line');
            $table->index(['bank_reconciliation_id'], 'idx_bank_recon_match_recon');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliation_matches');
        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('bank_reconciliations');
    }
};
