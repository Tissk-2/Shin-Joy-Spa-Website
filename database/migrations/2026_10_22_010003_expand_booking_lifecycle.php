<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STATUSES = [
        'pending',
        'confirmed',
        'awaiting_payment',
        'completed',
        'cancelled',
        'no_show',
    ];

    private const LEGACY_STATUSES = [
        'pending',
        'confirmed',
        'completed',
        'cancelled',
    ];

    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->unsignedInteger('booking_version')->default(1);
            $table->string('payment_preference', 16)->nullable();
            $table->string('source_channel', 16)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('treatment_ends_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('payment_deadline_at')->nullable();
            $table->timestamp('review_due_at')->nullable();
            $table->timestamp('refund_eligible_at')->nullable();
            $table->string('refund_reason', 64)->nullable();
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->foreignId('created_by_staff_id')->nullable()->constrained('users')->nullOnDelete();
        });

        // The enum change must be the last bookings rebuild: SQLite rebuilds copy
        // introspected column types, which do not carry the original CHECK constraint.
        Schema::table('bookings', function (Blueprint $table): void {
            $table->enum('status', self::STATUSES)->default('pending')->change();
        });

        Schema::table('booking_status_changes', function (Blueprint $table): void {
            $table->enum('from_status', self::STATUSES)->change();
            $table->enum('to_status', self::STATUSES)->change();
            $table->string('actor_name')->nullable()->change();
            $table->string('actor_email', 254)->nullable()->change();
        });

        Schema::table('booking_status_changes', function (Blueprint $table): void {
            $table->string('actor_type', 16)->default('staff');
            $table->string('channel', 16)->nullable();
            $table->string('external_actor_id', 191)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('booking_status_changes', function (Blueprint $table): void {
            $table->dropColumn(['actor_type', 'channel', 'external_actor_id']);
        });

        Schema::table('booking_status_changes', function (Blueprint $table): void {
            $table->string('actor_name')->change();
            $table->string('actor_email', 254)->change();
            $table->enum('from_status', self::LEGACY_STATUSES)->change();
            $table->enum('to_status', self::LEGACY_STATUSES)->change();
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropForeign(['created_by_staff_id']);
        });

        // Later migrations may index this column; SQLite refuses DROP COLUMN while such an index exists.
        if (Schema::hasIndex('bookings', 'bookings_staff_submission_unique')) {
            Schema::table('bookings', function (Blueprint $table): void {
                $table->dropUnique('bookings_staff_submission_unique');
            });
        }

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn([
                'booking_version',
                'payment_preference',
                'source_channel',
                'created_by_staff_id',
                'starts_at',
                'treatment_ends_at',
                'approved_at',
                'payment_deadline_at',
                'review_due_at',
                'refund_eligible_at',
                'refund_reason',
            ]);
        });

        // Fails if rows already use a lifecycle status, or if system-actor history rows have
        // null actor_name/actor_email (restored to NOT NULL above); accepted, since B4b/B4c write them.
        Schema::table('bookings', function (Blueprint $table): void {
            $table->enum('status', self::LEGACY_STATUSES)->default('pending')->change();
        });
    }
};
