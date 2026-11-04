<?php

namespace Tests\Feature\Auth;

use App\Models\Service;
use App\Models\User;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BookingAuthContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ServiceCatalogSeeder::class);
    }

    public function test_login_and_registration_resolve_both_published_packages_from_the_safe_intended_url(): void
    {
        foreach ([
            'nezuko' => ['nameId' => 'Nezuko', 'nameEn' => 'Nezuko', 'startingPriceIdr' => 199000],
            'nezuko-vip' => ['nameId' => 'Nezuko VIP', 'nameEn' => 'Nezuko VIP', 'startingPriceIdr' => 299000],
        ] as $slug => $catalogData) {
            $this->get('/booking?service='.$slug.'&name=Forged%20treatment&starting_price=1')
                ->assertRedirect('/login');

            foreach (['login', 'register'] as $page) {
                $this->get('/'.$page)
                    ->assertOk()
                    ->assertInertia(fn (Assert $inertia) => $inertia
                        ->component('auth/'.ucfirst($page))
                        ->where('bookingContext', [
                            'slug' => $slug,
                            ...$catalogData,
                        ]));
            }

            $this->assertNotNull(session('url.intended'));
            $this->assertStringContainsString('service='.$slug, (string) session('url.intended'));
        }
    }

    public function test_verification_prompt_keeps_the_published_booking_context_without_consuming_the_redirect(): void
    {
        $user = User::factory()->unverified()->create();
        $intended = 'http://localhost/booking?service=nezuko-vip&name=Forged&starting_price=1';

        $this->actingAs($user)
            ->withSession(['url.intended' => $intended])
            ->get('/verify-email')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/VerifyEmail')
                ->where('bookingContext', [
                    'slug' => 'nezuko-vip',
                    'nameId' => 'Nezuko VIP',
                    'nameEn' => 'Nezuko VIP',
                    'startingPriceIdr' => 299000,
                ]));

        $this->assertSame($intended, session('url.intended'));
    }

    public function test_unpublished_or_invalid_booking_context_is_omitted_gracefully(): void
    {
        Service::query()->where('slug', 'nezuko')->update(['published_at' => null]);

        foreach ([
            '/booking?service=nezuko',
            '/booking?service=missing-treatment',
            '/booking?service[]=nezuko',
        ] as $bookingUrl) {
            $this->withSession(['url.intended' => $bookingUrl])
                ->get('/login')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('auth/Login')
                    ->where('bookingContext', null));
        }
    }

    public function test_ordinary_login_stays_generic_without_a_booking_intent(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/Login')
                ->where('bookingContext', null));
    }
}
