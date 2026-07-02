<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_requests', function (Blueprint $table) {
            $table->id();
            $table->integer('organization_id');
            $table->string('type', 64);
            $table->string('module', 64)->nullable();
            $table->string('reference_type', 64);
            $table->unsignedBigInteger('reference_id');
            $table->integer('requested_by');
            $table->integer('assigned_to')->nullable();
            $table->string('approver_permission', 128)->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('title');
            $table->text('reason')->nullable();
            $table->json('payload')->nullable();
            $table->integer('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations');
            $table->foreign('requested_by')->references('id')->on('users');
            $table->index(['organization_id', 'status'], 'idx_action_requests_org_status');
            $table->index(['reference_type', 'reference_id'], 'idx_action_requests_reference');
            $table->index(
                ['organization_id', 'type', 'reference_type', 'reference_id'],
                'idx_action_requests_lookup',
            );
        });

        Schema::create('in_app_notifications', function (Blueprint $table) {
            $table->id();
            $table->integer('organization_id');
            $table->integer('user_id');
            $table->unsignedBigInteger('action_request_id')->nullable();
            $table->string('type', 32);
            $table->string('severity', 16)->default('default');
            $table->string('title');
            $table->text('message');
            $table->string('action_url', 512)->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations');
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('action_request_id')->references('id')->on('action_requests')->nullOnDelete();
            $table->index(['user_id', 'is_read', 'created_at'], 'idx_in_app_notifications_user_unread');
            $table->index(['organization_id', 'user_id'], 'idx_in_app_notifications_org_user');
        });

        Schema::create('approval_actions', function (Blueprint $table) {
            $table->id();
            $table->integer('organization_id');
            $table->unsignedBigInteger('action_request_id');
            $table->integer('user_id');
            $table->string('action', 32);
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('organization_id')->references('id')->on('organizations');
            $table->foreign('action_request_id')->references('id')->on('action_requests')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users');
            $table->index(['action_request_id', 'created_at'], 'idx_approval_actions_request');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_actions');
        Schema::dropIfExists('in_app_notifications');
        Schema::dropIfExists('action_requests');
    }
};
