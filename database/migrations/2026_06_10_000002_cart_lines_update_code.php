<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cart_lines')) {
            return;
        }

        if (! Schema::hasColumn('cart_lines', 'update_code')) {
            Schema::table('cart_lines', function (Blueprint $table) {
                $table->string('update_code', 24)->nullable()->after('cart_id');
            });
        }

        DB::table('cart_lines')
            ->whereNull('update_code')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('cart_lines')
                        ->where('id', $row->id)
                        ->update(['update_code' => $this->newCode()]);
                }
            });

        DB::statement("ALTER TABLE cart_lines MODIFY update_code VARCHAR(24) NOT NULL");

        $indexExists = DB::selectOne(
            "SELECT COUNT(1) AS c
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = 'cart_lines'
               AND index_name = 'uq_cart_line_update_code'"
        );
        if ((int) ($indexExists->c ?? 0) === 0) {
            DB::statement("ALTER TABLE cart_lines ADD UNIQUE INDEX uq_cart_line_update_code (update_code)");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('cart_lines') || ! Schema::hasColumn('cart_lines', 'update_code')) {
            return;
        }

        $indexExists = DB::selectOne(
            "SELECT COUNT(1) AS c
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = 'cart_lines'
               AND index_name = 'uq_cart_line_update_code'"
        );
        if ((int) ($indexExists->c ?? 0) > 0) {
            DB::statement("ALTER TABLE cart_lines DROP INDEX uq_cart_line_update_code");
        }

        Schema::table('cart_lines', function (Blueprint $table) {
            $table->dropColumn('update_code');
        });
    }

    private function newCode(): string
    {
        do {
            $code = 'CLU-'.Str::upper(Str::random(10));
            $exists = DB::table('cart_lines')->where('update_code', $code)->exists();
        } while ($exists);

        return $code;
    }
};

