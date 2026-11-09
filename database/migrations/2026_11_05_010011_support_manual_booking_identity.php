<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STATUSES = ['pending', 'confirmed', 'awaiting_payment', 'completed', 'cancelled', 'no_show'];

    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->string('customer_email')->nullable()->change();
        });

        // SQLite rebuilds the table on ->change() and drops the status CHECK; re-apply the enum after every rebuild.
        Schema::table('bookings', function (Blueprint $table): void {
            $table->enum('status', self::STATUSES)->default('pending')->change(); // up: keep this the last rebuild
        });

        // An index is not a rebuild, so it can follow the enum.
        Schema::table('bookings', function (Blueprint $table): void {
            // submission_key leads: on MySQL an index led by created_by_staff_id would replace the foreign key's own index and block down().
            $table->unique(['submission_key', 'created_by_staff_id'], 'bookings_staff_submission_unique');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropUnique('bookings_staff_submission_unique');
        });

        // Fails if accountless rows with a null email exist; accepted, as with 010003's down path.
        Schema::table('bookings', function (Blueprint $table): void {
            $table->string('customer_email')->nullable(false)->change();
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->enum('status', self::STATUSES)->default('pending')->change();
        });
    }
};
