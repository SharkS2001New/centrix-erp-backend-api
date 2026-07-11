<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_mail_messages', function (Blueprint $table) {
            $table->string('mailbox_account_id', 64)->nullable()->after('folder')->index();
        });
    }

    public function down(): void
    {
        Schema::table('platform_mail_messages', function (Blueprint $table) {
            $table->dropIndex(['mailbox_account_id']);
            $table->dropColumn('mailbox_account_id');
        });
    }
};
