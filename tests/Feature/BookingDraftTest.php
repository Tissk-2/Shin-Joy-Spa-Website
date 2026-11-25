<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Flow review F12: a booking sent after the session ended lost the date, time and notes.
class BookingDraftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-06 09:00:00', 'Asia/Jakarta'));
        $this->seed(ServiceCatalogSeeder::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_the_typed_choices_come_back_once_after_signing_in_again(): void
    {
        $user = User::factory()->create(['name' => 'Ayu Lestari', 'phone' => '+6281234567890']);

        $this->from('/booking?service=nezuko')
            ->post('/bookings', $this->payload())
            ->assertRedirect(route('login', absolute: false));

        $booking = $this->actingAs($user)->get('/booking?service=nezuko');
        $this->assertSame([
            'service' => 'nezuko',
            'mode' => 'regular',
            'duration' => 90,
            'extension' => 'nezuko-regular-extra-60',
        ], $booking->inertiaProps('selection'));
        $this->assertSame([
            'date' => '2026-09-07',
            'time' => '10:30',
            'guests' => 1,
            'notes' => 'Focus on shoulders.',
        ], $booking->inertiaProps('draft'));

        $this->assertNull($this->actingAs($user)->get('/booking?service=nezuko')->inertiaProps('draft'));
    }

    public function test_someone_else_signing_in_next_does_not_see_the_draft(): void
    {
        $other = User::factory()->create(['name' => 'Budi Santoso', 'phone' => '+6289999999999']);

        $this->post('/bookings', $this->payload());

        $booking = $this->actingAs($other)->get('/booking?service=nezuko');
        $this->assertNull($booking->inertiaProps('draft'));
        $this->assertSame('nezuko', $booking->inertiaProps('selection.service'));
        $this->assertSame('', $booking->inertiaProps('selection.mode'));
    }

    public function test_odd_values_are_dropped_instead_of_shown(): void
    {
        $user = User::factory()->create(['name' => 'Ayu Lestari', 'phone' => '+6281234567890']);

        $this->post('/bookings', $this->payload([
            'date' => '<b>tomorrow</b>',
            'time' => ['10:30'],
            'guests' => 99,
            'notes' => str_repeat('x', 1001),
            'service' => 'not-a-package',
        ]));

        $booking = $this->actingAs($user)->get('/booking');
        $this->assertSame(['date' => null, 'time' => null, 'guests' => null, 'notes' => null], $booking->inertiaProps('draft'));
        $this->assertSame('', $booking->inertiaProps('selection.service'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Ayu Lestari',
            'phone' => '+62 812-3456-7890',
            'service' => 'nezuko',
            'mode' => 'regular',
            'duration' => 90,
            'extension' => 'nezuko-regular-extra-60',
            'date' => '2026-09-07',
            'time' => '10:30',
            'guests' => 1,
            'notes' => 'Focus on shoulders.',
            'submission_key' => '018f2f7c-9d0a-7b1b-9a45-3c1f9d0b5a10',
            'expected_total_price_idr' => 340000,
            ...$overrides,
        ];
    }
}
