<?php

namespace App\Support\Telegram;

use App\Models\TelegramUpdate;
use App\Support\Outbox\OutboxWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class TelegramUpdateRecorder
{
    // A /start command, optionally addressed as /start@BotName, followed by whitespace or the end of the text.
    private const START_COMMAND = '/\A\/start(?:@[A-Za-z0-9_]{5,32})?(?=\s|\z)/';

    private const CHAT_TYPES = ['private', 'group', 'supergroup', 'channel'];

    public function __construct(private readonly OutboxWriter $outbox) {}

    /** The bot's numeric ID (the token's part before the colon), or null when the token is missing or malformed. */
    public static function botId(): ?string
    {
        $token = config('services.telegram.bot_token');

        return is_string($token) && preg_match('/\A(\d{1,20}):[A-Za-z0-9_-]{20,}\z/', $token, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * @param  array<array-key, mixed>  $update
     * @return TelegramUpdate|null null when update_id is not a non-negative integer
     */
    public function record(string $botId, array $update): ?TelegramUpdate
    {
        $updateId = $update['update_id'] ?? null;
        if (! is_int($updateId) || $updateId < 0) {
            return null;
        }

        $normalized = $this->normalize($update);

        return DB::transaction(function () use ($botId, $updateId, $normalized): TelegramUpdate {
            $now = CarbonImmutable::now('UTC')->setMicrosecond(0);

            $inserted = DB::table('telegram_updates')->insertOrIgnore([
                'bot_id' => $botId,
                'update_id' => $updateId,
                'kind' => $normalized['kind'],
                'payload' => $normalized['payload'] === null ? null : json_encode($normalized['payload'], JSON_THROW_ON_ERROR),
                'start_token' => $normalized['start_token'],
                'outcome' => $normalized['outcome'],
                'outcome_reason' => $normalized['outcome_reason'],
                'received_at' => $now,
                'processed_at' => $normalized['outcome'] === null ? null : $now,
            ]);

            $row = TelegramUpdate::query()
                ->where('bot_id', $botId)
                ->where('update_id', $updateId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($inserted === 1 && $normalized['outcome'] === null) {
                $this->outbox->record('telegram.update.received', "telegram.update:{$botId}:{$updateId}", ['telegram_update_id' => $row->id]);
            }

            return $row;
        });
    }

    /**
     * @param  array<array-key, mixed>  $update
     * @return array{kind: string, payload: ?array, start_token: ?string, outcome: ?string, outcome_reason: ?string}
     */
    private function normalize(array $update): array
    {
        if (array_key_exists('message', $update)) {
            $message = $update['message'];

            if (! is_array($message) || ! is_string($message['text'] ?? null) || preg_match(self::START_COMMAND, $message['text']) !== 1) {
                return $this->other('unsupported');
            }

            $text = $message['text'];
            $messageId = $message['message_id'] ?? null;
            $sender = $this->sender($message);
            $chat = $this->chat($message);

            if (! is_int($messageId) || $messageId <= 0 || $sender === null || $chat === null) {
                return $this->other('invalid_shape');
            }

            $token = trim((string) preg_replace(self::START_COMMAND, '', $text));
            if (preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $token) !== 1) {
                $token = null;
            }

            return [
                'kind' => 'start',
                'payload' => [
                    'chat_id' => $chat['id'],
                    'chat_type' => $chat['type'],
                    'from_id' => $sender['id'],
                    'from_is_bot' => $sender['is_bot'],
                    'message_id' => $messageId,
                ],
                'start_token' => $token,
                'outcome' => null,
                'outcome_reason' => null,
            ];
        }

        if (array_key_exists('callback_query', $update)) {
            $q = $update['callback_query'];

            if (! is_array($q)) {
                return $this->other('invalid_shape');
            }

            $id = $q['id'] ?? null;
            $sender = $this->sender($q);
            $data = $q['data'] ?? null;
            $message = $q['message'] ?? null;
            $dataValid = $data === null
                || (is_string($data) && $data !== '' && strlen($data) <= 64 && mb_check_encoding($data, 'UTF-8'));

            if (! is_string($id) || preg_match('/\A[\x21-\x7e]{1,64}\z/', $id) !== 1
                || $sender === null || ! $dataValid) {
                return $this->other('invalid_shape');
            }

            $chatId = null;
            $chatType = null;
            $messageId = null;
            $threadId = null;

            if ($message !== null) {
                if (! is_array($message)) {
                    return $this->other('invalid_shape');
                }

                $messageId = $message['message_id'] ?? null;
                $chat = $this->chat($message);
                $threadId = $message['message_thread_id'] ?? null;

                if (! is_int($messageId) || $messageId <= 0 || $chat === null
                    || ($threadId !== null && (! is_int($threadId) || $threadId <= 0))) {
                    return $this->other('invalid_shape');
                }

                $chatId = $chat['id'];
                $chatType = $chat['type'];
            }

            return [
                'kind' => 'callback_query',
                'payload' => [
                    'callback_query_id' => $id,
                    'from_id' => $sender['id'],
                    'from_is_bot' => $sender['is_bot'],
                    'data' => $data,
                    'chat_id' => $chatId,
                    'chat_type' => $chatType,
                    'message_id' => $messageId,
                    'message_thread_id' => $threadId,
                ],
                'start_token' => null,
                'outcome' => null,
                'outcome_reason' => null,
            ];
        }

        return $this->other('unsupported');
    }

    /**
     * @param  array<array-key, mixed>  $container
     * @return array{id: int, is_bot: bool}|null
     */
    private function sender(array $container): ?array
    {
        $from = $container['from'] ?? null;

        if (! is_array($from)) {
            return null;
        }

        $id = $from['id'] ?? null;
        $isBot = $from['is_bot'] ?? null;

        if (! is_int($id) || $id <= 0 || ! is_bool($isBot)) {
            return null;
        }

        return ['id' => $id, 'is_bot' => $isBot];
    }

    /**
     * @param  array<array-key, mixed>  $container
     * @return array{id: int, type: string}|null
     */
    private function chat(array $container): ?array
    {
        $chat = $container['chat'] ?? null;

        if (! is_array($chat)) {
            return null;
        }

        $id = $chat['id'] ?? null;
        $type = $chat['type'] ?? null;

        if (! is_int($id) || $id === 0 || ! is_string($type) || ! in_array($type, self::CHAT_TYPES, true)) {
            return null;
        }

        return ['id' => $id, 'type' => $type];
    }

    /**
     * @return array{kind: string, payload: null, start_token: null, outcome: string, outcome_reason: string}
     */
    private function other(string $reason): array
    {
        return ['kind' => 'other', 'payload' => null, 'start_token' => null, 'outcome' => 'ignored', 'outcome_reason' => $reason];
    }
}
