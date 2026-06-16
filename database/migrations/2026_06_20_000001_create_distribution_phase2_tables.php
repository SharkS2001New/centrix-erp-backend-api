<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dispatch_trips')) {
            Schema::create('dispatch_trips', function (Blueprint $table) {
                $table->id();
                $table->integer('branch_id');
                $table->string('trip_code', 50);
                $table->integer('route_id')->nullable();
                $table->integer('driver_id')->nullable();
                $table->integer('vehicle_id')->nullable();
                $table->date('scheduled_date');
                $table->enum('status', ['draft', 'loading', 'in_transit', 'completed', 'cancelled'])->default('draft');
                $table->text('notes')->nullable();
                $table->string('prepared_by_name', 200)->nullable();
                $table->timestamp('prepared_at')->nullable();
                $table->string('checked_by_name', 200)->nullable();
                $table->timestamp('checked_at')->nullable();
                $table->timestamp('departed_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->integer('created_by')->nullable();
                $table->timestamps();

                $table->foreign('branch_id')->references('id')->on('branches');
                $table->foreign('route_id')->references('id')->on('routes')->nullOnDelete();
                $table->foreign('driver_id')->references('id')->on('drivers')->nullOnDelete();
                $table->foreign('vehicle_id')->references('id')->on('vehicles')->nullOnDelete();
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
                $table->unique(['branch_id', 'trip_code']);
                $table->index(['branch_id', 'scheduled_date', 'status']);
                $table->index(['route_id', 'scheduled_date']);
            });
        }

        if (! Schema::hasTable('dispatch_trip_sales')) {
            Schema::create('dispatch_trip_sales', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('trip_id');
                $table->bigInteger('sale_id');
                $table->unsignedSmallInteger('stop_seq')->default(1);

                $table->foreign('trip_id')->references('id')->on('dispatch_trips')->cascadeOnDelete();
                $table->foreign('sale_id')->references('id')->on('sales')->cascadeOnDelete();
                $table->unique(['trip_id', 'sale_id']);
                $table->index(['sale_id']);
            });
        }

        if (! Schema::hasTable('loading_lists')) {
            Schema::create('loading_lists', function (Blueprint $table) {
                $table->id();
                $table->integer('branch_id');
                $table->unsignedBigInteger('trip_id');
                $table->integer('route_id')->nullable();
                $table->date('list_date');
                $table->enum('status', ['open', 'locked', 'loaded'])->default('open');
                $table->string('prepared_by_name', 200)->nullable();
                $table->string('checked_by_name', 200)->nullable();
                $table->timestamp('locked_at')->nullable();
                $table->decimal('total_amount', 14, 2)->default(0);
                $table->timestamps();

                $table->foreign('branch_id')->references('id')->on('branches');
                $table->foreign('trip_id')->references('id')->on('dispatch_trips')->cascadeOnDelete();
                $table->foreign('route_id')->references('id')->on('routes')->nullOnDelete();
                $table->unique('trip_id');
            });
        }

        if (! Schema::hasTable('loading_list_lines')) {
            Schema::create('loading_list_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('loading_list_id');
                $table->unsignedSmallInteger('line_no');
                $table->string('product_code', 200);
                $table->string('product_name', 255);
                $table->float('quantity');
                $table->string('quantity_label', 120)->nullable();
                $table->string('pack_breakdown', 200)->nullable();
                $table->decimal('unit_price', 12, 2)->default(0);
                $table->decimal('line_total', 14, 2)->default(0);

                $table->foreign('loading_list_id')->references('id')->on('loading_lists')->cascadeOnDelete();
                $table->index(['loading_list_id', 'line_no']);
            });
        }

        if (! Schema::hasTable('pod_records')) {
            Schema::create('pod_records', function (Blueprint $table) {
                $table->id();
                $table->integer('branch_id');
                $table->bigInteger('sale_id');
                $table->unsignedBigInteger('trip_id')->nullable();
                $table->timestamp('captured_at');
                $table->integer('captured_by')->nullable();
                $table->string('recipient_name', 200);
                $table->text('notes')->nullable();
                $table->string('signature_path', 500)->nullable();
                $table->string('photo_path', 500)->nullable();
                $table->enum('status', ['complete', 'partial', 'refused'])->default('complete');
                $table->decimal('gps_lat', 10, 7)->nullable();
                $table->decimal('gps_lng', 10, 7)->nullable();
                $table->timestamps();

                $table->foreign('branch_id')->references('id')->on('branches');
                $table->foreign('sale_id')->references('id')->on('sales')->cascadeOnDelete();
                $table->foreign('trip_id')->references('id')->on('dispatch_trips')->nullOnDelete();
                $table->foreign('captured_by')->references('id')->on('users')->nullOnDelete();
                $table->index(['sale_id']);
                $table->index(['trip_id']);
            });
        }

        if (! Schema::hasTable('pod_lines')) {
            Schema::create('pod_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('pod_record_id');
                $table->bigInteger('sale_item_id');
                $table->float('qty_ordered');
                $table->float('qty_delivered');
                $table->float('qty_refused')->default(0);
                $table->string('reason', 255)->nullable();

                $table->foreign('pod_record_id')->references('id')->on('pod_records')->cascadeOnDelete();
                $table->foreign('sale_item_id')->references('id')->on('sale_items')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('route_schedules')) {
            Schema::create('route_schedules', function (Blueprint $table) {
                $table->id();
                $table->integer('branch_id');
                $table->integer('route_id');
                $table->unsignedTinyInteger('day_of_week');
                $table->integer('default_driver_id')->nullable();
                $table->integer('default_vehicle_id')->nullable();
                $table->time('departure_time')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->foreign('branch_id')->references('id')->on('branches');
                $table->foreign('route_id')->references('id')->on('routes')->cascadeOnDelete();
                $table->foreign('default_driver_id')->references('id')->on('drivers')->nullOnDelete();
                $table->foreign('default_vehicle_id')->references('id')->on('vehicles')->nullOnDelete();
                $table->unique(['branch_id', 'route_id', 'day_of_week']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pod_lines');
        Schema::dropIfExists('pod_records');
        Schema::dropIfExists('loading_list_lines');
        Schema::dropIfExists('loading_lists');
        Schema::dropIfExists('dispatch_trip_sales');
        Schema::dropIfExists('route_schedules');
        Schema::dropIfExists('dispatch_trips');
    }
};
