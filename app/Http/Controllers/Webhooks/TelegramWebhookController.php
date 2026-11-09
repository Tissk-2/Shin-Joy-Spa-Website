<?php

namespace App\Http\Controllers\Webhooks;

use App\Support\Telegram\TelegramUpdateRecorder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use JsonException;
use Throwable;

final class TelegramWebhookController
{
    private const MAX_BODY_BYTES = 65536;

    private const LIMITER_KEY = 'telegram-webhook';

    private const LIMIT_PER_MINUTE = 300;

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

        if (RateLimiter::tooManyAttempts(self::LIMITER_KEY, self::LIMIT_PER_MINUTE)) {
            return response('', 429, ['Retry-After' => (string) RateLimiter::availableIn(self::LIMITER_KEY)]);
        }
        RateLimiter::hit(self::LIMITER_KEY, 60);

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

        try {
            $recorded = $recorder->record($botId, $update);
        } catch (Throwable $exception) {
            // The exception message can contain SQL bindings, including an enrollment token.
            Log::error('telegram.webhook.store_failed', ['exception' => $exception::class]);

            return response('', 503);
        }

        return $recorded === null ? $this->rejected('not_an_update') : response('', 200);
    }

    // Authenticated but unusable: acknowledge it, so Telegram stops retrying something that can never succeed.
    private function rejected(string $reason): Response
    {
        Log::warning('telegram.webhook.rejected', ['reason' => $reason]);

        return response('', 200);
    }
}
