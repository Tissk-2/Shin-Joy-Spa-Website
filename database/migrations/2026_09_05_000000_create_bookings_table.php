<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_extension_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 32)->unique();
            $table->uuid('submission_key');
            $table->char('request_fingerprint', 64);
            // Frozen at the original four statuses; later statuses arrive in 2026_10_22_010003.
            $table->enum('status', ['pending', 'confirmed', 'completed', 'cancelled'])->default('pending');
            $table->string('customer_name');
            $table->string('customer_email');
            $table->string('customer_phone', 32);
            $table->date('appointment_date');
            $table->string('appointment_time', 5);
            $table->string('appointment_timezone', 64)->default('Asia/Jakarta');
            $table->unsignedTinyInteger('guests');
            $table->text('notes')->nullable();
            $table->string('package_slug');
            $table->string('package_name_id');
            $table->string('package_name_en');
            $table->boolean('package_is_vip')->default(false);
            $table->string('treatment_mode', 32);
            $table->string('base_choice_code');
            $table->unsignedInteger('base_duration_minutes');
            $table->unsignedBigInteger('base_price_idr');
            $table->string('extension_choice_code')->nullable();
            $table->unsignedInteger('extension_duration_minutes')->nullable();
            $table->unsignedBigInteger('extension_price_idr')->nullable();
            $table->unsignedInteger('total_duration_minutes');
            $table->unsignedBigInteger('total_price_idr');
            $table->timestamps();

            $table->unique(['user_id', 'submission_key'], 'bookings_user_submission_unique');
            $table->index(['user_id', 'appointment_date', 'appointment_time'], 'bookings_user_appointment_index');
            $table->index(['status', 'appointment_date'], 'bookings_status_appointment_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
