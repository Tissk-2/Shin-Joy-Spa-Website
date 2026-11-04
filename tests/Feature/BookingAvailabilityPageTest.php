<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BookingAvailabilityPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_boolean_true_exposes_enabled_availability(): void
    {
        $user = User::factory()->create();
        foreach ([null, false, 'true', 1, true] as $value) {
            config(['booking.scheduling_enabled' => $value]);
            $this->actingAs($user)->get('/booking')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Booking', false)
                ->where('availabilityEnabled', $value === true)
                ->has('submissionKey')
                ->has('selection')
                ->has('packages'));
        }
    }

    public function test_booking_page_still_requires_a_verified_customer(): void
    {
        config(['booking.scheduling_enabled' => true]);
        $this->get('/booking')->assertRedirect('/login');
        $this->actingAs(User::factory()->unverified()->create())->get('/booking')
            ->assertRedirect(route('verification.notice', absolute: false));
    }
}
