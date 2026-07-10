<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_mail_messages', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 16); // inbound | outbound
            $table->string('folder', 24)->default('inbox'); // inbox | sent | drafts | trash
            $table->string('thread_key', 191)->nullable()->index();
            $table->string('message_id', 255)->nullable()->unique();
            $table->string('in_reply_to', 255)->nullable()->index();
            $table->string('from_address', 255);
            $table->string('from_name', 255)->nullable();
            $table->json('to_addresses')->nullable();
            $table->json('cc_addresses')->nullable();
            $table->string('subject', 500)->nullable();
            $table->longText('body_text')->nullable();
            $table->longText('body_html')->nullable();
            $table->integer('organization_id')->nullable()->index();
            $table->unsignedBigInteger('contract_id')->nullable()->index();
            $table->unsignedBigInteger('sent_by_user_id')->nullable()->index();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->string('imap_uid', 64)->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['folder', 'sent_at']);
            $table->index(['folder', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_mail_messages');
    }
};
