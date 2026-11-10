<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_booking_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->string('chat_id', 64);                          // numeric, or @ plus a username of up to 32
            $table->unsignedBigInteger('message_thread_id')->nullable();
            $table->unsignedBigInteger('message_id')->nullable();   // set once Telegram confirms the send
            $table->unsignedInteger('booking_version');             // the version the message showed
            $table->string('state', 20);                            // sending | sent
            $table->string('render_key', 64)->nullable();           // the latest render; only it may record its message
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['booking_id', 'chat_id']);
        });

        Schema::create('telegram_action_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('telegram_booking_message_id')->constrained('telegram_booking_messages')->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->unsignedInteger('booking_version');
            $table->string('action', 20);                           // approve | decline_prompt | decline_confirm (C3b)
            $table->char('nonce_hash', 64)->unique();
            $table->foreignId('initiating_user_id')->nullable()->constrained('users')->nullOnDelete();   // C3b
            $table->string('initiating_telegram_user_id', 20)->nullable();                               // C3b
            $table->dateTime('expires_at');
            $table->dateTime('consumed_at')->nullable();
            $table->string('result', 30)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_action_tokens');
        Schema::dropIfExists('telegram_booking_messages');
    }
};
