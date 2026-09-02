<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained('service_categories')->restrictOnDelete();
            $table->string('slug')->unique();
            $table->string('name_id');
            $table->string('name_en');
            $table->text('description_id');
            $table->text('description_en');
            $table->text('long_description_id');
            $table->text('long_description_en');
            $table->string('good_for_id')->nullable();
            $table->string('good_for_en')->nullable();
            $table->unsignedInteger('duration_minutes');
            $table->unsignedBigInteger('price_idr');
            $table->string('image_path');
            $table->json('gallery_paths');
            $table->unsignedInteger('popularity');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('display_order');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
