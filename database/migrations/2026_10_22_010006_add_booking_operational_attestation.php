<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_status_changes', function (Blueprint $table) {
            $table->timestamp('operational_confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('booking_status_changes', function (Blueprint $table) {
            $table->dropColumn('operational_confirmed_at');
        });
    }
};
