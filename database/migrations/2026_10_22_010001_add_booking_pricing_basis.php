<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->string('pricing_basis', 32)->nullable();
            $table->unsignedTinyInteger('min_guests')->nullable();
            $table->unsignedTinyInteger('max_guests')->nullable();
            $table->timestamp('pricing_verified_at')->nullable();
            $table->unsignedBigInteger('pricing_verified_by')->nullable()->index();
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->string('pricing_basis', 32)->nullable();
            $table->unsignedTinyInteger('min_guests')->nullable();
            $table->unsignedTinyInteger('max_guests')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->dropIndex(['pricing_verified_by']);
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->dropColumn(['pricing_basis', 'min_guests', 'max_guests', 'pricing_verified_at', 'pricing_verified_by']);
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn(['pricing_basis', 'min_guests', 'max_guests']);
        });
    }
};
