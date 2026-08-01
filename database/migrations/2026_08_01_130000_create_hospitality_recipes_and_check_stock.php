<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hotel F&B recipes + check stock balancing — hospitality domain only
 * (not retail sales / temporary_carts).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hospitality_recipes', function (Blueprint $table) {
            $table->id();
            $table->integer('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            // Menu / sellable product (Ugali plate, Tusker bottle, …).
            $table->string('menu_product_code', 64);
            // recipe = explode ingredients; direct = deduct the menu SKU itself; none = no stock.
            $table->string('deduct_mode', 16)->default('recipe');
            $table->boolean('is_active')->default(true);
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'menu_product_code'], 'hosp_recipe_menu_uq');
            $table->index(['organization_id', 'is_active'], 'hosp_recipe_active_idx');
        });

        Schema::create('hospitality_recipe_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained('hospitality_recipes')->cascadeOnDelete();
            $table->integer('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->string('ingredient_product_code', 64);
            // Quantity in the ingredient product’s stock base units (e.g. kg, litres, pcs).
            $table->decimal('quantity', 14, 4);
            $table->decimal('waste_percent', 8, 2)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['recipe_id', 'ingredient_product_code'], 'hosp_recipe_ing_uq');
            $table->index(['organization_id', 'ingredient_product_code'], 'hosp_recipe_ing_org_idx');
        });

        Schema::table('hospitality_checks', function (Blueprint $table) {
            if (! Schema::hasColumn('hospitality_checks', 'stock_balanced')) {
                $table->boolean('stock_balanced')->default(false)->after('amount_paid');
            }
            if (! Schema::hasColumn('hospitality_checks', 'stock_deducted_at')) {
                $table->timestamp('stock_deducted_at')->nullable()->after('stock_balanced');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hospitality_checks', function (Blueprint $table) {
            if (Schema::hasColumn('hospitality_checks', 'stock_deducted_at')) {
                $table->dropColumn('stock_deducted_at');
            }
            if (Schema::hasColumn('hospitality_checks', 'stock_balanced')) {
                $table->dropColumn('stock_balanced');
            }
        });
        Schema::dropIfExists('hospitality_recipe_ingredients');
        Schema::dropIfExists('hospitality_recipes');
    }
};
