<?php

namespace Tests\Concurrency;

use App\Models\Booking;
use App\Models\OutboxMessage;
use App\Models\TelegramActionToken;
use App\Models\TelegramBookingMessage;
use App\Models\TelegramStaffLink;
use App\Models\User;
use App\Support\Telegram\TelegramUpdateRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Scheduling\RoomAllocationTest;

/**
 * On SQLite the database-wide write lock serializes each race at the second worker's first write, so a green SQLite run
 * proves the outcomes and that every worker went through the staff guard, but not the guard's row lock or the locking
 * reads. Only a run against MySQL (CONCURRENCY_DB_*) proves those.
 */
class TelegramRaceTest extends ConcurrencyTestCase
{
    public static function lastRoomRaces(): array
    {
        // A refused dashboard approval rolls its guard increment back with its own transaction, so when Telegram runs
        // first only the Telegram worker's increment survives.
        return [
            'telegram then dashboard' => ['telegram', 'approve', 'approved/', 'unavailable', 'telegram', 1],
            'dashboard then telegram' => ['approve', 'telegram', 'confirmed', 'conflict/room', 'dashboard', 2],
        ];
    }

    #[DataProvider('lastRoomRaces')]
    public function test_telegram_and_dashboard_approvals_contend_for_the_last_room(
        string $first,
        string $second,
        string $firstOut,
        string $secondOut,
        string $channel,
        int $revisions,
    ): void {
        [$a, $b, $staff] = $this->scene();
        $before = $this->guardRevision();

        $firstId = $first === 'telegram' ? $this->press($a, 1) : $a->id;
        $secondId = $second === 'telegram' ? $this->press($b, 1) : $b->id;

        [$firstOutput, $secondOutput] = $this->race($first, $firstId, $second, $secondId);

        $this->assertSame($firstOut, $firstOutput);
        $this->assertSame($secondOut, $secondOutput);
        $this->assertSame('confirmed', DB::table('bookings')->where('id', $a->id)->value('status'));
        $this->assertSame('pending', DB::table('bookings')->where('id', $b->id)->value('status'));
        $this->assertSame(1, DB::table('room_reservations')->count());
        $this->assertSame($a->id, (int) DB::table('room_reservations')->value('booking_id'));
        $this->assertSame(1, DB::table('booking_status_changes')->count());
        $this->assertSame($a->id, (int) DB::table('booking_status_changes')->value('booking_id'));
        $this->assertSame($channel, DB::table('booking_status_changes')->value('channel'));
        // The press acts as the linked staff member, and the first dashboard worker is that same first admin.
        $this->assertSame($staff->id, (int) DB::table('booking_status_changes')->value('admin_user_id'));
        $this->assertSame($before + $revisions, $this->guardRevision());

        if ($first === 'telegram') {
            $token = DB::table('telegram_action_tokens')->where('booking_id', $a->id)->where('action', 'approve')->first();
            $this->assertNotNull($token->consumed_at);
            $this->assertSame('approved', $token->result);
            $this->assertSame(
                ['callback_query_id' => 'cbq-1', 'show_alert' => false, 'text' => 'Booking confirmed.'],
                $this->answerFor($firstId),
            );
        } else {
            $token = DB::table('telegram_action_tokens')->where('booking_id', $b->id)->where('action', 'approve')->first();
            $this->assertNull($token->consumed_at);
            $answer = $this->answerFor($secondId);
            $this->assertSame(__('admin.telegram.callback.room'), $answer['text']);
            $this->assertTrue($answer['show_alert']);
        }
    }

    public static function revocationRaces(): array
    {
        return [
            'link removed, then the press' => ['unlink', 'telegram', 'unlinked', 'denied/not_linked', 'pending'],
            'press, then the link removed' => ['telegram', 'unlink', 'approved/', 'unlinked', 'confirmed'],
            'access removed, then the press' => ['unpermit', 'telegram', 'unpermitted', 'denied/not_authorized', 'pending'],
            'press, then access removed' => ['telegram', 'unpermit', 'approved/', 'unpermitted', 'confirmed'],
        ];
    }

