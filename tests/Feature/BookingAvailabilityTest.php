<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingSettings;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomReservation;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceExtension;
use App\Models\ServiceVariant;
use App\Models\User;
use App\Queries\PublicCatalogQuery;
use App\Queries\RoomAvailabilityQuery;
use App\Support\Scheduling\BookingAvailabilityUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BookingAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private ServiceVariant $variant;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function selection(array $changes = []): array
    {
        return [...['service' => 'local', 'mode' => 'regular', 'duration' => 60,
            'date' => '2026-10-26', 'guests' => 1], ...$changes];
    }

    private function fixture(): Room
    {
        CarbonImmutable::setTestNow('2026-10-25 23:00:00.123456 UTC');
        config(['booking.scheduling_enabled' => true]);
        BookingSettings::create(['id' => 1, 'version' => 1, 'review_intervals' => [
            ['end_minute' => 60, 'weekday' => 7, 'start_minute' => 0]],
            'review_target_minutes' => 30, 'booking_horizon_days' => 2,
            'minimum_lead_minutes' => 0, 'minimum_checkout_minutes' => 5]);
        $category = ServiceCategory::create(['slug' => 'local', 'name_id' => 'Local', 'name_en' => 'Local', 'image_path' => '', 'display_order' => 0, 'is_active' => true, 'published_at' => now()]);
        $service = Service::create(['category_id' => $category->id, 'slug' => 'local', 'name_id' => 'Local', 'name_en' => 'Local', 'description_id' => '', 'description_en' => '', 'long_description_id' => '', 'long_description_en' => '', 'duration_minutes' => 60, 'price_idr' => 1, 'image_path' => '', 'gallery_paths' => [], 'popularity' => 0, 'display_order' => 0, 'is_active' => true, 'published_at' => now()]);
        $this->variant = ServiceVariant::create(['service_id' => $service->id, 'code' => 'base', 'treatment_mode' => 'regular', 'duration_minutes' => 60, 'price_idr' => 1, 'display_order' => 0, 'is_active' => true, 'published_at' => now()]);

        return $this->room('room', 4);
    }

    private function room(string $code, int $capacity, array $changes = []): Room
    {
        $room = Room::create([...['code' => $code, 'name' => $code, 'type' => 'standard', 'capacity' => $capacity, 'is_active' => true, 'cleaning_minutes' => 15], ...$changes]);
        $room->variants()->attach($this->variant);

        return $room;
    }

    private function url(array $selection = []): string
    {
        return '/booking/availability?'.http_build_query($this->selection($selection));
    }

    public function test_auth_verification_and_disabled_requests_never_read_schedule_or_catalog(): void
    {
        $this->getJson($this->url())->assertUnauthorized();
        $this->actingAs(User::factory()->unverified()->create())->getJson($this->url())->assertForbidden();
        $user = User::factory()->create();
        Schema::drop('booking_settings');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($user)->getJson($this->url())->assertStatus(503)
            ->assertJsonPath('code', 'availability_unavailable')->assertHeader('Cache-Control', 'no-store, private');
        foreach (DB::getQueryLog() as $query) {
            $this->assertDoesNotMatchRegularExpression('/booking_settings|rooms|room_|services|service_/i', $query['query']);
        }
        DB::flushQueryLog();
        try {
            app(RoomAvailabilityQuery::class)->forSelection($this->selection());
            $this->fail('Disabled direct query returned success');
        } catch (BookingAvailabilityUnavailable) {
            $this->assertSame([], DB::getQueryLog());
        }
        DB::disableQueryLog();
        $this->expectException(BookingAvailabilityUnavailable::class);
        app(RoomAvailabilityQuery::class)->forSelection($this->selection());
    }

    public function test_success_has_exact_private_projection_23_starts_and_preserves_occupied_slots(): void
    {
        $room = $this->fixture();
        RoomBlock::create(['room_id' => $room->id, 'starts_at' => '2026-10-26 02:00:00', 'ends_at' => '2026-10-26 03:00:00', 'reason' => 'Secret maintenance']);
        $this->room('small', 2);
        $response = $this->actingAs(User::factory()->create())->getJson($this->url());
        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonCount(23, 'slots');
        $data = $response->json();
        $this->assertSame(['generated_at', 'slots'], array_keys($data));
        $this->assertSame('2026-10-25T23:00:00.123456Z', $data['generated_at']);
        $this->assertSame(['start_at', 'rooms'], array_keys($data['slots'][0]));
        $this->assertSame('2026-10-26T02:00:00.000000Z', $data['slots'][0]['start_at']);
        $this->assertSame('2026-10-26T13:00:00.000000Z', $data['slots'][22]['start_at']);
        $this->assertSame(['small', 'room'], array_column($data['slots'][0]['rooms'], 'code'));
        $this->assertSame(['code', 'name', 'type', 'capacity', 'available'], array_keys($data['slots'][0]['rooms'][0]));
        $this->assertFalse($data['slots'][0]['rooms'][1]['available']);
        $this->assertTrue($data['slots'][2]['rooms'][1]['available']);
    }

    public function test_strict_inputs_and_direct_callers_reject_noncanonical_values_before_database(): void
    {
        $cases = [['duration' => '060'], ['duration' => true], ['duration' => 60.0], ['duration' => '999999999999999999999999'],
            ['duration' => ' 60'], ['guests' => '1.0'], ['guests' => []], ['service' => []], ['mode' => 'vip'],
            ['date' => '2026-02-29'], ['date' => '2026-04-31'], ['date' => '2026-2-01'], ['room_type' => "bad\n"],
            ['room_type' => false], ['extension' => []], ['extension' => str_repeat('x', 81)], ['price' => 1], ['time' => '09:00']];
        foreach ($cases as $changes) {
            try {
                app(RoomAvailabilityQuery::class)->forSelection($this->selection($changes));
                $this->fail('Malformed selection accepted: '.json_encode($changes));
            } catch (ValidationException $error) {
                $this->assertArrayHasKey(array_key_first($changes), $error->errors());
            }
        }
        $this->actingAs(User::factory()->create());
        foreach ([['duration' => '060'], ['service' => ['nested']], ['extra' => 'x'], ['date' => '2026-02-29']] as $changes) {
            $this->getJson($this->url($changes))->assertUnprocessable()->assertJsonValidationErrors(array_key_first($changes));
        }
    }

    public function test_optional_normalization_exact_codes_and_catalog_lifecycle(): void
    {
        $this->fixture();
        $query = app(RoomAvailabilityQuery::class);
        $expected = $query->forSelection($this->selection());
        $this->assertSame($expected, $query->forSelection($this->selection(['extension' => '', 'room_type' => null])));
        foreach ([['service' => ' local'], ['extension' => 'missing'], ['duration' => 61]] as $change) {
            $this->assertInvalid($this->selection($change), array_key_first($change));
        }
        foreach (['service_variants', 'services', 'service_categories'] as $table) {
            foreach (['is_active' => false, 'published_at' => null] as $field => $value) {
                DB::table($table)->update([$field => $value]);
                $this->assertInvalid($this->selection(), $table === 'service_variants' ? 'service' : 'service');
                DB::table($table)->update([$field => $field === 'is_active' ? true : now()]);
            }
        }
    }

    public function test_service_selection_rejects_case_folded_database_slug_matches(): void
    {
        Schema::table('services', fn ($table) => $table->string('slug')->collation('NOCASE')->change());
        $this->fixture();
        // Exercise actual case-insensitive column equality, matching production's collation risk.
        $this->assertSame('local', DB::table('services')->where('slug', 'LOCAL')->value('slug'));
        $this->assertSame('local', (new PublicCatalogQuery)->findService('LOCAL')->slug);
        $this->assertInvalid($this->selection(['service' => 'LOCAL']), 'service');
        $this->actingAs(User::factory()->create())->getJson($this->url(['service' => 'LOCAL']))
            ->assertUnprocessable()->assertJsonValidationErrors('service');
        $this->getJson($this->url())->assertOk()->assertJsonCount(23, 'slots');
    }

    public function test_room_type_selection_is_exact_under_case_insensitive_database_comparison(): void
    {
        Schema::table('rooms', fn ($table) => $table->string('type', 64)->nullable()->collation('NOCASE')->change());
        $this->fixture();
        $this->room('upper', 1, ['type' => 'VIP']);
        $this->room('lower-first', 2, ['type' => 'vip']);
        $this->room('lower-second', 2, ['type' => 'vip']);
        $this->assertSame(['upper', 'lower-first', 'lower-second'], DB::table('rooms')->where('type', 'vip')
            ->orderBy('capacity')->orderBy('id')->pluck('code')->all());
        $query = app(RoomAvailabilityQuery::class);
        $legacy = $query->forInterval($this->variant, 1, CarbonImmutable::parse('2026-10-26 02:00:00 UTC'), 60, 'vip');
        $this->assertSame(['upper', 'lower-first', 'lower-second'], array_column($legacy, 'code'));
        $slots = $query->forSelection($this->selection(['room_type' => 'vip']))['slots'];
        foreach ($slots as $slot) {
            $this->assertTrue(array_is_list($slot['rooms']));
            $this->assertSame(['lower-first', 'lower-second'], array_column($slot['rooms'], 'code'));
        }
        $this->assertSame(['upper'], array_column($query->forSelection($this->selection(['room_type' => 'VIP']))['slots'][0]['rooms'], 'code'));
        $this->assertSame([], $query->forSelection($this->selection(['room_type' => 'ViP']))['slots'][0]['rooms']);
        $this->actingAs(User::factory()->create())->getJson($this->url(['room_type' => 'vip']))
            ->assertOk()->assertJsonPath('slots.0.rooms.0.code', 'lower-first')->assertJsonCount(2, 'slots.0.rooms');
    }

    public function test_catalog_extension_total_controls_closing_and_rejects_wrong_or_hidden_choices(): void
    {
        $this->fixture();
        $extension = ServiceExtension::create(['service_id' => $this->variant->service_id, 'code' => 'extra', 'treatment_mode' => 'regular', 'duration_minutes' => 60, 'price_idr' => 999, 'display_order' => 0, 'is_active' => true, 'published_at' => now()]);
        $result = app(RoomAvailabilityQuery::class)->forSelection($this->selection(['extension' => 'extra']));
        $this->assertCount(21, $result['slots']);
        $this->assertSame('2026-10-26T12:00:00.000000Z', $result['slots'][20]['start_at']);
        foreach ([['is_active' => false], ['published_at' => null], ['treatment_mode' => 'double_therapist'], ['duration_minutes' => 0], ['duration_minutes' => 721]] as $change) {
            $extension->update($change);
            $this->assertInvalid($this->selection(['extension' => 'extra']), 'extension');
            $extension->update(['is_active' => true, 'published_at' => now(), 'treatment_mode' => 'regular', 'duration_minutes' => 60]);
        }
        $extension->delete();
        $this->assertInvalid($this->selection(['extension' => 'extra']), 'extension');
        $this->variant->update(['duration_minutes' => 480]);
        ServiceExtension::create(['service_id' => $this->variant->service_id, 'code' => 'overflow', 'treatment_mode' => 'regular',
            'duration_minutes' => 300, 'price_idr' => 1, 'display_order' => 0, 'is_active' => true, 'published_at' => now()]);
        $this->assertInvalid($this->selection(['duration' => 480, 'extension' => 'overflow']), 'extension');
    }

    public function test_jakarta_inclusive_horizon_microsecond_cutoff_and_zero_lead(): void
    {
        $this->fixture();
        $query = app(RoomAvailabilityQuery::class);
        $this->assertCount(23, $query->forSelection($this->selection(['date' => '2026-10-28']))['slots']);
        foreach (['2026-10-25', '2026-10-29'] as $date) {
            $this->assertInvalid($this->selection(['date' => $date]), 'date');
        }
        foreach (['2026-10-26 02:00:00 UTC' => 22, '2026-10-26 01:59:59.999999 UTC' => 23] as $now => $count) {
            CarbonImmutable::setTestNow($now);
            $this->assertCount($count, $query->forSelection($this->selection())['slots']);
        }
        BookingSettings::whereKey(1)->update(['minimum_lead_minutes' => 30]);
        CarbonImmutable::setTestNow('2026-10-26 01:30:00 UTC');
        $this->assertCount(23, $query->forSelection($this->selection())['slots']);
        CarbonImmutable::setTestNow('2026-10-26 01:30:00.000001 UTC');
        $this->assertCount(22, $query->forSelection($this->selection())['slots']);
        CarbonImmutable::setTestNow('2026-10-26 14:00:00 UTC');
        $this->assertSame([], $query->forSelection($this->selection())['slots']);
    }

    public function test_missing_corrupt_settings_partial_schema_and_zero_candidates_fail_closed(): void
    {
        $this->fixture();
        BookingSettings::whereKey(1)->update(['review_intervals' => []]);
        $this->assertUnavailable();
        BookingSettings::query()->delete();
        $this->assertUnavailable();
        Schema::drop('booking_settings');
        $this->assertUnavailable();
    }

    public function test_terminal_rollout_absent_is_conservative_partial_fails_even_without_rooms(): void
    {
        $room = $this->fixture();
        RoomBlock::create(['room_id' => $room->id, 'starts_at' => '2026-10-26 02:00:00', 'ends_at' => '2026-10-26 03:00:00', 'reason' => 'Secret']);
        Schema::drop('room_block_terminations');
        $this->assertFalse(app(RoomAvailabilityQuery::class)->forSelection($this->selection())['slots'][0]['rooms'][0]['available']);
        Schema::create('room_block_terminations', fn ($table) => $table->id());
        $room->update(['is_active' => false]);
        $this->assertUnavailable();
    }

    public function test_read_schema_checks_consumed_columns_without_staff_or_writer_dependencies(): void
    {
        $this->fixture();
        Schema::drop('schedule_guards');
        Schema::drop('staff_access_guards');
        $this->assertCount(23, app(RoomAvailabilityQuery::class)->forSelection($this->selection())['slots']);
        foreach ([['services', 'display_order'], ['service_extensions', 'display_order'], ['service_variants', 'duration_minutes'],
            ['rooms', 'cleaning_minutes'], ['room_reservations', 'released_at'], ['booking_settings', 'booking_horizon_days']] as [$table, $column]) {
            Schema::table($table, fn ($blueprint) => $blueprint->renameColumn($column, 'missing_'.$column));
            $this->assertUnavailable();
            Schema::table($table, fn ($blueprint) => $blueprint->renameColumn('missing_'.$column, $column));
        }
    }

    public function test_empty_rooms_and_empty_slots_keep_valid_response_and_do_not_skip_readiness(): void
    {
        $room = $this->fixture();
        $room->update(['is_active' => false]);
        $slots = app(RoomAvailabilityQuery::class)->forSelection($this->selection())['slots'];
        $this->assertCount(23, $slots);
        $this->assertSame([], $slots[0]['rooms']);
        CarbonImmutable::setTestNow('2026-10-26 14:00:00 UTC');
        $this->assertSame([], app(RoomAvailabilityQuery::class)->forSelection($this->selection())['slots']);
        Schema::table('room_blocks', fn ($table) => $table->renameColumn('ends_at', 'missing_ends_at'));
        $this->assertUnavailable();
    }

    public function test_http_errors_are_localized_fixed_private_and_never_expose_saved_facts(): void
    {
        $this->fixture();
        $this->actingAs(User::factory()->create());
        BookingSettings::whereKey(1)->update(['review_intervals' => 'PRIVATE INVALID']);
        foreach (['en' => 'Room availability is temporarily unavailable.', 'id' => 'Ketersediaan ruangan sementara tidak dapat diperiksa.'] as $locale => $message) {
            $this->withSession(['locale' => $locale])->getJson($this->url())->assertStatus(503)
                ->assertExactJson(['message' => $message, 'code' => 'availability_unavailable'])
                ->assertHeader('Cache-Control', 'no-store, private');
            $response = $this->getJson($this->url(['extension' => ['secret' => true]]));
            $response->assertUnprocessable()->assertJsonValidationErrors('extension')->assertHeader('Cache-Control', 'no-store, private');
            $this->assertSame(['message', 'errors'], array_keys($response->json()));
        }
    }

    public function test_custom_validation_errors_use_localized_messages_instead_of_translation_keys(): void
    {
        $this->fixture();
        $this->actingAs(User::factory()->create());
        foreach (['en' => ['Enter a whole number within the allowed range.', 'Enter a valid date in YYYY-MM-DD format.', 'The selected treatment choice is unavailable.'],
            'id' => ['Masukkan bilangan bulat dalam rentang yang diizinkan.', 'Masukkan tanggal valid dalam format YYYY-MM-DD.', 'Pilihan perawatan tidak tersedia.']] as $locale => $messages) {
            foreach ([['duration' => '060'], ['date' => '2026-02-29'], ['extension' => 'private-missing-choice']] as $index => $selection) {
                $this->withSession(['locale' => $locale])->getJson($this->url($selection))->assertUnprocessable()
                    ->assertJsonPath('errors.'.array_key_first($selection).'.0', $messages[$index]);
            }
        }
    }

    public function test_user_rate_limit_is_independent_of_ip_and_runs_before_domain_work(): void
    {
        $this->actingAs(User::factory()->create());
        for ($request = 1; $request <= 60; $request++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.1.0.'.$request])->getJson($this->url())->assertStatus(503);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.2.0.1'])->getJson($this->url(['extra' => 'rejected before validation']))->assertStatus(429);
    }

    public function test_ip_rate_limit_counts_independent_users_and_auth_precedes_throttle(): void
    {
        $users = User::factory()->count(3)->create();
        for ($request = 0; $request < 120; $request++) {
            $this->actingAs($users[$request % 3])->withServerVariables(['REMOTE_ADDR' => '10.3.0.1'])
                ->getJson($this->url())->assertStatus(503);
        }
        $this->actingAs($users[0])->getJson($this->url(['extra' => 'rejected before validation']))->assertStatus(429);
        $this->actingAs(User::factory()->unverified()->create())->getJson($this->url())->assertForbidden();
    }

    public function test_unexpected_database_failure_propagates_instead_of_becoming_unavailable(): void
    {
        $this->fixture();
        DB::connection()->beforeExecuting(function ($sql, $bindings): void {
            if (str_contains($sql, 'from "rooms"')) {
                throw new QueryException('sqlite', $sql, $bindings, new \PDOException('Synthetic unexpected read failure'));
            }
        });
        $this->expectException(QueryException::class);
        app(RoomAvailabilityQuery::class)->forSelection($this->selection());
    }

    public function test_corrupt_original_intervals_fail_even_when_cutoff_leaves_no_starts(): void
    {
        $room = $this->fixture();
        $block = RoomBlock::create(['room_id' => $room->id, 'starts_at' => '2026-10-26 03:00:00', 'ends_at' => '2026-10-26 04:00:00', 'reason' => 'Private']);
        CarbonImmutable::setTestNow('2026-10-26 14:00:00 UTC');
        foreach ([['starts_at' => '2026-10-26 04:00:00', 'ends_at' => '2026-10-26 03:00:00'],
            ['starts_at' => '2026-10-26 03:00:00', 'ends_at' => '2026-10-26 04:99:00'],
            ['starts_at' => '2026-10-26 03:00:00', 'ends_at' => '2026-10-26 03:00:00']] as $change) {
            DB::table('room_blocks')->where('id', $block->id)->update($change);
            $this->assertUnavailable();
        }
        $block->delete();
        RoomReservation::create(['room_id' => $room->id, 'booking_id' => Booking::factory()->create()->id,
            'starts_at' => '2026-10-26 04:00:00', 'treatment_ends_at' => '2026-10-26 05:00:00',
            'ends_at' => '2026-10-26 03:00:00', 'cleaning_minutes' => 0]);
        $this->assertUnavailable();
    }

    public function test_corrupt_room_cleaning_is_unavailable_instead_of_shortening_occupancy(): void
    {
        $room = $this->fixture();
        DB::table('rooms')->where('id', $room->id)->update(['cleaning_minutes' => -60]);
        $this->assertUnavailable();
    }

    public function test_cancel_future_and_fractional_end_now_match_interval_and_preserve_elapsed_occupancy(): void
    {
        $room = $this->fixture();
        $block = RoomBlock::create(['room_id' => $room->id, 'starts_at' => '2026-10-26 02:00:00', 'ends_at' => '2026-10-26 03:00:00', 'reason' => 'Private']);
        $terminal = ['room_block_id' => $block->id, 'kind' => 'cancel_future', 'occurred_at' => '2026-10-26 01:00:00.000000',
            'reason' => 'Private terminal', 'actor_user_id' => null, 'actor_name' => 'Private actor', 'actor_role' => 'owner',
            'room_id' => $room->id, 'original_starts_at' => '2026-10-26 02:00:00', 'original_ends_at' => '2026-10-26 03:00:00',
            'original_reason' => 'Private maintenance', 'previous_version' => 1, 'resulting_version' => 2];
        DB::table('room_block_terminations')->insert($terminal);
        $query = app(RoomAvailabilityQuery::class);
        $this->assertTrue($query->forSelection($this->selection())['slots'][0]['rooms'][0]['available']);
        DB::table('room_block_terminations')->update(['kind' => 'end_now', 'occurred_at' => '2026-10-26 02:00:00.500000']);
        $result = $query->forSelection($this->selection());
        $this->assertFalse($result['slots'][0]['rooms'][0]['available']);
        $this->assertTrue($result['slots'][1]['rooms'][0]['available']);
        foreach (array_slice($result['slots'], 0, 3) as $slot) {
            $this->assertSame($query->forInterval($this->variant, 1, CarbonImmutable::parse($slot['start_at']), 60), $slot['rooms']);
        }
        DB::table('room_block_terminations')->update(['kind' => 'end_now', 'occurred_at' => '2026-10-26 02:00:00.000000']);
        $this->assertTrue($query->forSelection($this->selection())['slots'][0]['rooms'][0]['available']);
        CarbonImmutable::setTestNow('2026-10-26 14:00:00 UTC');
        foreach ([['kind' => 'invalid'], ['kind' => 'end_now', 'occurred_at' => 'bad private timestamp'],
            ['kind' => 'end_now', 'occurred_at' => '2026-10-26 03:00:00.000000']] as $change) {
            DB::table('room_block_terminations')->update($change);
            $this->assertUnavailable();
        }
    }

    public function test_single_room_group_filter_exact_type_and_released_holds(): void
    {
        $room = $this->fixture();
        $this->room('small', 2);
        $this->room('inactive', 4, ['is_active' => false]);
        $this->room('vip', 4, ['type' => 'VIP']);
        Room::create(['code' => 'incompatible', 'name' => 'Other', 'capacity' => 4]);
        $query = app(RoomAvailabilityQuery::class);
        $rooms = $query->forSelection($this->selection(['guests' => 3, 'room_type' => 'standard']))['slots'][0]['rooms'];
        $this->assertSame(['room'], array_column($rooms, 'code'));
        $this->assertSame([], $query->forSelection($this->selection(['guests' => 3, 'room_type' => 'vip']))['slots'][0]['rooms']);
        $hold = RoomReservation::create(['room_id' => $room->id, 'booking_id' => Booking::factory()->create(['status' => 'cancelled'])->id,
            'starts_at' => '2026-10-26 02:00:00', 'treatment_ends_at' => '2026-10-26 03:00:00',
            'ends_at' => '2026-10-26 03:15:00', 'cleaning_minutes' => 15]);
        $this->assertFalse($query->forSelection($this->selection(['guests' => 3, 'room_type' => 'standard']))['slots'][0]['rooms'][0]['available']);
        $hold->update(['released_at' => '2026-10-26 01:00:00']);
        $this->assertTrue($query->forSelection($this->selection(['guests' => 3, 'room_type' => 'standard']))['slots'][0]['rooms'][0]['available']);
    }

    public function test_cleaning_can_continue_after_close_and_valid_leap_day_is_accepted(): void
    {
        $this->fixture();
        CarbonImmutable::setTestNow('2028-02-28 23:00:00 UTC');
        $result = app(RoomAvailabilityQuery::class)->forSelection($this->selection(['date' => '2028-02-29']));
        $this->assertCount(23, $result['slots']);
        $this->assertTrue($result['slots'][22]['rooms'][0]['available']);
    }

    public function test_reads_are_batched_read_only_and_match_interval_with_room_cleaning(): void
    {
        $room = $this->fixture();
        $this->room('small', 2, ['cleaning_minutes' => 0]);
        $block = RoomBlock::create(['room_id' => $room->id, 'starts_at' => '2026-10-26 03:00:00', 'ends_at' => '2026-10-26 04:00:00', 'reason' => 'Secret']);
        RoomReservation::create(['room_id' => $room->id, 'booking_id' => Booking::factory()->create(['status' => 'cancelled'])->id, 'starts_at' => '2026-10-26 05:00:00', 'treatment_ends_at' => '2026-10-26 06:00:00', 'ends_at' => '2026-10-26 06:15:00', 'cleaning_minutes' => 15]);
        Booking::factory()->create();
        $user = User::factory()->create();
        $tables = ['bookings', 'booking_settings', 'room_blocks', 'room_reservations', 'room_block_terminations', 'schedule_guards', 'staff_access_guards'];
        $before = array_map(fn ($table) => DB::table($table)->get()->toJson(), $tables);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $result = app(RoomAvailabilityQuery::class)->forSelection($this->selection());
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        // SQLite: 33 uncached schema probes plus eight bounded data reads.
        $this->assertCount(41, $queries);
        foreach (['room_reservations', 'room_blocks', 'room_block_terminations'] as $table) {
            $this->assertCount(1, array_filter($queries, fn ($query) => str_contains($query['query'], 'from "'.$table.'"')));
        }
        $this->assertSame($before, array_map(fn ($table) => DB::table($table)->get()->toJson(), $tables));
        $this->actingAs($user)->getJson($this->url())->assertOk();
        $this->assertSame($before, array_map(fn ($table) => DB::table($table)->get()->toJson(), $tables));
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\b(insert|update|delete|for update)\b/i', $query['query']);
        }
        $this->assertFalse($result['slots'][0]['rooms'][1]['available']);
        $this->assertFalse($result['slots'][6]['rooms'][1]['available']);
        foreach ($result['slots'] as $slot) {
            $this->assertSame(app(RoomAvailabilityQuery::class)->forInterval($this->variant, 1, CarbonImmutable::parse($slot['start_at']), 60), $slot['rooms']);
        }
        foreach (range(1, 12) as $number) {
            $this->room('additional-'.$number, 4);
        }
        CarbonImmutable::setTestNow('2026-10-26 12:00:00 UTC');
        DB::enableQueryLog();
        DB::flushQueryLog();
        app(RoomAvailabilityQuery::class)->forSelection($this->selection());
        $this->assertSame(count($queries), count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    private function assertInvalid(array $selection, string $field): void
    {
        try {
            app(RoomAvailabilityQuery::class)->forSelection($selection);
            $this->fail('Invalid selection accepted');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey($field, $error->errors());
        }
    }

    private function assertUnavailable(): void
    {
        try {
            app(RoomAvailabilityQuery::class)->forSelection($this->selection());
            $this->fail('Unavailable schedule returned success');
        } catch (BookingAvailabilityUnavailable) {
            $this->addToAssertionCount(1);
        }
    }
}
