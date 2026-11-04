<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 120);
            $table->string('deduplication_key', 191);
            // Hex digests preserve exact identity under case/accent-insensitive
            // or padding-insensitive database collations; keep originals too.
            $table->char('deduplication_hash', 64)->unique();
            $table->longText('payload');
            $table->unsignedInteger('schema_version')->default(1);
            $table->timestamps();
            $table->index('type');
        });
        Schema::create('outbox_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained('outbox_messages')->cascadeOnDelete();
            $table->string('handler_key', 120);
            $table->char('handler_key_hash', 64);
            $table->string('state', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('available_at');
            $table->string('lease_token', 64)->nullable();
            $table->dateTime('lease_expires_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('skipped_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['message_id', 'handler_key_hash']);
            $table->index(['state', 'available_at']);
            $table->index('lease_expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_deliveries');
        Schema::dropIfExists('outbox_messages');
    }
};