    #[DataProvider('revocationRaces')]
    public function test_revocation_and_a_callback_are_serialized(
        string $first,
        string $second,
        string $firstOut,
        string $secondOut,
        string $status,
    ): void {
        [$a, , $staff] = $this->scene();
        $before = $this->guardRevision();
        $press = $this->press($a, 1);

        [$firstOutput, $secondOutput] = $this->race(
            $first,
            $first === 'telegram' ? $press : $staff->id,
            $second,
            $second === 'telegram' ? $press : $staff->id,
        );

        $this->assertSame($firstOut, $firstOutput);
        $this->assertSame($secondOut, $secondOutput);
        $this->assertSame($status, DB::table('bookings')->where('id', $a->id)->value('status'));
        $changes = $status === 'confirmed' ? 1 : 0;
        $this->assertSame($changes, DB::table('booking_status_changes')->count());
        $this->assertSame($changes, DB::table('room_reservations')->count());
        if ($status === 'confirmed') {
            $this->assertSame($staff->id, (int) DB::table('booking_status_changes')->value('admin_user_id'));
        }
        $token = DB::table('telegram_action_tokens')->where('booking_id', $a->id)->where('action', 'approve')->first();
        $this->assertSame($status === 'confirmed', $token->consumed_at !== null);
        $this->assertSame($before + 2, $this->guardRevision());

        // The staff member who pressed hears why nothing happened.
        if ($status === 'pending') {
            $answer = $this->answerFor($press);
            $this->assertSame(__('admin.telegram.callback.'.explode('/', $secondOut)[1]), $answer['text']);
            $this->assertTrue($answer['show_alert']);
        }

        if (in_array('unlink', [$first, $second], true)) {
            $this->assertSame(0, DB::table('telegram_staff_links')->count());
            $this->assertSame(1, DB::table('telegram_staff_link_changes')->where('action', 'revoked')->count());
        }

        if (in_array('unpermit', [$first, $second], true)) {
            $this->assertFalse((bool) DB::table('users')->where('id', $staff->id)->value('can_manage_bookings'));
            $this->assertSame(1, DB::table('staff_permission_changes')->where('target_user_id', $staff->id)->where('is_allowed', false)->count());
            $this->assertSame(1, DB::table('telegram_staff_links')->count());
        }
    }

    public function test_a_double_tap_across_workers_acts_once(): void
    {
        [$a] = $this->scene();
        $before = $this->guardRevision();
        $one = $this->press($a, 1);
        $two = $this->press($a, 2);

        [$firstOutput, $secondOutput] = $this->race('telegram', $one, 'telegram', $two);

        $this->assertSame('approved/', $firstOutput);
        $this->assertSame('ignored/used', $secondOutput);
        $this->assertSame('confirmed', DB::table('bookings')->where('id', $a->id)->value('status'));
        $this->assertSame(1, DB::table('booking_status_changes')->count());
        $this->assertSame('telegram', DB::table('booking_status_changes')->value('channel'));
        $this->assertSame(1, DB::table('room_reservations')->count());
        $this->assertSame('approved', DB::table('telegram_action_tokens')->where('booking_id', $a->id)->value('result'));
        $this->assertSame(
            ['callback_query_id' => 'cbq-1', 'show_alert' => false, 'text' => 'Booking confirmed.'],
            $this->answerFor($one),
        );
        $this->assertSame(
            ['callback_query_id' => 'cbq-2', 'show_alert' => false, 'text' => 'This button was already used to confirm the booking.'],
            $this->answerFor($two),
        );
        $this->assertSame($before + 2, $this->guardRevision());
    }

    public function test_a_dashboard_approval_beats_a_waiting_decline_prompt(): void
    {
        [$a] = $this->scene();
        $before = $this->guardRevision();
        $press = $this->press($a, 1, 'decline_prompt');

        [$firstOutput, $secondOutput] = $this->race('approve', $a->id, 'telegram', $press);

        $this->assertSame('confirmed', $firstOutput);
        $this->assertSame('stale/changed', $secondOutput);
        $this->assertSame('confirmed', DB::table('bookings')->where('id', $a->id)->value('status'));
        $this->assertSame(0, DB::table('telegram_action_tokens')->where('action', 'decline_confirm')->count());
        $this->assertSame(0, DB::table('outbox_messages')->where('type', 'telegram.decline.prompt')->count());
        $answer = $this->answerFor($press);
        $this->assertSame(__('admin.telegram.callback.changed'), $answer['text']);
        $this->assertTrue($answer['show_alert']);
        $this->assertSame($before + 2, $this->guardRevision());
    }

