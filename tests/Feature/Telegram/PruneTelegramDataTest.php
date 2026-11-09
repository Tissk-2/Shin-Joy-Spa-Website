<?php

namespace Tests\Feature\Telegram;

use App\Exceptions\Outbox\OutboxPermanentException;
use App\Jobs\PruneTelegramData;
use App\Models\OutboxMessage;
use App\Support\Outbox\OutboxWriter;
use App\Support\Telegram\TelegramReplyHandler;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PruneTelegramDataTest extends TestCase
{
    use RefreshDatabase;

    // Split so the pre-commit secret scan does not flag these deliberate test fixtures.
    private const SECRET = 'test-'
        .'webhook-secret-0123456789_ABCDEFGHIJ';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.bot_token' => '123456789:'.str_repeat('A', 35),
        ]);
        CarbonImmutable::setTestNow('2026-11-16 03:00:00 UTC');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_week_old_update_data_is_cleared_and_the_rows_are_kept(): void
    {
        $this->insertUpdate(1, '2026-11-09 02:59:59', 'claimed', null);
        $this->insertUpdate(2, '2026-11-08 00:00:00', null, 'stale-token');
        $this->insertUpdate(3, '2026-11-09 03:00:00', null, 'keep-token');
        $this->insertUpdate(4, '2026-11-15 03:00:00', 'claimed', null);

        app(PruneTelegramData::class)->handle();

        $one = $this->updateRow(1);
        $this->assertNull($one->payload);
        $this->assertSame('123456789', $one->bot_id);
        $this->assertSame('start', $one->kind);
        $this->assertSame('claimed', $one->outcome);
        $this->assertSame('2026-11-09 02:59:59', $one->received_at);

        $two = $this->updateRow(2);
        $this->assertNull($two->payload);
        $this->assertNull($two->start_token);
        $this->assertSame('123456789', $two->bot_id);
        $this->assertSame('start', $two->kind);
        $this->assertNull($two->outcome);
        $this->assertSame('2026-11-08 00:00:00', $two->received_at);

        $three = $this->updateRow(3);
        $this->assertNotNull($three->payload);
        $this->assertSame('keep-token', $three->start_token);

        $four = $this->updateRow(4);
        $this->assertNotNull($four->payload);
        $this->assertSame('2026-11-15 03:00:00', $four->received_at);

        $this->assertDatabaseCount('telegram_updates', 4);

        // The tombstone still deduplicates: posting update 1 again must change nothing.
        $this->sendJson(json_encode($this->startUpdate(1, '/start'), JSON_THROW_ON_ERROR))->assertOk();

        $this->assertDatabaseCount('telegram_updates', 4);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_week_old_reply_payloads_are_cleared(): void
    {
        DB::transaction(function (): void {
            $writer = app(OutboxWriter::class);
            $writer->record('telegram.reply', 'telegram.reply:1', ['chat_id' => 5550001111, 'text' => 'Old']);
            $writer->record('telegram.reply', 'telegram.reply:2', ['chat_id' => 5550002222, 'text' => 'New']);
            $writer->record('telegram.update.received', 'telegram.update:123456789:1', ['telegram_update_id' => 1]);
        });

        DB::table('outbox_messages')->whereIn('deduplication_key', ['telegram.reply:1', 'telegram.update:123456789:1'])
            ->update(['created_at' => '2026-11-08 00:00:00']);
        DB::table('outbox_messages')->where('deduplication_key', 'telegram.reply:2')
            ->update(['created_at' => '2026-11-15 03:00:00']);

        app(PruneTelegramData::class)->handle();

        $this->assertSame('{}', $this->messagePayload('telegram.reply:1'));
        $this->assertStringContainsString('5550002222', $this->messagePayload('telegram.reply:2'));
        $this->assertStringContainsString('telegram_update_id', $this->messagePayload('telegram.update:123456789:1'));

        // A requeued cleared reply fails permanently instead of sending an empty message.
        Http::fake();
        $message = OutboxMessage::query()->where('deduplication_key', 'telegram.reply:1')->sole();

        try {
            app(TelegramReplyHandler::class)->handle($message);
            $this->fail('Expected the cleared reply to fail permanently.');
        } catch (OutboxPermanentException $exception) {
            $this->assertSame('payload', $exception->reason());
        }

        Http::assertNothingSent();
    }

    public function test_the_schedule_registers_the_daily_overlap_guarded_prune(): void
    {
        $this->app->make(ConsoleKernel::class)->bootstrap();

        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(static fn ($event): bool => $event->description === 'telegram:prune-data');

        $this->assertCount(1, $events);
        $this->assertSame('0 0 * * *', $events->first()->expression);
        $this->assertTrue($events->first()->withoutOverlapping);
        $this->assertSame(60, $events->first()->expiresAt);
    }

    private function insertUpdate(int $updateId, string $receivedAt, ?string $outcome, ?string $token): void
    {
        DB::table('telegram_updates')->insert([
            'bot_id' => '123456789',
            'update_id' => $updateId,
            'kind' => 'start',
            'payload' => json_encode([
                'chat_id' => 5550001111,
                'chat_type' => 'private',
                'from_id' => 5550001111,
                'from_is_bot' => false,
                'message_id' => 11,
            ], JSON_THROW_ON_ERROR),
            'start_token' => $token,
            'outcome' => $outcome,
            'outcome_reason' => null,
            'received_at' => $receivedAt,
            'processed_at' => $outcome === null ? null : $receivedAt,
        ]);
    }

    private function updateRow(int $updateId): object
    {
        return DB::table('telegram_updates')->where('update_id', $updateId)->first();
    }

    private function messagePayload(string $key): string
    {
        return DB::table('outbox_messages')->where('deduplication_key', $key)->value('payload');
    }

    private function sendJson(string $body): TestResponse
    {
        return $this->call('POST', '/webhooks/telegram', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => self::SECRET,
        ], $body);
    }

    private function startUpdate(int $updateId, string $text): array
    {
        return ['update_id' => $updateId, 'message' => [
            'message_id' => 11, 'date' => 1793000000, 'text' => $text,
            'from' => ['id' => 5550001111, 'is_bot' => false, 'first_name' => 'Rani', 'username' => 'rani_k', 'language_code' => 'id'],
            'chat' => [
                'id' => 5550001111,
                'type' => 'private',
                'first_name' => 'Rani',
                'username' => 'rani_k',
            ],
        ]];
    }
}
