<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\User;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InertiaSharedDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_shared_props_expose_a_null_user_and_validated_locale(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Home')
                ->where('auth.user', null)
                ->where('locale', 'id')
                ->where('flash.status', null));
    }

    // Production break caught: shared links must not make inactive or unbookable packages visible in the footer contract.
    public function test_shared_catalog_contains_only_compact_visible_package_links(): void
    {
        $this->seed(ServiceCatalogSeeder::class);
        $nezuko = Service::query()->where('slug', 'nezuko')->firstOrFail();
        ServiceVariant::query()->where('service_id', $nezuko->id)->update(['is_active' => false]);

        $response = $this->get(route('home'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('catalog.packages.0.slug', 'nezuko-vip')
            ->has('catalog.packages', 1));

        $packages = $response->inertiaProps('catalog.packages');
        $this->assertSame(['slug', 'nameId', 'nameEn', 'isVip'], array_keys($packages[0]));
        $this->assertSame(['nezuko-vip'], array_column($packages, 'slug'));
        $this->assertArrayNotHasKey('id', $packages[0]);
        $this->assertArrayNotHasKey('created_at', $packages[0]);
    }

    public function test_authenticated_shared_props_contain_exactly_the_approved_user_fields(): void
    {
        $verifiedAt = now()->startOfSecond();
        $user = User::factory()->create([
            'name' => 'Ayu Lestari', 'email' => 'ayu@example.com', 'phone' => '+6281234567890', 'email_verified_at' => $verifiedAt,
        ]);

        $this->actingAs($user)->get(route('home'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('auth.user', 5)
            ->where('auth.user', ['id' => $user->id, 'name' => 'Ayu Lestari', 'email' => 'ayu@example.com', 'phone' => '+6281234567890', 'email_verified_at' => $verifiedAt->toISOString()]));
    }

    public function test_serialized_inertia_props_do_not_leak_secrets_internal_errors_or_configuration(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password-secret-canary'), 'remember_token' => 'remember-token-secret-canary']);
        config(['database.connections.sqlite.database' => 'database-config-secret-canary']);
        $response = $this->actingAs($user)->withSession([
            'password_reset_token' => 'reset-token-secret-canary', 'internal_exception' => 'SQLSTATE exception-secret-canary', 'database_config' => 'database-config-secret-canary',
        ])->get(route('home'));
        $response->assertOk();
        $props = $response->inertiaProps();
        $this->assertSame(['id', 'name', 'email', 'phone', 'email_verified_at'], array_keys($props['auth']['user']));
        $serializedProps = json_encode($props, JSON_THROW_ON_ERROR);
        foreach (['password', 'remember_token', 'password-secret-canary', 'remember-token-secret-canary', 'reset-token-secret-canary', 'exception-secret-canary', 'database-config-secret-canary', 'SQLSTATE', 'DB_DATABASE'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serializedProps);
        }
    }
}
