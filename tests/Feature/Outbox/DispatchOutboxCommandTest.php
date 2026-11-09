<?php

namespace Tests\Feature\Outbox;

use App\Enums\OutboxDeliveryState;
use App\Jobs\DeliverOutboxMessage;
use App\Models\OutboxDelivery;
use App\Models\OutboxMessage;
use App\Support\Outbox\OutboxWriter;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Outbox\FakeOutboxHandler;
use Tests\TestCase;

require_once __DIR__.'/../../fixtures/outbox/FakeOutboxHandler.php';

class DispatchOutboxCommandTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00:00'));

        FakeOutboxHandler::$handled = [];
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    public function test_the_default_limit_dispatches_every_due_delivery(): void
    {
        Queue::fake();
        $now = now()->toImmutable();
        $first = $this->createDelivery('default-first', $now->subMinute());
        $second = $this->createDelivery('default-second', $now->subMinute());

        $this->artisan('outbox:dispatch')
            ->expectsOutput('Dispatched 2 outbox deliveries.')
            ->assertExitCode(0);

        $jobs = Queue::pushed(DeliverOutboxMessage::class)->values();
        $this->assertCount(2, $jobs);
        $this->assertSame(
            [$first->id, $second->id],
            $jobs->map(static fn (DeliverOutboxMessage $job): int => $job->deliveryId)->all(),
        );

        foreach ($jobs as $job) {
            $delivery = $job->deliveryId === $first->id ? $first->refresh() : $second->refresh();
            $this->assertSame(OutboxDeliveryState::Leased, $delivery->state);
            $this->assertSame($delivery->lease_token, $job->leaseToken);
        }
    }

    public function test_an_explicit_limit_claims_only_the_first_eligible_delivery(): void
    {
        Queue::fake();
        $now = now()->toImmutable();
        $first = $this->createDelivery('limit-first', $now->subMinute());
        $second = $this->createDelivery('limit-second', $now->subMinute());
        $third = $this->createDelivery('limit-third', $now->subMinute());

        $this->artisan('outbox:dispatch', ['--limit' => '1'])
            ->expectsOutput('Dispatched 1 outbox deliveries.')
            ->assertExitCode(0);

        $this->assertSame(OutboxDeliveryState::Leased, $first->refresh()->state);
        $this->assertSame(OutboxDeliveryState::Pending, $second->refresh()->state);
        $this->assertSame(OutboxDeliveryState::Pending, $third->refresh()->state);

        $jobs = Queue::pushed(DeliverOutboxMessage::class)->values();
        $this->assertCount(1, $jobs);
        $this->assertSame($first->id, $jobs->first()->deliveryId);
        $this->assertSame($first->lease_token, $jobs->first()->leaseToken);
    }

    public function test_invalid_limits_fail_without_touching_the_database_or_queue(): void
    {
        Queue::fake();
        $this->createDelivery('invalid-limit');
        $before = DB::table('outbox_deliveries')->orderBy('id')->get();

        foreach (['0', '1001', 'abc', '1.5', ''] as $limit) {
            $this->artisan('outbox:dispatch', ['--limit' => $limit])
                ->expectsOutput('The --limit option must be an integer between 1 and 1000.')
                ->assertExitCode(1);

            $this->assertEquals($before, DB::table('outbox_deliveries')->orderBy('id')->get());
            $this->assertCount(0, Queue::pushed(DeliverOutboxMessage::class));
        }
    }

    public function test_the_command_recovers_expired_leases_without_a_new_booking(): void
    {
        Queue::fake();
        $now = now()->toImmutable();
        $recoverable = $this->createDelivery('recoverable');
        $oldToken = $this->token();
        $this->updateDelivery($recoverable, [
            'state' => 'leased',
            'attempts' => 1,
            'lease_token' => $oldToken,
            'lease_expires_at' => $now->subSecond(),
        ]);
        $exhausted = $this->createDelivery('exhausted');
        $this->updateDelivery($exhausted, [
            'state' => 'leased',
            'attempts' => 5,
            'lease_token' => $this->token(),
            'lease_expires_at' => $now->subSecond(),
        ]);

        $this->artisan('outbox:dispatch')
            ->expectsOutput('Dispatched 1 outbox deliveries.')
            ->assertExitCode(0);

        $recoverable->refresh();
        $this->assertSame(OutboxDeliveryState::Leased, $recoverable->state);
        $this->assertNotSame($oldToken, $recoverable->lease_token);
        $this->assertSame(1, $recoverable->attempts);
        $this->assertSame(
            $now->addSeconds(300)->format('Y-m-d H:i:s'),
            $recoverable->lease_expires_at->format('Y-m-d H:i:s'),
        );

        $jobs = Queue::pushed(DeliverOutboxMessage::class)->values();
        $this->assertCount(1, $jobs);
        $this->assertSame($recoverable->id, $jobs->first()->deliveryId);
        $this->assertSame($recoverable->lease_token, $jobs->first()->leaseToken);

        $exhausted->refresh();
        $this->assertSame(OutboxDeliveryState::Failed, $exhausted->state);
        $this->assertSame('2026-11-03 10:00:00', $exhausted->failed_at->format('Y-m-d H:i:s'));
        $this->assertSame('retryable: lease expired after max attempts', $exhausted->last_error);
        $this->assertSame(5, $exhausted->attempts);
        $this->assertNull($exhausted->lease_token);
        $this->assertNull($exhausted->lease_expires_at);
    }

    public function test_the_real_sync_queue_completes_a_due_delivery(): void
    {
        $delivery = $this->createDelivery('sync-queue');

        $this->artisan('outbox:dispatch')
            ->expectsOutput('Dispatched 1 outbox deliveries.')
            ->assertExitCode(0);

        $delivery->refresh();
        $this->assertSame(OutboxDeliveryState::Completed, $delivery->state);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame([$delivery->message_id], FakeOutboxHandler::$handled);
    }

    public function test_nothing_due_dispatches_zero_and_pushes_nothing(): void
    {
        Queue::fake();

        $this->artisan('outbox:dispatch')
            ->expectsOutput('Dispatched 0 outbox deliveries.')
            ->assertExitCode(0);

        $this->assertCount(0, Queue::pushed(DeliverOutboxMessage::class));
    }

    public function test_the_schedule_registers_one_overlap_guarded_ten_second_command(): void
    {
        $this->app->make(ConsoleKernel::class)->bootstrap();

        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(static fn ($event): bool => str_contains((string) $event->command, 'outbox:dispatch --limit=100'));

        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events->first()->expression);
        $this->assertSame(10, $events->first()->repeatSeconds);
        // A 10-second task keeps schedule:run looping all minute; without this it reads the
        // pause and interrupt flags from the cache store every 100 ms.
        $this->assertFalse(Schedule::$pausable);
        $this->assertFalse(Schedule::$interruptible);
        $this->assertTrue($events->first()->withoutOverlapping);
        // A killed run must not hold the mutex (default 24h) and silently stop recovery.
        $this->assertSame(5, $events->first()->expiresAt);
    }

    public function test_the_default_limit_is_100_and_one_run_makes_exactly_one_batch(): void
    {
        Queue::fake();
        $this->createDelivery('single-batch');
        $selects = $this->countCandidateSelects();

        $this->artisan('outbox:dispatch')->assertExitCode(0);

        $limit = $this->app->make(ConsoleKernel::class)->all()['outbox:dispatch']->getDefinition()->getOption('limit');
        $this->assertSame('100', $limit->getDefault());
        $this->assertSame(1, $selects->count);
    }

    public function test_an_invalid_limit_never_reaches_the_dispatcher(): void
    {
        $this->createDelivery('never-dispatched');
        $selects = $this->countCandidateSelects();

        $this->artisan('outbox:dispatch', ['--limit' => '0'])->assertExitCode(1);

        $this->assertSame(0, $selects->count);
    }

    // ponytail: OutboxDispatcher is final, so dispatcher calls are counted by its
    // one candidate SELECT per batch instead of a mock.
    private function countCandidateSelects(): object
    {
        $counter = new class
        {
            public int $count = 0;
        };

        DB::listen(static function ($query) use ($counter): void {
            if (stripos(ltrim($query->sql), 'select') === 0 && str_contains($query->sql, 'outbox_deliveries')) {
                $counter->count++;
            }
        });

        return $counter;
    }

    private function createDelivery(string $key, ?CarbonInterface $availableAt = null): OutboxDelivery
    {
        config(['outbox.events' => ['booking.created' => ['primary' => FakeOutboxHandler::class]]]);

        $message = DB::transaction(fn (): OutboxMessage => app(OutboxWriter::class)->record(
            'booking.created',
            $key,
            ['booking_id' => 1],
        ));

        $delivery = $message->deliveries()->where('handler_key', 'primary')->firstOrFail();

        if ($availableAt instanceof CarbonInterface) {
            $this->updateDelivery($delivery, ['available_at' => $availableAt]);
        }

        return $delivery;
    }

    /** @param array<string, mixed> $attributes */
    private function updateDelivery(OutboxDelivery $delivery, array $attributes): void
    {
        DB::table('outbox_deliveries')->where('id', $delivery->id)->update($attributes);
        $delivery->refresh();
    }

    private function token(): string
    {
        return bin2hex(random_bytes(32));
    }
}
