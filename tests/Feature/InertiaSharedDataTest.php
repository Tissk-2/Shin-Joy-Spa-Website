<?php

namespace Tests\Feature;

use App\Models\ServiceCategory;
use App\Models\User;
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

    // Production break caught: sharing raw or unscoped categories would expose inactive records or persistence-only fields to every Inertia page.
    public function test_shared_catalog_categories_are_active_only_and_presented_through_the_public_allow_list(): void
    {
        ServiceCategory::query()->create([
            'slug' => 'later',
            'name_id' => 'Kategori Nanti',
            'name_en' => 'Later Category',
            'image_path' => '/img/later.jpg',
            'is_active' => true,
            'display_order' => 20,
        ]);
        ServiceCategory::query()->create([
            'slug' => 'first',
            'name_id' => 'Kategori Pertama',
            'name_en' => 'First Category',
            'image_path' => '/img/first.jpg',
            'is_active' => true,
            'display_order' => 10,
        ]);
        ServiceCategory::query()->create([
            'slug' => 'hidden',
            'name_id' => 'Kategori Tersembunyi',
            'name_en' => 'Hidden Category',
            'image_path' => '/img/hidden.jpg',
            'is_active' => false,
            'display_order' => 1,
        ]);

        $response = $this->get(route('home'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('catalog.categories.0.slug', 'first')
            ->where('catalog.categories.1.slug', 'later')
            ->has('catalog.categories', 2));

        $categories = $response->inertiaProps('catalog.categories');

        $this->assertSame(['slug', 'nameId', 'nameEn', 'image'], array_keys($categories[0]));
        $this->assertSame(['first', 'later'], array_column($categories, 'slug'));
        $this->assertSame('/img/first.jpg', $categories[0]['image']);
    }

    public function test_authenticated_shared_props_contain_exactly_the_approved_user_fields(): void
    {
        $verifiedAt = now()->startOfSecond();
        $user = User::factory()->create([
            'name' => 'Ayu Lestari',
            'email' => 'ayu@example.com',
            'phone' => '+6281234567890',
            'email_verified_at' => $verifiedAt,
        ]);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('auth.user', 5)
                ->where('auth.user', [
                    'id' => $user->id,
                    'name' => 'Ayu Lestari',
                    'email' => 'ayu@example.com',
                    'phone' => '+6281234567890',
                    'email_verified_at' => $verifiedAt->toISOString(),
                ]));
    }

    public function test_serialized_inertia_props_do_not_leak_secrets_internal_errors_or_configuration(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password-secret-canary'),
            'remember_token' => 'remember-token-secret-canary',
        ]);

        config(['database.connections.sqlite.database' => 'database-config-secret-canary']);

        $response = $this->actingAs($user)
            ->withSession([
                'password_reset_token' => 'reset-token-secret-canary',
                'internal_exception' => 'SQLSTATE exception-secret-canary',
                'database_config' => 'database-config-secret-canary',
            ])
            ->get(route('home'));

        $response->assertOk();

        $props = $response->inertiaProps();
        $this->assertIsArray($props);
        $this->assertSame(
            ['id', 'name', 'email', 'phone', 'email_verified_at'],
            array_keys($props['auth']['user']),
        );

        $serializedProps = json_encode($props, JSON_THROW_ON_ERROR);

        foreach ([
            'password',
            'remember_token',
            'password-secret-canary',
            'remember-token-secret-canary',
            'reset-token-secret-canary',
            'exception-secret-canary',
            'database-config-secret-canary',
            'SQLSTATE',
            'DB_DATABASE',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serializedProps);
        }
    }
}
