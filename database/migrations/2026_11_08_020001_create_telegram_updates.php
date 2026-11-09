<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_updates', function (Blueprint $table): void {
            $table->id();
            $table->string('bot_id', 20);
            $table->unsignedBigInteger('update_id');
            $table->string('kind', 20);                 // start | callback_query | other
            $table->json('payload')->nullable();        // null for kind other
            $table->string('start_token', 64)->nullable();
            $table->string('outcome', 20)->nullable();  // null = waiting for C2b; ignored
            $table->string('outcome_reason', 40)->nullable(); // unsupported | invalid_shape
            $table->dateTime('received_at')->index();
            $table->dateTime('processed_at')->nullable();
            $table->unique(['bot_id', 'update_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_updates');
    }
};
