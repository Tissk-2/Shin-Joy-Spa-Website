<?php

namespace App\Http\Controllers\Webhooks;

use App\Support\Telegram\TelegramUpdateRecorder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use JsonException;
use Throwable;

final class TelegramWebhookController
{
    private const MAX_BODY_BYTES = 65536;

    private const LIMITER_KEY = 'telegram-webhook';

    private const LIMIT_PER_MINUTE = 300;

    // Any Telegram user can message the bot. Without a limit per sender, one user
    // could use up the shared budget and delay staff alerts and button presses.
    private const SENDER_LIMITER_PREFIX = 'telegram-webhook-sender:';

    private const SENDER_LIMIT_PER_MINUTE = 20;

    public function __invoke(Request $request, TelegramUpdateRecorder $recorder): Response
    {
        $secret = config('services.telegram.webhook_secret');
        $botId = TelegramUpdateRecorder::botId();
        if (! is_string($secret) || preg_match('/\A[A-Za-z0-9_-]{32,256}\z/', $secret) !== 1 || $botId === null) {
            return response('', 503);
        }

        $given = $request->header('X-Telegram-Bot-Api-Secret-Token');
        if (! is_string($given) || ! hash_equals($secret, $given)) {
            return response('', 403);
        }

        $body = $request->getContent();
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return $this->rejected('too_large');
        }
        try {
            $update = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->rejected('invalid_json');
        }
        if (! is_array($update)) {
            return $this->rejected('not_an_update');
        }

        // A sender over their limit is acknowledged and dropped, so Telegram doesn't resend it,
        // and doesn't use up the shared budget. Logged once per window, even when concurrent
        // requests pushed the count past the limit, so spam can't flood the log.
        $senderKey = self::senderKey($update);
        if ($senderKey !== null && RateLimiter::tooManyAttempts($senderKey, self::SENDER_LIMIT_PER_MINUTE)) {
            if (Cache::add($senderKey.':logged', true, max(1, RateLimiter::availableIn($senderKey)))) {
                Log::warning('telegram.webhook.rejected', ['reason' => 'sender_limited']);
            }

            return response('', 200);
        }

        if (RateLimiter::tooManyAttempts(self::LIMITER_KEY, self::LIMIT_PER_MINUTE)) {
            return response('', 429, ['Retry-After' => (string) RateLimiter::availableIn(self::LIMITER_KEY)]);
        }

        try {
            $recorded = $recorder->record($botId, $update);
        } catch (Throwable $exception) {
            // The exception message can contain SQL bindings, including an enrollment token.
            Log::error('telegram.webhook.store_failed', ['exception' => $exception::class]);

            return response('', 503);
        }
        if ($recorded === null) {
            return $this->rejected('not_an_update');
        }

        // Counted only once stored: an update refused with 429 or 503 comes back and must not count twice.
        RateLimiter::hit(self::LIMITER_KEY, 60);
        if ($senderKey !== null) {
            RateLimiter::hit($senderKey, 60);
        }

        return response('', 200);
    }

    /**
     * Every update carries one object beside update_id; its from.id is the Telegram user who
     * sent it. Updates without one (channel posts) fall under the shared limit only. The ID is
     * hashed, so cache keys and any error that quotes them hold no Telegram user ID.
     *
     * @param  array<array-key, mixed>  $update
     */
    private static function senderKey(array $update): ?string
    {
        foreach ($update as $key => $value) {
            if ($key !== 'update_id' && is_array($value) && is_int($value['from']['id'] ?? null)) {
                return self::SENDER_LIMITER_PREFIX.hash_hmac('sha256', (string) $value['from']['id'], (string) config('app.key'));
            }
        }

        return null;
    }

    // Authenticated but unusable: acknowledge it, so Telegram stops retrying something that can never succeed.
    private function rejected(string $reason): Response
    {
        Log::warning('telegram.webhook.rejected', ['reason' => $reason]);

        return response('', 200);
    }
}
