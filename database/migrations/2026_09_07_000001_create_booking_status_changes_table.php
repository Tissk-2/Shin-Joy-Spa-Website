<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_status_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->string('actor_email', 254);
            // Frozen at the original four statuses; later statuses arrive in 2026_10_22_010003.
            $table->enum('from_status', ['pending', 'confirmed', 'completed', 'cancelled']);
            $table->enum('to_status', ['pending', 'confirmed', 'completed', 'cancelled']);
            $table->string('reason', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 1024)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['booking_id', 'created_at']);
            $table->index(['admin_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_status_changes');
    }
};