    /** @return array{0: Booking, 1: Booking, 2: User} two bookings that want the same last room, and the linked staff member */
    private function scene(): array
    {
        [$a] = (new RoomAllocationTest('fixture'))->fixture();
        $a->forceFill(['starts_at' => '2026-10-25 03:00:00'])->save();
        $b = $a->replicate(['reference', 'submission_key']);
        $b->reference = 'contender';
        $b->submission_key = 'contender';
        $b->save();
        User::factory()->owner()->create();
        $staff = User::factory()->bookingStaff()->create();
        User::factory()->bookingStaff()->create();   // the dashboard worker in role "second" uses the second admin
        TelegramStaffLink::query()->create([
            'user_id' => $staff->id,
            'telegram_user_id' => '5550001111',
            'activated_at' => CarbonImmutable::now('UTC'),
        ]);

        return [$a->fresh(), $b->fresh(), $staff];
    }

    /** Records a press of the booking's button and returns its telegram.update.received outbox message ID. */
    private function press(Booking $booking, int $updateId, string $action = 'approve'): int
    {
        $nonce = substr(hash('sha256', $action.$booking->id), 0, 43);   // the same booking and action give the same button
        $row = TelegramBookingMessage::query()->firstOrCreate(
            ['booking_id' => $booking->id, 'chat_id' => '-1001234567890'],
            ['message_id' => 123, 'booking_version' => 1, 'state' => 'sent'],
        );
        TelegramActionToken::query()->firstOrCreate(['nonce_hash' => hash('sha256', $nonce)], [
            'telegram_booking_message_id' => $row->id,
            'booking_id' => $booking->id,
            'booking_version' => 1,
            'action' => $action,
            'expires_at' => '2026-10-25 03:00:00',
        ]);
        app(TelegramUpdateRecorder::class)->record('123456789', ['update_id' => $updateId, 'callback_query' => [
            'id' => 'cbq-'.$updateId, 'chat_instance' => '-8122', 'data' => 't:'.$nonce,
            'from' => ['id' => 5550001111, 'is_bot' => false, 'first_name' => 'Rani'],
            'message' => ['message_id' => 123, 'date' => 1793000000, 'text' => 'NEW BOOKING',
                'chat' => ['id' => -1001234567890, 'type' => 'supergroup', 'title' => 'Shin Joy staff']],
        ]]);

        return (int) OutboxMessage::query()->where('type', 'telegram.update.received')->orderByDesc('id')->value('id');
    }

    /** @return array{0: string, 1: string} the first and second workers' output */
    private function race(string $first, int $firstId, string $second, int $secondId): array
    {
        $this->worker($first, 'first', $firstId);
        $this->worker($second, 'second', $secondId);
        file_put_contents($this->directory.'/first-go', 'go');
        ConcurrencyDatabase::wait($this->directory.'/first-acquired', $this->workers['first']);
        file_put_contents($this->directory.'/second-go', 'go');
        ConcurrencyDatabase::wait($this->directory.'/second-attempting', $this->workers['second']);
        usleep(150000);
        $this->assertTrue($this->workers['second']->isRunning());
        $this->assertFileDoesNotExist($this->directory.'/second-acquired');
        file_put_contents($this->directory.'/release-first', 'release');

        foreach ($this->workers as $worker) {
            $this->assertSame(0, $worker->wait(), $worker->getErrorOutput().$worker->getOutput());
        }

        $this->assertFileExists($this->directory.'/second-acquired');

        return [$this->workers['first']->getOutput(), $this->workers['second']->getOutput()];
    }

    private function guardRevision(): int
    {
        return (int) DB::table('staff_access_guards')->where('id', 1)->value('revision');
    }

    /** @return array<string, mixed>|null the answer recorded for the press behind this outbox message */
    private function answerFor(int $outboxId): ?array
    {
        $updateId = OutboxMessage::query()->findOrFail($outboxId)->payload['telegram_update_id'];

        return OutboxMessage::query()->where('deduplication_key', 'telegram.callback.answer:'.$updateId)->first()?->payload;
    }
}
