<?php

namespace Tests\Feature\Admin;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Queries\AdminBookingIndexQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminBookingIndexTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07 12:00', 'Asia/Jakarta'));
        $this->admin = User::factory()->admin()->create();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    // Production break caught: the endpoint must publish the complete, defaulted index contract.
    public function test_index_returns_exact_default_filters_counts_and_pagination_props(): void
    {
        Booking::factory()->create([
            'reference' => 'SJ-DEFAULT-CONTRACT',
            'appointment_date' => '2026-09-07',
            'appointment_time' => '12:00',
        ]);

        $response = $this->actingAs($this->admin)->get('/admin/bookings');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/bookings/Index', false)
            ->where('counts', [
                'today' => 1,
                'pending' => 1,
                'confirmed' => 0,
                'completed' => 0,
            ])
            ->where('filters', [
                'scope' => 'upcoming',
                'status' => null,
                'q' => '',
                'dateFrom' => null,
                'dateTo' => null,
            ])
            ->has('bookings.data', 1)
            ->where('bookings.currentPage', 1)
            ->where('bookings.lastPage', 1)
            ->where('bookings.total', 1));

        $this->assertSame(
            ['data', 'links', 'currentPage', 'lastPage', 'total'],
            array_keys($response->inertiaProps('bookings')),
        );
    }

    // Production break caught: SQL wildcards supplied by an administrator must be matched literally.
    #[DataProvider('literalSearchProvider')]
    public function test_search_treats_percent_underscore_backslash_and_escape_character_as_literals(
        string $search,
        string $matchingName,
        string $nonMatchingName,
        string $reference,
    ): void {
        Booking::factory()->create([
            'reference' => $reference,
            'customer_name' => $matchingName,
            'appointment_date' => '2026-09-08',
        ]);
        Booking::factory()->create([
            'reference' => 'SJ-LITERAL-CONTROL',
            'customer_name' => $nonMatchingName,
            'appointment_date' => '2026-09-08',
        ]);

        $response = $this->actingAs($this->admin)->get('/admin/bookings?'.http_build_query([
            'scope' => 'all',
            'q' => $search,
        ]));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('filters.q', $search)
            ->has('bookings.data', 1)
            ->where('bookings.data.0.reference', $reference));
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function literalSearchProvider(): iterable
    {
        yield 'percent' => ['100%', 'Ayu 100% Relax', 'Ayu 100X Relax', 'SJ-LITERAL-PERCENT'];
        yield 'underscore' => ['Ayu_Code', 'Ayu_Code', 'AyuXCode', 'SJ-LITERAL-UNDERSCORE'];
        yield 'backslash' => ['Ayu\Spa', 'Ayu\Spa', 'Ayu Spa', 'SJ-LITERAL-BACKSLASH'];
        yield 'escape character' => ['Ayu!Spa', 'Ayu!Spa', 'Ayu Spa', 'SJ-LITERAL-ESCAPE'];
    }

    // Production break caught: all four approved searchable fields must use case-insensitive substring matching.
    #[DataProvider('searchFieldProvider')]
    public function test_search_matches_reference_name_email_and_phone_case_insensitively(
        array $attributes,
        string $search,
    ): void {
        Booking::factory()->create([
            ...$attributes,
            'reference' => $attributes['reference'] ?? 'SJ-SEARCH-FIELD',
            'appointment_date' => '2026-09-08',
        ]);
        Booking::factory()->create([
            'reference' => 'SJ-NONMATCHING-FIELD',
            'customer_name' => 'Unrelated Customer',
            'customer_email' => 'other@example.test',
            'customer_phone' => '+620000000000',
            'appointment_date' => '2026-09-08',
        ]);

        $response = $this->actingAs($this->admin)->get('/admin/bookings?'.http_build_query([
            'scope' => 'all',
            'q' => $search,
        ]));

        $response->assertOk();
        $this->assertSame(
            [$attributes['reference'] ?? 'SJ-SEARCH-FIELD'],
            array_column($response->inertiaProps('bookings.data'), 'reference'),
        );
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function searchFieldProvider(): iterable
    {
        yield 'reference' => [['reference' => 'SJ-REF-MATCH-ABC'], 'ref-match'];
        yield 'name' => [['customer_name' => 'BUDI Santoso'], 'budi'];
        yield 'lowercase email query' => [['customer_email' => 'AYU@EXAMPLE.TEST'], 'ayu@example.test'];
        yield 'uppercase email query' => [['customer_email' => 'ayu@example.test'], 'AYU@EXAMPLE.TEST'];
        yield 'phone' => [['customer_phone' => '+62812345678901'], '812345678901'];
    }

    // Production break caught: equality at the current minute and every tie breaker must be deterministic.
    public function test_scopes_apply_boundary_minute_and_date_time_id_ordering(): void
    {
        $yesterday = $this->bookingAt('SJ-YESTERDAY', '2026-09-06', '18:00');
        $beforeNow = $this->bookingAt('SJ-BEFORE-NOW', '2026-09-07', '11:59');
        $atNowFirst = $this->bookingAt('SJ-AT-NOW-FIRST', '2026-09-07', '12:00');
        $atNowSecond = $this->bookingAt('SJ-AT-NOW-SECOND', '2026-09-07', '12:00');
        $laterToday = $this->bookingAt('SJ-LATER-TODAY', '2026-09-07', '13:00');
        $tomorrow = $this->bookingAt('SJ-TOMORROW', '2026-09-08', '09:00');

        $upcoming = $this->actingAs($this->admin)->get('/admin/bookings?scope=upcoming');
        $this->assertSame([
            $atNowFirst->reference,
            $atNowSecond->reference,
            $laterToday->reference,
            $tomorrow->reference,
        ], array_column($upcoming->inertiaProps('bookings.data'), 'reference'));

        $past = $this->get('/admin/bookings?scope=past');
        $this->assertSame([
            $beforeNow->reference,
            $yesterday->reference,
        ], array_column($past->inertiaProps('bookings.data'), 'reference'));

        $all = $this->get('/admin/bookings?scope=all');
        $this->assertSame([
            $tomorrow->reference,
            $laterToday->reference,
            $atNowSecond->reference,
            $atNowFirst->reference,
            $beforeNow->reference,
            $yesterday->reference,
        ], array_column($all->inertiaProps('bookings.data'), 'reference'));
    }

    // Production break caught: filters must compose while dashboard counts remain independent of them.
    public function test_status_search_and_inclusive_date_filters_compose_without_filtering_counts(): void
    {
        Booking::factory()->create([
            'reference' => 'SJ-MATCHING-COMPOSED',
            'status' => BookingStatus::Confirmed,
            'customer_name' => 'Ayu Match',
            'appointment_date' => '2026-09-07',
        ]);
        Booking::factory()->create([
            'reference' => 'SJ-WRONG-STATUS',
            'status' => BookingStatus::Pending,
            'customer_name' => 'Ayu Match',
            'appointment_date' => '2026-09-07',
        ]);
        Booking::factory()->create([
            'reference' => 'SJ-BEFORE-RANGE',
            'status' => BookingStatus::Confirmed,
            'customer_name' => 'Ayu Match',
            'appointment_date' => '2026-09-06',
        ]);
        Booking::factory()->create([
            'reference' => 'SJ-AFTER-RANGE',
            'status' => BookingStatus::Confirmed,
            'customer_name' => 'Ayu Match',
            'appointment_date' => '2026-09-09',
        ]);
        Booking::factory()->create([
            'reference' => 'SJ-WRONG-SEARCH',
            'status' => BookingStatus::Confirmed,
            'customer_name' => 'Budi Other',
            'appointment_date' => '2026-09-07',
        ]);
        Booking::factory()->create([
            'reference' => 'SJ-COMPLETED-COUNT',
            'status' => BookingStatus::Completed,
            'appointment_date' => '2026-09-10',
        ]);

        $response = $this->actingAs($this->admin)->get('/admin/bookings?'.http_build_query([
            'scope' => 'all',
            'status' => 'confirmed',
            'q' => '  Ayu  ',
            'dateFrom' => '2026-09-07',
            'dateTo' => '2026-09-07',
        ]));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('filters', [
                'scope' => 'all',
                'status' => 'confirmed',
                'q' => 'Ayu',
                'dateFrom' => '2026-09-07',
                'dateTo' => '2026-09-07',
            ])
            ->where('counts', [
                'today' => 3,
                'pending' => 1,
                'confirmed' => 4,
                'completed' => 1,
            ])
            ->has('bookings.data', 1)
            ->where('bookings.data.0.reference', 'SJ-MATCHING-COMPOSED'));
    }

    // Production break caught: malformed filters and paginator state must never reach the query layer.
    #[DataProvider('invalidFilterProvider')]
    public function test_invalid_filters_are_rejected(string $query, string $field): void
    {
        $this->actingAs($this->admin)
            ->from('/admin/bookings')
            ->get('/admin/bookings?'.$query)
            ->assertRedirect('/admin/bookings')
            ->assertSessionHasErrors($field);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidFilterProvider(): iterable
    {
        yield 'scope' => ['scope=tomorrow', 'scope'];
        yield 'status' => ['status=refunded', 'status'];
        yield 'date from format' => ['dateFrom=07-09-2026', 'dateFrom'];
        yield 'date to format' => ['dateTo=tomorrow', 'dateTo'];
        yield 'date range order' => ['dateFrom=2026-09-08&dateTo=2026-09-07', 'dateTo'];
        yield 'zero page' => ['page=0', 'page'];
        yield 'non-integer page' => ['page=second', 'page'];
        yield 'query length' => ['q='.str_repeat('a', 101), 'q'];
    }

    // Production break caught: fields outside the six-key query contract must receive normal validation errors.
    public function test_unknown_query_fields_are_rejected(): void
    {
        $this->actingAs($this->admin)
            ->from('/admin/bookings')
            ->get('/admin/bookings?'.http_build_query([
                'scope' => 'all',
                'redirect' => 'https://attacker.example/redirect',
            ]))
            ->assertRedirect('/admin/bookings')
            ->assertSessionHasErrors('redirect');
    }

    // Production break caught: pages must contain 20 items and retain every active validated filter.
    public function test_pagination_uses_twenty_rows_plain_labels_and_preserved_query_strings(): void
    {
        Booking::factory()->count(21)->create([
            'status' => BookingStatus::Confirmed,
            'customer_name' => 'Ayu Page',
            'appointment_date' => '2026-09-07',
            'appointment_time' => '13:00',
        ]);

        $query = http_build_query([
            'scope' => 'all',
            'status' => 'confirmed',
            'q' => 'Ayu',
            'dateFrom' => '2026-09-07',
            'dateTo' => '2026-09-07',
        ]);
        $response = $this->actingAs($this->admin)->get('/admin/bookings?'.$query);

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('bookings.data', 20)
            ->where('bookings.currentPage', 1)
            ->where('bookings.lastPage', 2)
            ->where('bookings.total', 21));

        $links = $response->inertiaProps('bookings.links');
        $this->assertSame('« Previous', $links[0]['label']);
        $this->assertSame('Next »', $links[array_key_last($links)]['label']);
        $this->assertStringContainsString('scope=all', $links[array_key_last($links)]['url']);
        $this->assertStringContainsString('status=confirmed', $links[array_key_last($links)]['url']);
        $this->assertStringContainsString('q=Ayu', $links[array_key_last($links)]['url']);
        $this->assertStringContainsString('dateFrom=2026-09-07', $links[array_key_last($links)]['url']);
        $this->assertStringContainsString('dateTo=2026-09-07', $links[array_key_last($links)]['url']);
        $this->assertStringContainsString('page=2', $links[array_key_last($links)]['url']);
        $this->assertStringNotContainsString('&laquo;', json_encode($links, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('&raquo;', json_encode($links, JSON_THROW_ON_ERROR));

        $this->actingAs($this->admin)
            ->get('/admin/bookings?'.$query.'&page=2')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('bookings.data', 1)
                ->where('bookings.currentPage', 2));
    }

    // Production break caught: the query object must never copy arbitrary request input into generated links.
    public function test_pagination_links_contain_only_validated_filters_and_paginator_page(): void
    {
        Booking::factory()->count(21)->create([
            'status' => BookingStatus::Confirmed,
            'customer_name' => 'Ayu Page',
            'appointment_date' => '2026-09-07',
            'appointment_time' => '13:00',
        ]);
        $this->app['request']->query->replace([
            'scope' => 'all',
            'status' => 'confirmed',
            'q' => 'Ayu',
            'dateFrom' => '2026-09-07',
            'dateTo' => '2026-09-07',
            'redirect' => 'https://attacker.example/redirect',
            'page' => 1,
        ]);

        $paginator = (new AdminBookingIndexQuery)->paginate([
            'scope' => 'all',
            'status' => 'confirmed',
            'q' => 'Ayu',
            'dateFrom' => '2026-09-07',
            'dateTo' => '2026-09-07',
        ], CarbonImmutable::now('Asia/Jakarta'));
        parse_str((string) parse_url((string) $paginator->nextPageUrl(), PHP_URL_QUERY), $parameters);

        $this->assertSame([
            'scope' => 'all',
            'status' => 'confirmed',
            'q' => 'Ayu',
            'dateFrom' => '2026-09-07',
            'dateTo' => '2026-09-07',
            'page' => '2',
        ], $parameters);
    }

    // Production break caught: summaries must expose every approved field and no model internals.
    public function test_booking_summary_and_pagination_are_exact_allow_lists(): void
    {
        Booking::factory()->create([
            'reference' => 'SJ-ALLOW-LIST',
            'status' => BookingStatus::Pending,
            'customer_name' => 'Ayu Lestari',
            'customer_email' => 'ayu@example.test',
            'customer_phone' => '+6281234567890',
            'package_name_id' => 'Paket Tenang',
            'package_name_en' => 'Calm Package',
            'package_is_vip' => true,
            'treatment_mode' => 'vip',
            'appointment_date' => '2026-09-08',
            'appointment_time' => '14:30',
            'appointment_timezone' => 'Asia/Jakarta',
            'guests' => 2,
            'total_duration_minutes' => 150,
            'total_price_idr' => 525000,
            'notes' => 'PRIVATE-NOTES',
        ]);

        $response = $this->actingAs($this->admin)->get('/admin/bookings?scope=all');
        $response->assertOk();

        $summary = $response->inertiaProps('bookings.data.0');
        $this->assertSame([
            'reference',
            'status',
            'package',
            'treatmentMode',
            'appointment',
            'customer',
            'guests',
            'totalDurationMinutes',
            'totalPriceIdr',
            'allowedTransitions',
        ], array_keys($summary));
        $this->assertSame(['nameId', 'nameEn', 'isVip'], array_keys($summary['package']));
        $this->assertSame(['date', 'time', 'timezone'], array_keys($summary['appointment']));
        $this->assertSame(['name', 'email', 'phone'], array_keys($summary['customer']));
        $this->assertSame([
            'reference' => 'SJ-ALLOW-LIST',
            'status' => 'pending',
            'package' => [
                'nameId' => 'Paket Tenang',
                'nameEn' => 'Calm Package',
                'isVip' => true,
            ],
            'treatmentMode' => 'vip',
            'appointment' => [
                'date' => '2026-09-08',
                'time' => '14:30',
                'timezone' => 'Asia/Jakarta',
            ],
            'customer' => [
                'name' => 'Ayu Lestari',
                'email' => 'ayu@example.test',
                'phone' => '+6281234567890',
            ],
            'guests' => 2,
            'totalDurationMinutes' => 150,
            'totalPriceIdr' => 525000,
            'allowedTransitions' => ['confirmed', 'cancelled'],
        ], $summary);
        $this->assertStringNotContainsString('PRIVATE-NOTES', json_encode($summary, JSON_THROW_ON_ERROR));
    }

    // Production break caught: the detail back target must come only from validated filters and the named local route.
    public function test_index_stores_only_a_server_built_safe_back_url(): void
    {
        $response = $this->actingAs($this->admin)
            ->withHeader('Referer', 'https://attacker.example/referer')
            ->get('/admin/bookings?'.http_build_query([
                'scope' => 'all',
                'status' => 'pending',
                'q' => 'Ayu',
                'dateFrom' => '2026-09-07',
                'dateTo' => '2026-09-08',
                'page' => 4,
            ]));

        $response->assertOk()->assertSessionHas(
            'admin.bookings.index_url',
            '/admin/bookings?scope=all&status=pending&q=Ayu&dateFrom=2026-09-07&dateTo=2026-09-08',
        );
    }

    private function bookingAt(string $reference, string $date, string $time): Booking
    {
        return Booking::factory()->create([
            'reference' => $reference,
            'appointment_date' => $date,
            'appointment_time' => $time,
        ]);
    }
}
