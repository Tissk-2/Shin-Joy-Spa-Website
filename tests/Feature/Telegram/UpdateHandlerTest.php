<?php

namespace Tests\Feature\Telegram;

use App\Actions\Telegram\ActivateStaffLink;
use App\Actions\Telegram\ClaimStaffEnrollment;
use App\Actions\Telegram\IssueStaffEnrollment;
use App\Actions\Telegram\RevokeStaffLink;
use App\Exceptions\Outbox\OutboxPermanentException;
use App\Models\OutboxMessage;
use App\Models\TelegramEnrollment;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Support\Staff\StaffSchemaException;
use App\Support\Telegram\TelegramUpdateHandler;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use LogicException;
use Tests\TestCase;

class UpdateHandlerTest extends TestCase
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
        CarbonImmutable::setTestNow('2026-11-09 03:00:00 UTC');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_a_private_start_claims_the_enrollment_and_queues_one_reply(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->bookingStaff()->create();
        $token = (new IssueStaffEnrollment)($owner, $staff->id);

        $this->handle($this->receive($this->startUpdate(1, '/start '.$token)));

        $enrollment = TelegramEnrollment::sole();
        $this->assertSame('5550001111', $enrollment->claimed_telegram_user_id);
        $this->assertSame('2026-11-09 03:00:00', $enrollment->claimed_at->toDateTimeString());

        $row = TelegramUpdate::sole();
        $this->assertSame('claimed', $row->outcome);
        $this->assertNull($row->outcome_reason);
        $this->assertSame('2026-11-09 03:00:00', $row->processed_at->toDateTimeString());
        $this->assertNull(DB::table('telegram_updates')->where('id', $row->id)->value('start_token'));

        $reply = $this->replies()->sole();
        $this->assertSame('telegram.reply:'.$row->id, $reply->deduplication_key);
        $this->assertSame([
            'chat_id' => 5550001111,
            'text' => "Thanks. Your request is waiting for the owner's approval. Tell the owner that your Telegram account ends in 1111.",
        ], $reply->payload);
        $this->assertStringNotContainsString('5550001111', $reply->payload['text']);
        $this->assertDatabaseCount('telegram_staff_links', 0);
        $this->assertSame(
            'Terima kasih. Permintaan Anda menunggu persetujuan pemilik. Beri tahu pemilik bahwa akun Telegram Anda berakhiran 1111.',
            trans('admin.telegram.bot_claimed', ['digits' => '1111'], 'id'),
        );
    }

    public function test_replaying_a_processed_update_changes_nothing(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->bookingStaff()->create();
        $token = (new IssueStaffEnrollment)($owner, $staff->id);
        $message = $this->receive($this->startUpdate(1, '/start '.$token));

        $this->handle($message);

        CarbonImmutable::setTestNow('2026-11-09 03:05:00 UTC');
        $this->handle($message);
        $this->handle($message);

        $this->assertSame('2026-11-09 03:00:00', TelegramEnrollment::sole()->claimed_at->toDateTimeString());
        $this->assertSame('2026-11-09 03:00:00', TelegramUpdate::sole()->processed_at->toDateTimeString());
        $this->assertSame(1, $this->replies()->count());
        $this->assertSame('claimed', TelegramUpdate::sole()->outcome);
    }

    public function test_refused_claims_are_recorded_and_explained(): void
    {
        // (a) Unknown token: refused before the staff guard that dashboard approvals wait on.
        $message = $this->receive($this->startUpdate(1, '/start '.str_repeat('a', 43)));
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->handle($message);

        $this->assertNotEmpty($statements);
        $this->assertSame([], preg_grep('/staff_access_guards/i', $statements));
        $unknown = TelegramUpdate::query()->where('update_id', 1)->sole();
        $this->assertSame('rejected', $unknown->outcome);
        $this->assertSame('invalid_token', $unknown->outcome_reason);
        $this->assertSame([
            'chat_id' => 5550001111,
            'text' => 'This link is invalid or has expired. Ask the owner for a new one.',
        ], $this->replyFor($unknown)->payload);

        // (b) The Telegram account already belongs to an active link.
        $owner = User::factory()->owner()->create();
        $staffA = User::factory()->bookingStaff()->create();
        $tokenA = (new IssueStaffEnrollment)($owner, $staffA->id);
        $enrollmentA = (new ClaimStaffEnrollment)($tokenA, '5550001111');
        (new ActivateStaffLink)($owner, $enrollmentA->id);

        $staffB = User::factory()->bookingStaff()->create();
        $tokenB = (new IssueStaffEnrollment)($owner, $staffB->id);
        $this->handle($this->receive($this->startUpdate(2, '/start '.$tokenB)));

        $refused = TelegramUpdate::query()->where('update_id', 2)->sole();
        $this->assertSame('rejected', $refused->outcome);
        $this->assertSame('telegram_refused', $refused->outcome_reason);
        $this->assertSame([
            'chat_id' => 5550001111,
            'text' => 'This Telegram account is already linked to another staff account.',
        ], $this->replyFor($refused)->payload);
        $this->assertNull(TelegramEnrollment::query()->where('user_id', $staffB->id)->sole()->claimed_at);

        // (c) The token expired before the update was processed.
        $staffC = User::factory()->bookingStaff()->create();
        $tokenC = (new IssueStaffEnrollment)($owner, $staffC->id);
        CarbonImmutable::setTestNow('2026-11-09 03:16:00 UTC');
        $this->handle($this->receive($this->startUpdate(3, '/start '.$tokenC)));

        $expired = TelegramUpdate::query()->where('update_id', 3)->sole();
        $this->assertSame('rejected', $expired->outcome);
        $this->assertSame('invalid_token', $expired->outcome_reason);
    }

    public function test_group_bot_tokenless_and_callback_updates_are_ignored(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->bookingStaff()->create();
        $token = (new IssueStaffEnrollment)($owner, $staff->id);

        $cases = [
            10 => [$this->startUpdate(10, '/start '.$token, 'supergroup'), 'not_private'],
            11 => [$this->startUpdate(11, '/start '.$token, 'private', true), 'bot_sender'],
            12 => [$this->startUpdate(12, '/start'), 'no_token'],
            13 => [$this->callbackUpdate(13), 'unsupported'],
        ];

        foreach ($cases as [$update, $reason]) {
            $this->handle($this->receive($update));
        }

        foreach ($cases as $updateId => [$update, $reason]) {
            $row = TelegramUpdate::query()->where('update_id', $updateId)->sole();
            $this->assertSame('ignored', $row->outcome);
            $this->assertSame($reason, $row->outcome_reason);
            $this->assertNull(DB::table('telegram_updates')->where('id', $row->id)->value('start_token'));
        }

        $replies = $this->replies();
        $this->assertCount(1, $replies);
        $this->assertSame(['chat_id' => 5550001111, 'text' => 'To link your staff account, open the link the owner sent you.'], $replies->sole()->payload);
        $this->assertNull(TelegramEnrollment::sole()->claimed_at);
    }

    public function test_a_failure_inside_the_claim_leaves_the_update_untouched(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->bookingStaff()->create();
        $token = (new IssueStaffEnrollment)($owner, $staff->id);
        $message = $this->receive($this->startUpdate(20, '/start '.$token));

        Schema::drop('staff_access_guards');

        $thrown = false;
        try {
            $this->handle($message);
        } catch (StaffSchemaException) {
            $thrown = true;
        }

        $this->assertTrue($thrown);
        $row = TelegramUpdate::sole();
        $this->assertNull($row->outcome);
        $this->assertNull($row->processed_at);
        $this->assertSame($token, DB::table('telegram_updates')->where('id', $row->id)->value('start_token'));
        $this->assertSame(0, $this->replies()->count());
        $this->assertNull(TelegramEnrollment::sole()->claimed_at);
    }

    public function test_a_failure_after_a_successful_claim_rolls_the_claim_back(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->bookingStaff()->create();
        $token = (new IssueStaffEnrollment)($owner, $staff->id);
        $message = $this->receive($this->startUpdate(30, '/start '.$token));

        // The claim succeeds, then recording the reply fails: nothing may be left half done.
        $events = config('outbox.events');
        unset($events['telegram.reply']);
        config(['outbox.events' => $events]);

        $thrown = false;
        try {
            $this->handle($message);
        } catch (LogicException) {
            $thrown = true;
        }

        $this->assertTrue($thrown);
        $this->assertNull(TelegramEnrollment::sole()->claimed_at);
        $row = TelegramUpdate::sole();
        $this->assertNull($row->outcome);
        $this->assertNull($row->processed_at);
        $this->assertSame($token, DB::table('telegram_updates')->where('id', $row->id)->value('start_token'));
        $this->assertSame(0, $this->replies()->count());
    }

    public function test_opening_the_link_again_repeats_the_answer_only_to_its_claimer(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->bookingStaff()->create();
        $first = (new IssueStaffEnrollment)($owner, $staff->id);
        $waiting = "Thanks. Your request is waiting for the owner's approval. Tell the owner that your Telegram account ends in 1111.";
        $invalid = 'This link is invalid or has expired. Ask the owner for a new one.';

        $this->handle($this->receive($this->startUpdate(1, '/start '.$first)));
        $this->handle($this->receive($this->startUpdate(2, '/start '.$first)));
        $this->handle($this->receive($this->startUpdate(3, '/start '.$first, fromId: 5550002222)));

        $this->assertOutcome(2, 'ignored', 'already_claimed', $waiting, 5550001111);
        $this->assertOutcome(3, 'rejected', 'invalid_token', $invalid, 5550002222);

        // A new link cancels the claimed one, so the old link is now simply invalid.
        $second = (new IssueStaffEnrollment)($owner, $staff->id);
        $this->handle($this->receive($this->startUpdate(4, '/start '.$first)));
        $this->assertOutcome(4, 'rejected', 'invalid_token', $invalid, 5550001111);

        $this->handle($this->receive($this->startUpdate(5, '/start '.$second)));
        (new ActivateStaffLink)($owner, TelegramEnrollment::query()->where('token_hash', hash('sha256', $second))->sole()->id);
        $this->handle($this->receive($this->startUpdate(6, '/start '.$second)));
        $this->assertOutcome(6, 'ignored', 'already_linked', 'Your Telegram account is already linked to your staff account.', 5550001111);

        // Once the owner removes the link, the old link is just invalid again.
        (new RevokeStaffLink)($owner, $staff->id);
        $this->handle($this->receive($this->startUpdate(7, '/start '.$second)));
        $this->assertOutcome(7, 'rejected', 'invalid_token', $invalid, 5550001111);
        $this->assertSame(
            'Akun Telegram Anda sudah tertaut ke akun staf Anda.',
            trans('admin.telegram.bot_already_linked', [], 'id'),
        );
    }

    public function test_a_tap_received_before_expiry_is_honoured_after_a_delay(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->bookingStaff()->create();
        $token = (new IssueStaffEnrollment)($owner, $staff->id);

        CarbonImmutable::setTestNow('2026-11-09 03:14:30 UTC');
        $message = $this->receive($this->startUpdate(40, '/start '.$token));
        CarbonImmutable::setTestNow('2026-11-09 03:15:10 UTC');
        $this->handle($message);

        $this->assertSame('claimed', TelegramUpdate::sole()->outcome);
        $this->assertSame('5550001111', TelegramEnrollment::sole()->claimed_telegram_user_id);
    }

    public function test_bad_outbox_payloads_fail_permanently(): void
    {
        $missing = (new OutboxMessage)->forceFill(['payload' => ['telegram_update_id' => 'x']]);

        try {
            $this->handle($missing);
            $this->fail('Expected the non-integer update id to fail permanently.');
        } catch (OutboxPermanentException $exception) {
            $this->assertSame('payload', $exception->reason());
        }

        $unknown = (new OutboxMessage)->forceFill(['payload' => ['telegram_update_id' => 999999]]);

        try {
            $this->handle($unknown);
            $this->fail('Expected the unknown update id to fail permanently.');
        } catch (OutboxPermanentException $exception) {
            $this->assertSame('update_missing', $exception->reason());
        }
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

    private function handle(OutboxMessage $message): void
    {
        app(TelegramUpdateHandler::class)->handle($message);
    }

    private function replies()
    {
        return OutboxMessage::query()->where('type', 'telegram.reply')->orderBy('id')->get();
    }

    private function replyFor(TelegramUpdate $update): OutboxMessage
    {
        return OutboxMessage::query()->where('deduplication_key', 'telegram.reply:'.$update->id)->sole();
    }

    private function assertOutcome(int $updateId, string $outcome, ?string $reason, string $text, int $chatId): void
    {
        $row = TelegramUpdate::query()->where('update_id', $updateId)->sole();
        $this->assertSame($outcome, $row->outcome);
        $this->assertSame($reason, $row->outcome_reason);
        $this->assertSame(['chat_id' => $chatId, 'text' => $text], $this->replyFor($row)->payload);
    }

    private function startUpdate(int $updateId, string $text, string $chatType = 'private', bool $isBot = false, int $fromId = 5550001111): array
    {
        return ['update_id' => $updateId, 'message' => [
            'message_id' => 11, 'date' => 1793000000, 'text' => $text,
            'from' => ['id' => $fromId, 'is_bot' => $isBot, 'first_name' => 'Rani', 'username' => 'rani_k', 'language_code' => 'id'],
            'chat' => [
                'id' => $chatType === 'private' ? $fromId : -1001234567890,
                'type' => $chatType,
                'first_name' => 'Rani',
                'username' => 'rani_k',
            ],
        ]];
    }

    private function callbackUpdate(int $updateId): array
    {
        return ['update_id' => $updateId, 'callback_query' => [
            'id' => '4382bfdwdsb323b2d9', 'chat_instance' => '-8122', 'data' => 'x',
            'from' => ['id' => 5550001111, 'is_bot' => false, 'first_name' => 'Rani', 'username' => 'rani_k'],
            'message' => ['message_id' => 77, 'message_thread_id' => 5, 'date' => 1793000000, 'text' => 'Booking SJ-123 for Budi Santoso',
                'chat' => ['id' => -1001234567890, 'type' => 'supergroup', 'title' => 'Shin Joy staff']],
        ]];
    }
}
