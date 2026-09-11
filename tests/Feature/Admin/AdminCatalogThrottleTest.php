<?php

namespace Tests\Feature\Admin;

use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCatalogThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_twenty_first_write_for_one_admin_and_ip_gets_the_standard_429_response(): void
    {
        $admin = User::factory()->admin()->create();
        $category = $this->category('minute-limit');
        $url = "/admin/catalog/categories/{$category->slug}/archive";

        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $this->actingAs($admin)
                ->withServerVariables(['REMOTE_ADDR' => '203.0.113.21'])
                ->postJson($url, [])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('version');
        }

        $response = $this->actingAs($admin)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.21'])
            ->postJson($url, [])
            ->assertTooManyRequests();

        $response->assertHeader('Retry-After');
        $this->assertSame(0, $category->fresh()->lock_version);
    }

    public function test_one_hundred_and_first_ip_write_is_hour_limited_without_hitting_an_admin_minute_bucket(): void
    {
        $admins = User::factory()->count(6)->admin()->create();
        $category = $this->category('hour-limit');
        $url = "/admin/catalog/categories/{$category->slug}/archive";
        $ipAddress = '203.0.113.101';

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $this->actingAs($admins[$attempt % 5])
                ->withServerVariables(['REMOTE_ADDR' => $ipAddress])
                ->postJson($url, [])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('version');
        }

        $response = $this->actingAs($admins[5])
            ->withServerVariables(['REMOTE_ADDR' => $ipAddress])
            ->postJson($url, [])
            ->assertTooManyRequests();

        $response->assertHeader('Retry-After');
        $this->assertSame(0, $category->fresh()->lock_version);
    }

    private function category(string $slug): ServiceCategory
    {
        return ServiceCategory::query()->create([
            'slug' => $slug,
            'name_id' => 'Kategori',
            'name_en' => 'Category',
            'image_path' => '/img/category.jpg',
            'published_at' => now(),
            'lock_version' => 0,
            'is_active' => true,
            'display_order' => 1,
        ]);
    }
}
