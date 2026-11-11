<?php

namespace Tests\Feature\Outbox;

use App\Enums\OutboxDeliveryState;
use App\Models\OutboxDelivery;
use App\Models\OutboxMessage;
use App\Support\Outbox\OutboxWriter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Fixtures\Outbox\FakeOutboxHandler;
use Tests\TestCase;

require_once __DIR__.'/../../fixtures/outbox/FakeOutboxHandler.php';

class RequeueOutboxDeliveriesCommandTest extends TestCase
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

    public function test_a_failed_delivery_is_requeued_with_a_fresh_retry_budget(): void
    {
        $delivery = $this->createFailedDelivery('happy-path', 'permanent: Telegram token rejected');
        // Non-null lease values so the lease-clearing assertions below are meaningful.
        $this->updateDelivery($delivery, [
            'lease_token' => str_repeat('a', 64),
            'lease_expires_at' => now()->subMinute(),
        ]);

        $this->artisan('outbox:requeue', ['ids' => [(string) $delivery->id], '--yes' => true])
            ->expectsOutputToContain("#{$delivery->id}: primary, attempts 5, last error: permanent: Telegram token rejected")
            ->expectsOutput('Requeued 1 of 1 deliveries.')
            ->assertExitCode(0);

        $delivery->refresh();
        $this->assertSame(OutboxDeliveryState::Retry, $delivery->state);
        $this->assertSame(0, $delivery->attempts);
        $this->assertSame('2026-11-03 10:00:00', $delivery->available_at->format('Y-m-d H:i:s'));
        $this->assertNull($delivery->lease_token);
        $this->assertNull($delivery->lease_expires_at);
        $this->assertNull($delivery->failed_at);
        $this->assertNull($delivery->last_error);
        $this->assertSame('2026-11-03 10:00:00', $delivery->updated_at->format('Y-m-d H:i:s'));
    }

    public function test_deliveries_in_other_states_are_reported_and_left_untouched(): void
    {
        foreach (['pending', 'leased', 'retry', 'completed'] as $state) {
            $delivery = $this->createDelivery("other-{$state}");
            $this->updateDelivery($delivery, ['state' => $state]);
            $before = DB::table('outbox_deliveries')->where('id', $delivery->id)->first();

            $this->artisan('outbox:requeue', ['ids' => [(string) $delivery->id], '--yes' => true])
                ->expectsOutputToContain("{$state}, not failed")
                ->expectsOutputToContain('No failed deliveries to requeue.')
                ->assertExitCode(1);

            $this->assertEquals($before, DB::table('outbox_deliveries')->where('id', $delivery->id)->first());
        }
    }

    public function test_a_skipped_delivery_is_final_and_cannot_be_requeued(): void
    {
        $delivery = $this->createDelivery('skipped-final');
        $this->updateDelivery($delivery, ['state' => 'skipped', 'skipped_at' => now(), 'last_error' => 'skipped: disabled']);
        $before = DB::table('outbox_deliveries')->where('id', $delivery->id)->first();

        $this->artisan('outbox:requeue', ['ids' => [(string) $delivery->id], '--yes' => true])
            ->expectsOutputToContain('skipped, not failed')
            ->expectsOutputToContain('No failed deliveries to requeue.')
            ->assertExitCode(1);

        $this->assertEquals($before, DB::table('outbox_deliveries')->where('id', $delivery->id)->first());
    }

    public function test_a_mixed_request_requeues_only_the_failed_delivery(): void
    {
        $failed = $this->createFailedDelivery('mixed-failed', 'permanent: mixed');
        $completed = $this->createDelivery('mixed-completed');
        $this->updateDelivery($completed, ['state' => 'completed']);
        $completedBefore = DB::table('outbox_deliveries')->where('id', $completed->id)->first();
        $missing = 999999;

        $this->artisan('outbox:requeue', [
            'ids' => [(string) $failed->id, (string) $completed->id, (string) $missing],
            '--yes' => true,
        ])
            ->expectsOutputToContain("#{$missing}: not found")
            ->expectsOutputToContain('completed, not failed')
            ->expectsOutput('Requeued 1 of 3 deliveries.')
            ->assertExitCode(1);

        $this->assertSame(OutboxDeliveryState::Retry, $failed->refresh()->state);
        $this->assertSame(0, $failed->attempts);
        $this->assertEquals($completedBefore, DB::table('outbox_deliveries')->where('id', $completed->id)->first());
    }

    public function test_invalid_ids_are_rejected_without_touching_the_database(): void
    {
        $delivery = $this->createFailedDelivery('invalid-input', 'permanent: invalid');
        $before = DB::table('outbox_deliveries')->orderBy('id')->get();

        foreach (['abc', '0', '-1', '1.5', "12\n", '12345678901234567890'] as $invalid) {
            $this->artisan('outbox:requeue', ['ids' => [$invalid], '--yes' => true])
                ->expectsOutputToContain('Invalid delivery ID [')
                ->assertExitCode(1);

            $this->assertEquals($before, DB::table('outbox_deliveries')->orderBy('id')->get());
        }

        $this->artisan('outbox:requeue', [
            'ids' => array_map('strval', range(1, 101)),
            '--yes' => true,
        ])
            ->expectsOutputToContain('Requeue at most 100 deliveries at a time.')
            ->assertExitCode(1);

        $this->assertEquals($before, DB::table('outbox_deliveries')->orderBy('id')->get());
        $this->assertSame(OutboxDeliveryState::Failed, $delivery->refresh()->state);
    }

    public function test_duplicate_ids_are_treated_as_one_delivery(): void
    {
        $delivery = $this->createFailedDelivery('duplicates', 'permanent: duplicates');
        $id = (string) $delivery->id;

        $this->artisan('outbox:requeue', ['ids' => [$id, $id, $id], '--yes' => true])
            ->expectsOutput('Requeued 1 of 1 deliveries.')
            ->assertExitCode(0);

        $this->assertSame(OutboxDeliveryState::Retry, $delivery->refresh()->state);
    }

    public function test_duplicates_are_removed_before_the_100_id_cap(): void
    {
        $delivery = $this->createFailedDelivery('dedupe-cap', 'permanent: dedupe');
        $ids = array_map('strval', range($delivery->id, $delivery->id + 99));

        $this->artisan('outbox:requeue', ['ids' => [...$ids, (string) $delivery->id], '--yes' => true])
            ->doesntExpectOutputToContain('Requeue at most 100 deliveries at a time.')
            ->expectsOutput('Requeued 1 of 100 deliveries.')
            ->assertExitCode(1);

        $this->assertSame(OutboxDeliveryState::Retry, $delivery->refresh()->state);
    }

    public function test_declining_the_confirmation_changes_nothing(): void
    {
        $delivery = $this->createFailedDelivery('declined', 'permanent: declined');
        $before = DB::table('outbox_deliveries')->where('id', $delivery->id)->first();

        $this->artisan('outbox:requeue', ['ids' => [(string) $delivery->id]])
            ->expectsConfirmation('Requeue 1 failed deliveries? They may send their notifications again.', 'no')
            ->expectsOutputToContain('No changes were made.')
            ->assertExitCode(0);

        $this->assertEquals($before, DB::table('outbox_deliveries')->where('id', $delivery->id)->first());
    }

    public function test_accepting_the_confirmation_requeues_the_delivery(): void
    {
        $delivery = $this->createFailedDelivery('accepted', 'permanent: accepted');

        $this->artisan('outbox:requeue', ['ids' => [(string) $delivery->id]])
            ->expectsConfirmation('Requeue 1 failed deliveries? They may send their notifications again.', 'yes')
            ->expectsOutput('Requeued 1 of 1 deliveries.')
            ->assertExitCode(0);

        $this->assertSame(OutboxDeliveryState::Retry, $delivery->refresh()->state);
    }

    public function test_a_delivery_that_changes_state_before_the_write_is_not_overwritten(): void
    {
        $delivery = $this->createFailedDelivery('compare-and-set', 'permanent: raced');
        $flipped = false;

        DB::beforeExecuting(function (string $query) use ($delivery, &$flipped): void {
            if ($flipped || stripos(ltrim($query), 'update') !== 0 || ! str_contains($query, 'outbox_deliveries')) {
                return;
            }

            $flipped = true;
            DB::table('outbox_deliveries')->where('id', $delivery->id)->update([
                'state' => OutboxDeliveryState::Completed->value,
                'completed_at' => now(),
            ]);
        });

        $this->artisan('outbox:requeue', ['ids' => [(string) $delivery->id], '--yes' => true])
            ->expectsOutputToContain('changed state, not requeued')
            ->expectsOutput('Requeued 0 of 1 deliveries.')
            ->assertExitCode(1);

        $this->assertTrue($flipped);
        $delivery->refresh();
        $this->assertSame(OutboxDeliveryState::Completed, $delivery->state);
        $this->assertSame(5, $delivery->attempts);
        $this->assertSame('permanent: raced', $delivery->last_error);
    }

    public function test_each_requeue_writes_one_audit_log_entry_without_a_payload(): void
    {
        Log::spy();
        $delivery = $this->createFailedDelivery('audit-log', 'permanent: audit');
        $id = $delivery->id;

        $this->artisan('outbox:requeue', ['ids' => [(string) $id], '--yes' => true])
            ->assertExitCode(0);

        Log::shouldHaveReceived('info')
            ->with('Outbox delivery requeued.', Mockery::on(
                static fn (array $context): bool => $context === [
                    'delivery_id' => $id,
                    'handler_key' => 'primary',
                    'previous_error' => 'permanent: audit',
                ]
            ))
            ->once();
        Log::shouldHaveReceived('info')->once();
    }

    public function test_a_requeued_delivery_is_dispatched_to_completion_on_the_real_queue(): void
    {
        $delivery = $this->createFailedDelivery('end-to-end', 'permanent: end-to-end');

        $this->artisan('outbox:requeue', ['ids' => [(string) $delivery->id], '--yes' => true])
            ->expectsOutput('Requeued 1 of 1 deliveries.')
            ->assertExitCode(0);

        $this->artisan('outbox:dispatch')
            ->expectsOutput('Dispatched 1 outbox deliveries.')
            ->assertExitCode(0);

        $delivery->refresh();
        $this->assertSame(OutboxDeliveryState::Completed, $delivery->state);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame([$delivery->message_id], FakeOutboxHandler::$handled);
    }

    private function createFailedDelivery(string $key, string $lastError): OutboxDelivery
    {
        $delivery = $this->createDelivery($key);
        $this->updateDelivery($delivery, [
            'state' => OutboxDeliveryState::Failed->value,
            'attempts' => 5,
            'lease_token' => null,
            'lease_expires_at' => null,
            'failed_at' => now(),
            'last_error' => $lastError,
        ]);

        return $delivery;
    }

    private function createDelivery(string $key): OutboxDelivery
    {
        config(['outbox.events' => ['booking.created' => ['primary' => FakeOutboxHandler::class]]]);

        $message = DB::transaction(fn (): OutboxMessage => app(OutboxWriter::class)->record(
            'booking.created',
            $key,
            ['booking_id' => 1],
        ));

        return $message->deliveries()->where('handler_key', 'primary')->firstOrFail();
    }

    /** @param array<string, mixed> $attributes */
    private function updateDelivery(OutboxDelivery $delivery, array $attributes): void
    {
        DB::table('outbox_deliveries')->where('id', $delivery->id)->update($attributes);
        $delivery->refresh();
    }
}
