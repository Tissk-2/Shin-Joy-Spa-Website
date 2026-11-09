<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->dateTime('expires_at');
            $table->string('claimed_telegram_user_id', 20)->nullable();
            $table->dateTime('claimed_at')->nullable();
            $table->dateTime('activated_at')->nullable();
            $table->foreignId('activated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_staff_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('telegram_user_id', 20)->unique();
            $table->foreignId('enrollment_id')->nullable()->constrained('telegram_enrollments')->nullOnDelete();
            $table->dateTime('activated_at');
            $table->timestamps();
        });

        Schema::create('telegram_staff_link_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('target_name');
            $table->string('telegram_user_id', 20);
            $table->string('action', 20);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->dateTime('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_staff_link_changes');
        Schema::dropIfExists('telegram_staff_links');
        Schema::dropIfExists('telegram_enrollments');
    }
};
