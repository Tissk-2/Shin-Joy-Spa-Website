<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Service;
use App\Models\ServiceExtension;
use App\Models\ServiceVariant;
use App\Models\User;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
                ->where('auth.canAccessAdmin', false)
                ->where('locale', 'id')
                ->where('flash.status', null));
    }

    public function test_admin_access_shared_prop_is_derived_from_current_verification_and_role_state(): void
    {
        $customer = User::factory()->create();
        $unverifiedAdmin = User::factory()->admin()->unverified()->create();
        $revokedAdmin = User::factory()->admin()->create();
        DB::table('users')->where('id', $revokedAdmin->id)->update([
            'role' => UserRole::Customer->value,
        ]);
        $revokedAdmin->refresh();
        $verifiedAdmin = User::factory()->admin()->create();

        foreach ([$customer, $unverifiedAdmin, $revokedAdmin] as $deniedUser) {
            $this->actingAs($deniedUser)->get(route('home'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('auth.canAccessAdmin', false));
        }

        $this->actingAs($verifiedAdmin)->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('auth.canAccessAdmin', true));
    }

    public function test_admin_access_shared_prop_uses_the_current_database_role_after_session_revocation(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        DB::table('users')->where('id', $admin->id)->update([
            'role' => UserRole::Customer->value,
        ]);

        $this->assertTrue($admin->isAdmin(), 'The authenticated model must remain stale for this regression test.');

        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.canAccessAdmin', false));
    }

    // Production break caught: shared links must not make inactive or unbookable packages visible in the footer contract.
    public function test_shared_catalog_contains_only_compact_visible_package_links(): void
    {
        $this->seed(ServiceCatalogSeeder::class);
        $nezuko = Service::query()->where('slug', 'nezuko')->firstOrFail();
        ServiceVariant::query()->where('service_id', $nezuko->id)->update(['is_active' => false]);
        $draft = Service::query()->create([
            'category_id' => $nezuko->category_id,
            'slug' => 'draft-shared-link',
            'name_id' => 'Draf',
            'name_en' => 'Draft',
            'description_id' => 'Draf',
            'description_en' => 'Draft',
            'long_description_id' => 'Draf',
            'long_description_en' => 'Draft',
            'image_path' => '/img/massage.jpg',
            'gallery_paths' => [],
            'popularity' => 0,
            'is_vip' => false,
            'is_active' => true,
            'published_at' => null,
            'display_order' => 99,
        ]);
        ServiceVariant::query()->create([
            'service_id' => $draft->id,
            'code' => 'draft-shared-link-regular-60',
            'treatment_mode' => 'regular',
            'duration_minutes' => 60,
            'price_idr' => 199000,
            'is_active' => true,
            'published_at' => now(),
            'display_order' => 1,
        ]);
        ServiceExtension::query()->create([
            'service_id' => $draft->id,
            'code' => 'draft-shared-link-extra-30',
            'treatment_mode' => 'regular',
            'duration_minutes' => 30,
            'price_idr' => 50000,
            'is_active' => true,
            'published_at' => now(),
            'display_order' => 1,
        ]);

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
            ->where('auth.canAccessAdmin', false)
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
