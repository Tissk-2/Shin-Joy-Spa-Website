<?php

namespace App\Queries;

use App\Models\TelegramEnrollment;
use App\Models\TelegramStaffLink;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class TelegramStaffQuery
{
    /**
     * @param  list<int>  $userIds
     * @return array<int, array<string, mixed>>
     */
    public function states(array $userIds, CarbonImmutable $now): array
    {
        $links = TelegramStaffLink::query()->whereIn('user_id', $userIds)->get()->keyBy('user_id');
        $open = TelegramEnrollment::query()
            ->whereIn('user_id', $userIds)
            ->whereNull('activated_at')
            ->whereNull('cancelled_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('user_id');
        $states = [];

        foreach ($userIds as $userId) {
            $link = $links->get($userId);
            $enrollment = $open->get($userId)?->first();

            if ($link !== null) {
                $states[$userId] = [
                    'state' => 'linked',
                    'identity' => self::hint($link->telegram_user_id),
                    'linkedAt' => CarbonImmutable::parse($link->activated_at, 'UTC')->toIso8601ZuluString(),
                ];
            } elseif ($enrollment !== null && $enrollment->claimed_at !== null) {
                $states[$userId] = [
                    'state' => 'claimed',
                    'enrollmentId' => $enrollment->id,
                    'identity' => self::hint($enrollment->claimed_telegram_user_id),
                    'claimedAt' => CarbonImmutable::parse($enrollment->claimed_at, 'UTC')->toIso8601ZuluString(),
                ];
            } elseif ($enrollment !== null && $enrollment->expires_at > $now) {
                $states[$userId] = [
                    'state' => 'waiting',
                    'enrollmentId' => $enrollment->id,
                    'expiresAt' => CarbonImmutable::parse($enrollment->expires_at, 'UTC')->toIso8601ZuluString(),
                ];
            } else {
                $states[$userId] = ['state' => 'none'];
            }
        }

        return $states;
    }

    /** @return list<array<string, mixed>> */
    public function history(): array
    {
        return DB::table('telegram_staff_link_changes')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn ($row): array => [
                'action' => $row->action,
                'targetName' => $row->target_name,
                'targetDeleted' => $row->target_user_id === null,
                'identity' => self::hint($row->telegram_user_id),
                'actorName' => $row->actor_name,
                'at' => CarbonImmutable::parse($row->created_at, 'UTC')->toIso8601ZuluString(),
            ])
            ->all();
    }

    /** The configured bot username, or null when it is missing or not a valid Telegram username. */
    public static function botUsername(): ?string
    {
        $username = config('services.telegram.bot_username');

        return is_string($username) && preg_match('/\A[A-Za-z0-9_]{5,32}\z/', $username) === 1 ? $username : null;
    }

    public static function hint(string $telegramUserId): string
    {
        return substr($telegramUserId, -4);
    }
}
