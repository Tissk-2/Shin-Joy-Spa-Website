<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->unsignedInteger('duration_minutes')->nullable()->change();
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->unsignedBigInteger('price_idr')->nullable()->change();
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->boolean('is_vip')->default(false)->after('popularity');
        });
    }

    public function down(): void
    {
        DB::table('services')->whereNull('duration_minutes')->update(['duration_minutes' => 0]);
        DB::table('services')->whereNull('price_idr')->update(['price_idr' => 0]);

        Schema::table('services', function (Blueprint $table): void {
            $table->unsignedInteger('duration_minutes')->nullable(false)->change();
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->unsignedBigInteger('price_idr')->nullable(false)->change();
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->dropColumn('is_vip');
        });
    }
};
