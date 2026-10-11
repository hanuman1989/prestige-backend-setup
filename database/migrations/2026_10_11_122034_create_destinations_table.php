<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('destinations', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('meta_title')->nullable();
            $table->text('meta_keyword')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('image')->nullable();
            $table->longText('description')->nullable();
            $table->unsignedTinyInteger('destination_type');
            $table->boolean('is_most_popular')->default(false);
            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->index(['status', 'id']);
            $table->index(['destination_type', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destinations');
    }
};
