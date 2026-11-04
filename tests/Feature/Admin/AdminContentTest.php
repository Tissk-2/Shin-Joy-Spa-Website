<?php

namespace Tests\Feature\Admin;

use App\Models\PageContent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_editor_is_admin_only_and_returns_sections_and_values(): void
    {
        $this->get('/admin/content')->assertRedirect(route('login', absolute: false));

        $customer = User::factory()->create();
        $this->actingAs($customer)->get('/admin/content')->assertForbidden();

        PageContent::query()->create([
            'key' => 'hero.heading',
            'value_id' => 'Judul khusus',
            'value_en' => null,
        ]);

        $admin = User::factory()->admin()->create();
        $response = $this->actingAs($admin)->get('/admin/content');
        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/Content', false)
            ->has('sections.hero', 4)
            ->has('sections.about', 3)
            ->has('sections.contact', 3));

        $props = $response->inertiaProps();
        $this->assertSame('Judul khusus', $props['values']['hero.heading']['id']);
        $this->assertSame('', $props['values']['hero.heading']['en']);
    }

    public function test_saving_both_languages_persists_and_shows_the_override_on_the_public_home_page(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/admin/content', [
                'values' => [
                    'hero.heading' => ['id' => '  Judul baru  ', 'en' => 'Fresh headline'],
                    'loc.address' => ['id' => 'Jl. Contoh No. 1', 'en' => 'Sample St. 1'],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(
            'Jl. Contoh No. 1',
            PageContent::query()->where('key', 'loc.address')->firstOrFail()->value_id,
        );
        $this->assertSame(
            'Sample St. 1',
            PageContent::query()->where('key', 'loc.address')->firstOrFail()->value_en,
        );
        $this->assertSame(
            'Judul baru',
            PageContent::query()->where('key', 'hero.heading')->firstOrFail()->value_id,
        );

        $this->get('/')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Home', false),
        );

        $props = $this->get('/')->assertOk()->inertiaProps();
        $this->assertSame('Judul baru', $props['content']['hero.heading']['id']);
        $this->assertSame(
            'Fresh headline',
            $props['content']['hero.heading']['en'],
        );
    }

    public function test_clearing_both_languages_reverts_that_key_to_the_shipped_copy(): void
    {
        $admin = User::factory()->admin()->create();
        PageContent::query()->create([
            'key' => 'about.title',
            'value_id' => 'Judul lama',
            'value_en' => 'Old title',
        ]);

        $this->actingAs($admin)
            ->post('/admin/content', [
                'values' => [
                    'about.title' => ['id' => '   ', 'en' => ''],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(0, PageContent::query()->where('key', 'about.title')->count());
    }

    public function test_filling_only_one_language_keeps_the_other_on_the_shipped_copy(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/admin/content', [
                'values' => [
                    'hero.sub' => ['id' => 'Hanya Indonesia', 'en' => ''],
                ],
            ])
            ->assertRedirect();

        $stored = PageContent::query()->where('key', 'hero.sub')->firstOrFail();
        $this->assertSame('Hanya Indonesia', $stored->value_id);
        $this->assertNull($stored->value_en);

        $props = $this->get('/')->assertOk()->inertiaProps();
        $this->assertSame(
            'Hanya Indonesia',
            $props['content']['hero.sub']['id'],
        );
        $this->assertArrayNotHasKey('en', $props['content']['hero.sub']);
    }

    public function test_unknown_keys_and_oversized_values_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->from('/admin/content')
            ->post('/admin/content', [
                'values' => [
                    'nav.brand' => ['id' => 'Injected', 'en' => 'Injected'],
                ],
            ])
            ->assertRedirect('/admin/content')
            ->assertSessionHasErrors('values');

        $this->actingAs($admin)
            ->from('/admin/content')
            ->post('/admin/content', [
                'values' => [
                    'gallery.title' => [
                        'id' => 'Foto lokasi terverifikasi',
                        'en' => 'Verified venue photos',
                    ],
                ],
            ])
            ->assertRedirect('/admin/content')
            ->assertSessionHasErrors('values');

        $this->actingAs($admin)
            ->from('/admin/content')
            ->post('/admin/content', [
                'values' => [
                    'hero.heading' => ['id' => str_repeat('a', 1001), 'en' => ''],
                ],
            ])
            ->assertRedirect('/admin/content')
            ->assertSessionHasErrors('values.hero.heading.id');

        $this->assertSame(0, PageContent::query()->count());
    }

    public function test_reset_removes_every_override_and_reports_status(): void
    {
        $admin = User::factory()->admin()->create();
        PageContent::query()->create(['key' => 'hero.heading', 'value_id' => 'X', 'value_en' => 'Y']);
        PageContent::query()->create(['key' => 'loc.hours', 'value_id' => 'Setiap hari', 'value_en' => null]);

        $this->actingAs($admin)
            ->post('/admin/content/reset')
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(0, PageContent::query()->count());
    }
}
