<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('webauthn_credentials')) {
            return;
        }

        Schema::create('webauthn_credentials', function (Blueprint $table) {
            $table->id();
            // users.id is signed INT (legacy schema).
            $table->integer('user_id');
            $table->string('name', 120)->nullable();
            /** Credential ID as base64url (unique per RP). */
            $table->string('credential_id', 512);
            $table->text('public_key');
            $table->string('user_handle', 128);
            $table->unsignedBigInteger('counter')->default(0);
            $table->string('aaguid', 36)->nullable();
            $table->json('transports')->nullable();
            $table->boolean('backup_eligible')->nullable();
            $table->boolean('backup_status')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique('credential_id');
            $table->index('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webauthn_credentials');
    }
};
