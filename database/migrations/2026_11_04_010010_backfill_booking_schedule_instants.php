<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('bookings')->whereNull('starts_at')->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                $date = preg_replace('/ 00:00:00\z/', '', (string) $row->appointment_date);
                $raw = $date.' '.$row->appointment_time;
                try {
                    $start = CarbonImmutable::createFromFormat('!Y-m-d H:i', $raw, (string) $row->appointment_timezone);
                } catch (Throwable) {
                    continue;
                }
                if (! $start instanceof CarbonImmutable || $start->format('Y-m-d H:i') !== $raw) {
                    continue;
                }
                $start = $start->utc();
                DB::table('bookings')->where('id', $row->id)->update([
                    'starts_at' => $start->format('Y-m-d H:i:s'),
                    'treatment_ends_at' => $start->addMinutes((int) $row->total_duration_minutes)->format('Y-m-d H:i:s'),
                ]);
            }
        });
    }

    public function down(): void
    {
        // The backfilled instants are correct derived data, and 010003::down()
        // drops both columns when the lifecycle schema itself is rolled back.
    }
};
