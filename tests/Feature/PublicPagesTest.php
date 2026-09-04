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
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ServiceDetail')
                ->where('service.slug', 'nezuko'));

        $this->get('/booking')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Booking'));
    }

    public function test_section_aliases_redirect_to_home_fragments(): void
    {
        $this->get('/services')->assertRedirect('/#services');
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

    public function test_no_unprotected_administrator_routes_are_registered(): void
    {
        $this->get('/admin')->assertNotFound();
        $this->get('/admin-login')->assertNotFound();
    }
}
