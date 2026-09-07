<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\User;
use App\Policies\BookingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const CANARY = 'PRIVATE-CANARY';

    public function test_guest_is_redirected_to_login_for_every_admin_content_route(): void
    {
        $booking = Booking::factory()->create(['customer_name' => self::CANARY]);

        foreach ($this->adminContentUris($booking) as $uri) {
            $response = $this->get($uri);

            $response->assertRedirect(route('login', absolute: false));
            $this->assertStringNotContainsString(self::CANARY, $response->getContent());
        }
    }

    public function test_unverified_admin_is_redirected_to_verification_for_every_admin_content_route(): void
    {
        $admin = User::factory()->admin()->unverified()->create();
        $booking = Booking::factory()->create(['customer_name' => self::CANARY]);

        foreach ($this->adminContentUris($booking) as $uri) {
            $response = $this->actingAs($admin)->get($uri);

            $response->assertRedirect(route('verification.notice', absolute: false));
            $this->assertStringNotContainsString(self::CANARY, $response->getContent());
        }
    }

    public function test_verified_customer_receives_forbidden_for_every_admin_content_route(): void
    {
        $customer = User::factory()->create();
        $booking = Booking::factory()->create(['customer_name' => self::CANARY]);

        foreach ($this->adminContentUris($booking) as $uri) {
            $response = $this->actingAs($customer)->get($uri);

            $response->assertForbidden();
            $this->assertStringNotContainsString(self::CANARY, $response->getContent());
        }
    }

    public function test_verified_admin_can_access_every_admin_content_route(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create();

        $this->actingAs($admin)->get('/admin')
            ->assertRedirect('/admin/bookings');

        $this->actingAs($admin)->get('/admin/bookings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/bookings/Index', false));

        $this->actingAs($admin)->get("/admin/bookings/{$booking->reference}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/bookings/Show', false));
    }

    public function test_revoked_admin_is_denied_using_the_current_database_role_for_every_admin_content_route(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = Booking::factory()->create(['customer_name' => self::CANARY]);
        $this->actingAs($admin);

        DB::table('users')->where('id', $admin->id)->update([
            'role' => UserRole::Customer->value,
        ]);

        $this->assertTrue($admin->isAdmin(), 'The authenticated model must remain stale for this regression test.');

        foreach ($this->adminContentUris($booking) as $uri) {
            $response = $this->get($uri);

            $response->assertForbidden();
            $this->assertStringNotContainsString(self::CANARY, $response->getContent());
        }
    }

    public function test_booking_policy_allows_only_verified_admins(): void
    {
        $policy = new BookingPolicy;
        $booking = Booking::factory()->create();
        $verifiedAdmin = User::factory()->admin()->create();
        $unverifiedAdmin = User::factory()->admin()->unverified()->create();
        $customer = User::factory()->create();
        $revokedAdmin = User::factory()->admin()->create();
        DB::table('users')->where('id', $revokedAdmin->id)->update([
            'role' => UserRole::Customer->value,
        ]);
        $revokedAdmin->refresh();

        foreach ([$unverifiedAdmin, $customer, $revokedAdmin] as $deniedUser) {
            $this->assertFalse($policy->viewAny($deniedUser));
            $this->assertFalse($policy->view($deniedUser, $booking));
            $this->assertFalse($policy->updateStatus($deniedUser, $booking));
        }

        $this->assertTrue($policy->viewAny($verifiedAdmin));
        $this->assertTrue($policy->view($verifiedAdmin, $booking));
        $this->assertTrue($policy->updateStatus($verifiedAdmin, $booking));
    }

    public function test_admin_login_uses_only_the_fixed_internal_destination(): void
    {
        $this->withSession(['url.intended' => 'https://attacker.example/previous'])
            ->get('/admin/login?redirect=https://attacker.example')
            ->assertRedirect(route('login', absolute: false));

        $this->assertSame('/admin/bookings', session('url.intended'));
    }

    public function test_authenticated_admin_login_entry_redirects_only_to_the_booking_console(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/admin/login?redirect=https://attacker.example')
            ->assertRedirect(route('admin.bookings.index', absolute: false));
    }

    /**
     * @return list<string>
     */
    private function adminContentUris(Booking $booking): array
    {
        return [
            '/admin',
            '/admin/bookings',
            "/admin/bookings/{$booking->reference}",
        ];
    }
}
