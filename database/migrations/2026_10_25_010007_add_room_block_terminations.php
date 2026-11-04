<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_block_terminations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('room_block_id')->unique()->constrained('room_blocks')->restrictOnDelete();
            $table->string('kind', 32);
            $table->dateTime('occurred_at', 6);
            $table->text('reason');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->string('actor_role', 32);
            $table->unsignedBigInteger('room_id');
            $table->dateTime('original_starts_at', 6);
            $table->dateTime('original_ends_at', 6);
            $table->text('original_reason');
            $table->unsignedInteger('previous_version');
            $table->unsignedInteger('resulting_version');
        });
    }

    public function down(): void
    {
        // Never erase a terminal fact and silently restore maintenance occupancy.
        if (Schema::hasTable('room_block_terminations') && DB::table('room_block_terminations')->exists()) {
            throw new RuntimeException('Cannot roll back room block terminal evidence; retain compatible readers and apply a forward fix.');
        }
        Schema::dropIfExists('room_block_terminations');
    }
};
