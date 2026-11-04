<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_guards', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedBigInteger('revision')->default(0);
        });
        DB::table('schedule_guards')->insert(['id' => 1, 'revision' => 0]);
        Schema::table('bookings', fn (Blueprint $table) => $table->string('requested_room_type', 64)->nullable());
    }

    public function down(): void
    {
        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn('requested_room_type'));
        Schema::dropIfExists('schedule_guards');
    }
};
