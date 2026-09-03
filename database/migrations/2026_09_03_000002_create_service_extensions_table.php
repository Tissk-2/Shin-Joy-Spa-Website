<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_extensions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('code')->unique();
            $table->string('treatment_mode', 32);
            $table->unsignedInteger('duration_minutes');
            $table->unsignedBigInteger('price_idr');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('display_order');
            $table->timestamps();

            $table->unique(['service_id', 'treatment_mode', 'duration_minutes'], 'service_extension_choice_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_extensions');
    }
};
