<?php

namespace App\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Clears Telegram data older than a week. Update rows stay, so a repeated update_id is still recognised. */
final class PruneTelegramData
{
    public const RETENTION_DAYS = 7;

    public function handle(): void
    {
        $now = CarbonImmutable::now('UTC')->setMicrosecond(0);
        $cutoff = $now->subDays(self::RETENTION_DAYS);

        DB::table('telegram_updates')
            ->where('received_at', '<', $cutoff)
            ->where(fn ($query) => $query->whereNotNull('payload')->orWhereNotNull('start_token'))
            ->update(['payload' => null, 'start_token' => null]);

        // Reply and Decline prompt rows hold a chat ID, and a staff name or a sender's full Telegram ID. Nothing
        // reads them after delivery.
        DB::table('outbox_messages')
            ->whereIn('type', ['telegram.reply', 'telegram.decline.prompt'])   // mutation D
            ->where('created_at', '<', $cutoff)
            ->where('payload', '!=', '{}')
            ->update(['payload' => '{}', 'updated_at' => $now]);
    }
}
