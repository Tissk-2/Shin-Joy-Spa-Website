<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('type', 64)->nullable();
            $table->unsignedInteger('capacity');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('cleaning_minutes')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('room_service_variant', function (Blueprint $table): void {
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_variant_id')->constrained()->cascadeOnDelete();
            $table->unique(['room_id', 'service_variant_id']);
        });
        Schema::create('room_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('room_id')->constrained()->restrictOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('treatment_ends_at');
            $table->dateTime('ends_at');
            $table->unsignedInteger('cleaning_minutes');
            $table->dateTime('released_at')->nullable();
            $table->string('release_reason')->nullable();
            $table->timestamps();
            $table->index(['room_id', 'released_at', 'starts_at', 'ends_at'], 'room_reservations_interval_index');
        });
        Schema::create('room_blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('room_id')->constrained()->restrictOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->text('reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['room_id', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_blocks');
        Schema::dropIfExists('room_reservations');
        Schema::dropIfExists('room_service_variant');
        Schema::dropIfExists('rooms');
    }
};
