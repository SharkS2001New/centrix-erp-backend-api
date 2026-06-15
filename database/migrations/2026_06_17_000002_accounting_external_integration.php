<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounting_connections')) {
            Schema::create('accounting_connections', function (Blueprint $table) {
                $table->id();
                $table->integer('organization_id');
                $table->string('provider', 30);
                $table->string('realm_id', 100)->nullable();
                $table->text('access_token')->nullable();
                $table->text('refresh_token')->nullable();
                $table->timestamp('token_expires_at')->nullable();
                $table->enum('status', ['disconnected', 'connected', 'error'])->default('disconnected');
                $table->text('last_error')->nullable();
                $table->timestamp('connected_at')->nullable();
                $table->integer('connected_by')->nullable();
                $table->timestamps();

                $table->unique(['organization_id', 'provider'], 'uq_org_accounting_provider');
            });
        }

        if (! Schema::hasTable('accounting_account_mappings')) {
            Schema::create('accounting_account_mappings', function (Blueprint $table) {
                $table->id();
                $table->integer('organization_id');
                $table->string('provider', 30);
                $table->string('local_account_code', 20);
                $table->string('external_account_id', 100);
                $table->string('external_account_name', 200)->nullable();
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'provider', 'local_account_code'],
                    'uq_org_provider_local_account',
                );
            });
        }

        if (! Schema::hasTable('accounting_export_queue')) {
            Schema::create('accounting_export_queue', function (Blueprint $table) {
                $table->id();
                $table->integer('organization_id');
                $table->string('provider', 30);
                $table->string('entry_number', 50);
                $table->date('entry_date');
                $table->string('reference_type', 50);
                $table->unsignedBigInteger('reference_id');
                $table->string('description')->nullable();
                $table->json('lines');
                $table->enum('status', ['pending', 'exported', 'failed'])->default('pending');
                $table->string('external_journal_id', 100)->nullable();
                $table->text('last_error')->nullable();
                $table->timestamp('exported_at')->nullable();
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'provider', 'reference_type', 'reference_id'],
                    'uq_org_provider_export_ref',
                );
                $table->index(['organization_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_export_queue');
        Schema::dropIfExists('accounting_account_mappings');
        Schema::dropIfExists('accounting_connections');
    }
};
