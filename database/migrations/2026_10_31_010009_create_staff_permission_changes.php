<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_permission_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('target_name');
            $table->string('target_email');
            $table->string('target_role', 20);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->string('actor_email')->nullable();
            $table->string('actor_role', 20)->nullable();
            $table->boolean('was_allowed');
            $table->boolean('is_allowed');
            $table->string('channel', 20);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_permission_changes');
    }
};
