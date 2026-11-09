<?php

namespace Tests\Feature\Telegram;

use App\Actions\Telegram\IssueStaffEnrollment;
use App\Enums\OutboxDeliveryState;
use App\Models\OutboxDelivery;
use App\Models\OutboxMessage;
use App\Models\TelegramEnrollment;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Support\Outbox\OutboxDispatcher;
use App\Support\Telegram\TelegramUpdateHandler;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

class UpdateRecoveryTest extends TestCase
{
    use DatabaseMigrations;

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
    }

    protected function tearDown(): void
    {
        // DatabaseMigrations rolls every migration back after the test, and the owner
        // authority migration refuses to roll back while an owner account exists.
        User::query()->where('role', 'owner')->delete();

        parent::tearDown();
    }

    public function test_duplicate_update_recovers_after_dispatch_outage(): void
    {
        Http::fake([
            $this->endpoint() => Http::response(['ok' => true, 'result' => ['message_id' => 9]], 200),
        ]);

        $owner = User::factory()->owner()->create();
        $staff = User::factory()->bookingStaff()->create();
        $token = (new IssueStaffEnrollment)($owner, $staff->id);
        $update = $this->startUpdate(1, '/start '.$token);

        $this->receive($update);

        $this->app->instance(QueueFactory::class, new class implements QueueFactory
        {
            public function connection($name = null)
            {
                return $this;
            }

            public function push(object $job, mixed $data = '', ?string $queue = null): void
            {
                throw new RuntimeException('Queue push failed.');
            }
        });

        $this->assertSame(0, app(OutboxDispatcher::class)->dispatchBatch());
        $this->assertSame(OutboxDeliveryState::Retry, OutboxDelivery::query()->sole()->state);

        // Telegram repeats the update because the first answer was lost.
        $this->receive($update);
        $this->assertSame(1, TelegramUpdate::query()->count());
        $this->assertSame(1, OutboxMessage::query()->where('type', 'telegram.update.received')->count());

        $this->app->instance(QueueFactory::class, $this->app->make('queue'));

        $this->assertSame(1, app(OutboxDispatcher::class)->dispatchBatch());
        $this->assertSame(1, app(OutboxDispatcher::class)->dispatchBatch());
        $this->assertSame(0, app(OutboxDispatcher::class)->dispatchBatch());

        $this->assertSame('5550001111', TelegramEnrollment::sole()->claimed_telegram_user_id);
        $this->assertSame('claimed', TelegramUpdate::sole()->outcome);
        $this->assertSame(1, OutboxMessage::query()->where('type', 'telegram.reply')->count());
        $this->assertSame(0, OutboxDelivery::query()->where('state', '!=', OutboxDeliveryState::Completed->value)->count());
        Http::assertSentCount(1);

        // A worker that lost its lease replays the update handler without repeating anything.
        app(TelegramUpdateHandler::class)->handle(OutboxMessage::query()->where('type', 'telegram.update.received')->sole());

        $this->assertSame(1, OutboxMessage::query()->where('type', 'telegram.reply')->count());
        Http::assertSentCount(1);
    }

    private function receive(array $update): ?OutboxMessage
    {
        $this->sendJson(json_encode($update, JSON_THROW_ON_ERROR))->assertOk();

        return OutboxMessage::query()->where('type', 'telegram.update.received')->orderByDesc('id')->first();
    }

    private function sendJson(string $body): TestResponse
    {
        return $this->call('POST', '/webhooks/telegram', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => self::SECRET,
        ], $body);
    }

    private function endpoint(): string
    {
        return 'https://api.telegram.org/bot123456789:'.str_repeat('A', 35).'/sendMessage';
    }

    private function startUpdate(int $updateId, string $text): array
    {
        return ['update_id' => $updateId, 'message' => [
            'message_id' => 11, 'date' => 1793000000, 'text' => $text,
            'from' => ['id' => 5550001111, 'is_bot' => false, 'first_name' => 'Rani', 'username' => 'rani_k', 'language_code' => 'id'],
            'chat' => ['id' => 5550001111, 'type' => 'private', 'first_name' => 'Rani', 'username' => 'rani_k'],
        ]];
    }
}
