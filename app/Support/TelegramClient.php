<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Focused Telegram Bot API boundary: sendMessage and answerCallbackQuery.
 *
 * Stateless and dependency-free: reads only the configured bot token and
 * performs a single POST via Laravel's Http facade. Retries and failure
 * logging belong to the queued job (Phase 6), so this class never retries,
 * never logs, and never retains a raw transport exception (whose message can
 * contain the tokenized request URL).
 */
class TelegramClient
{
    private const PERMANENT_HTTP_STATUSES = [400, 401, 403, 404];

    public function sendMessage(string $message, string $chatId, ?int $messageThreadId = null, ?array $replyMarkup = null): int
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $message,
            'link_preview_options' => ['is_disabled' => true],
        ];

        if ($messageThreadId !== null) {
            $payload['message_thread_id'] = $messageThreadId;
        }

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }

        $response = $this->post('sendMessage', $payload);

        $messageId = $this->messageIdFrom($response);

        if ($messageId === null) {
            throw new TelegramDeliveryException(
                permanent: false,
                httpStatus: $response->status(),
                telegramErrorCode: $this->integerErrorCode($response),
            );
        }

        return $messageId;
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text, bool $showAlert = false): void
    {
        $response = $this->post('answerCallbackQuery', [
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
            'show_alert' => $showAlert,
        ]);

        if ($response->json('ok') !== true) {
            throw new TelegramDeliveryException(
                permanent: false,
                httpStatus: $response->status(),
                telegramErrorCode: $this->integerErrorCode($response),
            );
        }
    }

    /** POSTs one Bot API method; throws a sanitized TelegramDeliveryException for every non-2xx answer. */
    private function post(string $method, array $payload): Response
    {
        $request = Http::connectTimeout(3)->timeout(10);
        $caBundle = config('services.telegram.ca_bundle');
        if (is_string($caBundle) && trim($caBundle) !== '') {
            $request = $request->withOptions(['verify' => $caBundle]);
        }

        try {
            $response = $request->post($this->endpoint($method), $payload);
        } catch (ConnectionException) {
            // The raw exception message may contain the tokenized URL; it is
            // never retained, attached as previous, or logged.
            throw new TelegramDeliveryException(
                permanent: false,
            );
        }

        $status = $response->status();

        if (in_array($status, self::PERMANENT_HTTP_STATUSES, true)) {
            throw new TelegramDeliveryException(
                permanent: true,
                httpStatus: $status,
                telegramErrorCode: $this->integerErrorCode($response),
            );
        }

        if ($status === 429) {
            throw new TelegramDeliveryException(
                permanent: false,
                httpStatus: $status,
                telegramErrorCode: $this->integerErrorCode($response),
                retryAfterSeconds: $this->retryAfterFrom($response),
            );
        }

        if ($status === 408 || $status >= 500) {
            throw new TelegramDeliveryException(
                permanent: false,
                httpStatus: $status,
                telegramErrorCode: $this->integerErrorCode($response),
            );
        }

        if ($status < 200 || $status >= 300) {
            // Any otherwise unclassified non-success status is retryable unless
            // the design explicitly marks it permanent.
            throw new TelegramDeliveryException(
                permanent: false,
                httpStatus: $status,
                telegramErrorCode: $this->integerErrorCode($response),
            );
        }

        return $response;
    }

    /**
     * Success requires JSON with ok === true (strict) and an integer
     * result.message_id. Every malformed 2xx body yields null.
     */
    private function messageIdFrom(Response $response): ?int
    {
        $body = $response->json();

        if (! is_array($body)) {
            return null;
        }

        if (($body['ok'] ?? null) !== true) {
            return null;
        }

        $result = $body['result'] ?? null;

        if (! is_array($result)) {
            return null;
        }

        $messageId = $result['message_id'] ?? null;

        return is_int($messageId) ? $messageId : null;
    }

    /**
     * Accept only an integer Telegram error_code; coerce nothing.
     */
    private function integerErrorCode(Response $response): ?int
    {
        $body = $response->json();

        if (! is_array($body)) {
            return null;
        }

        $code = $body['error_code'] ?? null;

        return is_int($code) ? $code : null;
    }

    /**
     * Use parameters.retry_after only when it is a positive integer.
     * Strings, floats, booleans, zero, and negatives are invalid → null, so
     * the queued job falls back to its normal retry ladder.
     */
    private function retryAfterFrom(Response $response): ?int
    {
        $body = $response->json();

        if (! is_array($body)) {
            return null;
        }

        $retryAfter = $body['parameters']['retry_after'] ?? null;

        return is_int($retryAfter) && $retryAfter > 0 ? $retryAfter : null;
    }

    private function endpoint(string $method): string
    {
        return 'https://api.telegram.org/bot'.config('services.telegram.bot_token').'/'.$method;
    }
}
