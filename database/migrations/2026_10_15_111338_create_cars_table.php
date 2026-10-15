<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cars', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_unicode_ci');
            $table->id();
            $table->foreignId('car_category_id');
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('brand', 100)->nullable();
            $table->string('model', 150)->nullable();
            $table->string('image')->nullable();
            $table->longText('description')->nullable();
            $table->unsignedSmallInteger('passenger_seats');
            $table->unsignedSmallInteger('luggage_bags')->default(0);
            $table->enum('transmission', ['manual', 'automatic'])->default('manual');
            $table->boolean('has_ac')->default(true);
            $table->enum('rate_basis', ['per_km', 'per_day', 'per_trip'])->default('per_km');
            $table->decimal('starting_rate', 10, 2);
            $table->unsignedSmallInteger('minimum_km_per_day')->nullable();
            $table->decimal('driver_allowance_per_day', 10, 2)->nullable();
            $table->decimal('extra_km_rate', 10, 2)->nullable();
            $table->longText('inclusion')->nullable();
            $table->longText('exclusion')->nullable();
            $table->longText('cancellation_policy')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->boolean('status')->default(true);
            $table->string('meta_title')->nullable();
            $table->text('meta_keyword')->nullable();
            $table->text('meta_description')->nullable();
            $table->timestamps();

            $table->index(['car_category_id', 'status'], 'cars_category_status_index');
            $table->index('status');
            $table->foreign('car_category_id', 'cars_category_foreign')
                ->references('id')->on('car_categories')
                ->restrictOnDelete()->cascadeOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cars');
    }
};
