<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_device_tokens')) {
            $this->ensureUserDeviceTokensIndexes();

            return;
        }

        if (! Schema::hasTable('manager_device_tokens')) {
            return;
        }

        if (! Schema::hasColumn('manager_device_tokens', 'app_channel')) {
            Schema::table('manager_device_tokens', function (Blueprint $table) {
                $table->string('app_channel', 24)->default('manager')->after('user_id');
            });
        }

        try {
            Schema::table('manager_device_tokens', function (Blueprint $table) {
                $table->dropUnique(['user_id', 'token']);
            });
        } catch (\Throwable) {
            // Index may already be dropped on a partially migrated database.
        }

        Schema::rename('manager_device_tokens', 'user_device_tokens');

        $this->ensureUserDeviceTokensIndexes();
    }

    public function down(): void
    {
        if (! Schema::hasTable('user_device_tokens')) {
            return;
        }

        Schema::table('user_device_tokens', function (Blueprint $table) {
            $table->dropUnique('user_device_tokens_user_token_channel_unique');
            $table->dropIndex('user_device_tokens_user_channel_index');
        });

        Schema::rename('user_device_tokens', 'manager_device_tokens');

        Schema::table('manager_device_tokens', function (Blueprint $table) {
            $table->unique(['user_id', 'token']);
            $table->dropColumn('app_channel');
        });
    }

    protected function ensureUserDeviceTokensIndexes(): void
    {
        if (! Schema::hasTable('user_device_tokens')) {
            return;
        }

        if (! Schema::hasColumn('user_device_tokens', 'app_channel')) {
            Schema::table('user_device_tokens', function (Blueprint $table) {
                $table->string('app_channel', 24)->default('manager')->after('user_id');
            });
        }

        if (! $this->indexExists('user_device_tokens', 'user_device_tokens_user_token_channel_unique')) {
            Schema::table('user_device_tokens', function (Blueprint $table) {
                $table->unique(['user_id', 'token', 'app_channel'], 'user_device_tokens_user_token_channel_unique');
            });
        }

        if (! $this->indexExists('user_device_tokens', 'user_device_tokens_user_channel_index')) {
            Schema::table('user_device_tokens', function (Blueprint $table) {
                $table->index(['user_id', 'app_channel'], 'user_device_tokens_user_channel_index');
            });
        }
    }

    protected function indexExists(string $table, string $indexName): bool
    {
        $indexes = Schema::getIndexes($table);

        foreach ($indexes as $index) {
            if (($index['name'] ?? '') === $indexName) {
                return true;
            }
        }

        return false;
    }
};
