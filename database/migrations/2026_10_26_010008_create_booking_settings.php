<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->json('review_intervals');
            $table->unsignedInteger('review_target_minutes');
            $table->unsignedInteger('booking_horizon_days');
            $table->unsignedInteger('minimum_lead_minutes');
            $table->unsignedInteger('minimum_checkout_minutes');
            $table->unsignedInteger('version');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_settings');
    }
};
