<?php

namespace Tests\Feature;

use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ServiceCatalogSeeder::class);
    }

    public function test_public_pages_are_served_by_inertia(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Home'));

        $this->get('/services/balinese-massage')
            ->assertNotFound();

        $this->get('/services/nezuko')
            ->assertNotFound();

        $this->get('/booking?service=nezuko&mode=regular&duration=90')
            ->assertRedirect(route('login', absolute: false));
    }

    public function test_services_page_and_about_section_alias_are_available(): void
    {
        $response = $this->get('/services');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Services')
            ->has('categories', 1)
            ->where('categories.0.slug', 'package')
            ->has('categories.0.packages', 2)
            ->where('categories.0.packages.0.slug', 'nezuko')
            ->missing('categories.0.id')
            ->missing('categories.0.published_at')
            ->missing('categories.0.packages.0.id')
            ->missing('categories.0.packages.0.published_at'));

        $category = $response->inertiaProps('categories')[0];
        $this->assertSame(['slug', 'nameId', 'nameEn', 'image', 'packages'], array_keys($category));
        $this->assertSame([
            'slug', 'nameId', 'nameEn', 'descId', 'descEn', 'longId', 'longEn',
            'benefitId', 'benefitEn', 'image', 'gallery', 'isVip',
            'pricingBasis', 'minGuests', 'maxGuests', 'pricingVerified', 'variants', 'extensions',
        ], array_keys($category['packages'][0]));

        $this->get('/about')->assertRedirect('/#about');
    }

    public function test_service_route_rejects_malformed_slugs_without_executing_the_page(): void
    {
        $this->get('/services/not_valid')->assertNotFound();
        $this->get('/services/%3Cscript%3E')->assertNotFound();
    }

    public function test_well_formed_unknown_service_returns_not_found(): void
    {
        $this->get('/services/not-a-real-service')
            ->assertNotFound();
    }

    public function test_administrator_content_is_protected_and_legacy_login_route_is_absent(): void
    {
        $this->get('/admin')->assertRedirect(route('login', absolute: false));
        $this->get('/admin-login')->assertNotFound();
    }
}
