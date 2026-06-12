<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tills', function (Blueprint $table) {
            if (! Schema::hasColumn('tills', 'till_name')) {
                $table->string('till_name', 200)->nullable()->after('till_number');
            }
            if (! Schema::hasColumn('tills', 'description')) {
                $after = Schema::hasColumn('tills', 'till_name') ? 'till_name' : 'till_number';
                $table->text('description')->nullable()->after($after);
            }
            if (! Schema::hasColumn('tills', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
        });
    }

    public function down(): void
    {
        Schema::table('tills', function (Blueprint $table) {
            $table->dropColumn(['till_name', 'description', 'is_active']);
        });
    }
};
