<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PublicContactInformationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cms_contact_edits_are_shared_with_home_catalog_and_booking_pages(): void
    {
        $this->seed(ServiceCatalogSeeder::class);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/admin/content', [
                'values' => [
                    'loc.address' => [
                        'id' => 'Jl. Mawar No. 8, Malang',
                        'en' => '8 Rose Street, Malang',
                    ],
                    'loc.phone' => [
                        'id' => '+62 811 2345 6789',
                        'en' => '+62 812 9876 5432',
                    ],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        foreach (['/', '/services'] as $path) {
            $response = $this->get($path);
            $response->assertOk();
            $content = $response->inertiaProps('content');

            $this->assertSame([
                'id' => 'Jl. Mawar No. 8, Malang',
                'en' => '8 Rose Street, Malang',
            ], $content['loc.address']);
            $this->assertSame([
                'id' => '+62 811 2345 6789',
                'en' => '+62 812 9876 5432',
            ], $content['loc.phone']);
        }

        $bookingResponse = $this->get('/booking?service=nezuko');
        $bookingResponse->assertOk()->assertInertia(fn (Assert $page) => $page->component('Booking'));
        $bookingContent = $bookingResponse->inertiaProps('content');
        $this->assertSame('Jl. Mawar No. 8, Malang', $bookingContent['loc.address']['id']);
        $this->assertSame('+62 812 9876 5432', $bookingContent['loc.phone']['en']);
    }

    public function test_missing_locale_override_remains_absent_for_client_fallback(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/admin/content', [
                'values' => [
                    'loc.address' => ['id' => 'Alamat CMS Indonesia', 'en' => ''],
                    'loc.phone' => ['id' => '', 'en' => '+62 813 1111 2222'],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $props = $this->get('/')->assertOk()->inertiaProps();

        $this->assertSame(
            ['id' => 'Alamat CMS Indonesia'],
            $props['content']['loc.address'],
        );
        $this->assertSame(
            ['en' => '+62 813 1111 2222'],
            $props['content']['loc.phone'],
        );
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }
}
