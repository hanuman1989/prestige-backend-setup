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
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('meta_title')->nullable();
            $table->text('meta_keyword')->nullable();
            $table->text('meta_description')->nullable();
            $table->text('duration');
            $table->text('tour_best_time')->nullable();
            $table->longText('inclusion')->nullable();
            $table->longText('exclusion')->nullable();
            $table->longText('accommodation_details')->nullable();
            $table->longText('transportation')->nullable();
            $table->longText('description')->nullable();
            $table->boolean('is_special_package')->default(false);
            $table->boolean('status')->default(true);
            $table->unsignedTinyInteger('current_step')->default(1);
            $table->timestamps();
            $table->index(['status', 'current_step', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
