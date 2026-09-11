<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Policies\ServiceCategoryPolicy;
use App\Policies\ServicePolicy;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminCatalogAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_before_catalog_data_is_rendered(): void
    {
        ServiceCategory::query()->create($this->categoryAttributes('private-catalog-canary'));

        $response = $this->get('/admin/catalog');

        $response->assertRedirect(route('login', absolute: false));
        $this->assertStringNotContainsString('private-catalog-canary', $response->getContent());
    }

    public function test_unverified_admin_is_redirected_before_catalog_data_is_rendered(): void
    {
        $admin = User::factory()->admin()->unverified()->create();
        ServiceCategory::query()->create($this->categoryAttributes('private-catalog-canary'));

        $response = $this->actingAs($admin)->get('/admin/catalog');

        $response->assertRedirect(route('verification.notice', absolute: false));
        $this->assertStringNotContainsString('private-catalog-canary', $response->getContent());
    }

    public function test_verified_customer_is_forbidden_before_catalog_data_is_rendered(): void
    {
        $customer = User::factory()->create();
        ServiceCategory::query()->create($this->categoryAttributes('private-catalog-canary'));

        $response = $this->actingAs($customer)->get('/admin/catalog');

        $response->assertForbidden();
        $this->assertStringNotContainsString('private-catalog-canary', $response->getContent());
    }

    public function test_verified_admin_can_open_the_catalog_index(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/admin/catalog')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/catalog/Index', false)
                ->has('categories')
                ->has('packages')
                ->has('can'));
    }

    public function test_catalog_route_keeps_authorization_before_bindings_and_has_no_write_throttle(): void
    {
        $route = app('router')->getRoutes()->getByName('admin.catalog.index');

        $this->assertNotNull($route);
        $this->assertSame('/admin/catalog', route('admin.catalog.index', absolute: false));
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertSame(
            ['auth', 'verified', 'admin'],
            array_values(array_filter(
                $route->gatherMiddleware(),
                fn (string $middleware): bool => in_array($middleware, ['auth', 'verified', 'admin'], true),
            )),
        );
        $this->assertNotContains('throttle:catalog-write', $route->gatherMiddleware());

        $securityStack = array_values(array_filter(
            app(HttpKernel::class)->getMiddlewarePriority(),
            fn (string $middleware): bool => in_array($middleware, [
                EnsureEmailIsVerified::class,
                EnsureUserIsAdmin::class,
                SubstituteBindings::class,
            ], true),
        ));

        $this->assertSame([
            EnsureEmailIsVerified::class,
            EnsureUserIsAdmin::class,
            SubstituteBindings::class,
        ], $securityStack);
    }

    public function test_catalog_policies_allow_every_ability_only_for_verified_admins(): void
    {
        if (! class_exists(ServiceCategoryPolicy::class) || ! class_exists(ServicePolicy::class)) {
            $this->fail('The catalog policies have not been implemented.');
        }

        $categoryPolicy = new ServiceCategoryPolicy;
        $servicePolicy = new ServicePolicy;
        $category = ServiceCategory::query()->create($this->categoryAttributes('body-care'));
        $service = Service::query()->create($this->serviceAttributes($category, 'body-treatment'));
        $verifiedAdmin = User::factory()->admin()->create();
        $deniedUsers = [
            User::factory()->admin()->unverified()->create(),
            User::factory()->create(),
        ];

        foreach ($deniedUsers as $user) {
            $this->assertCategoryAbilities($categoryPolicy, $user, $category, false);
            $this->assertServiceAbilities($servicePolicy, $user, $service, false);
        }

        $this->assertCategoryAbilities($categoryPolicy, $verifiedAdmin, $category, true);
        $this->assertServiceAbilities($servicePolicy, $verifiedAdmin, $service, true);
    }

    public function test_catalog_write_limiter_uses_admin_and_ip_then_ip_only_without_replacing_existing_limiters(): void
    {
        $limiter = RateLimiter::limiter('catalog-write');

        $this->assertNotNull($limiter);

        $admin = User::factory()->admin()->create();
        $request = Request::create('/admin/catalog/categories', 'POST');
        $request->server->set('REMOTE_ADDR', '203.0.113.40');
        $request->setUserResolver(fn (): User => $admin);
        $limits = $limiter($request);

        $this->assertCount(2, $limits);
        $this->assertSame([20, 100], array_column($limits, 'maxAttempts'));
        $this->assertSame([60, 3600], array_column($limits, 'decaySeconds'));
        $this->assertSame('admin:'.$admin->getAuthIdentifier().'|ip:203.0.113.40', $limits[0]->key);
        $this->assertSame('ip:203.0.113.40', $limits[1]->key);
        $this->assertNotNull(RateLimiter::limiter('booking-store'));
        $this->assertNotNull(RateLimiter::limiter('admin-booking-status'));
    }

    private function assertCategoryAbilities(
        ServiceCategoryPolicy $policy,
        User $user,
        ServiceCategory $category,
        bool $expected,
    ): void {
        $this->assertSame($expected, $policy->viewAny($user));
        $this->assertSame($expected, $policy->view($user, $category));
        $this->assertSame($expected, $policy->create($user));
        $this->assertSame($expected, $policy->update($user, $category));
        $this->assertSame($expected, $policy->publish($user, $category));
        $this->assertSame($expected, $policy->archive($user, $category));
        $this->assertSame($expected, $policy->restore($user, $category));
        $this->assertSame($expected, $policy->delete($user, $category));
    }

    private function assertServiceAbilities(
        ServicePolicy $policy,
        User $user,
        Service $service,
        bool $expected,
    ): void {
        $this->assertSame($expected, $policy->viewAny($user));
        $this->assertSame($expected, $policy->view($user, $service));
        $this->assertSame($expected, $policy->create($user));
        $this->assertSame($expected, $policy->update($user, $service));
        $this->assertSame($expected, $policy->publish($user, $service));
        $this->assertSame($expected, $policy->archive($user, $service));
        $this->assertSame($expected, $policy->restore($user, $service));
        $this->assertSame($expected, $policy->delete($user, $service));
    }

    /** @return array<string, mixed> */
    private function categoryAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name_id' => 'Kategori Privat',
            'name_en' => 'Private Category',
            'image_path' => null,
            'published_at' => null,
            'lock_version' => 0,
            'is_active' => false,
            'display_order' => 1,
        ];
    }

    /** @return array<string, mixed> */
    private function serviceAttributes(ServiceCategory $category, string $slug): array
    {
        return [
            'category_id' => $category->id,
            'slug' => $slug,
            'name_id' => 'Perawatan Tubuh',
            'name_en' => 'Body Treatment',
            'description_id' => null,
            'description_en' => null,
            'long_description_id' => null,
            'long_description_en' => null,
            'good_for_id' => null,
            'good_for_en' => null,
            'duration_minutes' => null,
            'price_idr' => null,
            'image_path' => null,
            'gallery_paths' => [],
            'popularity' => 0,
            'is_vip' => false,
            'is_active' => false,
            'published_at' => null,
            'lock_version' => 0,
            'display_order' => 1,
        ];
    }
}
