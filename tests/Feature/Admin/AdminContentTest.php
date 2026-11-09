<?php

namespace Tests\Feature\Admin;

use App\Models\PageContent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
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
        $this->assertSame(0, $props['revision']);
        $this->assertSame('Judul khusus', $props['values']['hero.heading']['id']);
        $this->assertSame('', $props['values']['hero.heading']['en']);
    }

    public function test_saving_both_languages_persists_and_shows_the_override_on_the_public_home_page(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/admin/content', [
                'revision' => 0,
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
                'revision' => 0,
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
                'revision' => 0,
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
                'revision' => 0,
                'values' => [
                    'nav.brand' => ['id' => 'Injected', 'en' => 'Injected'],
                ],
            ])
            ->assertRedirect('/admin/content')
            ->assertSessionHasErrors('values');

        $this->actingAs($admin)
            ->from('/admin/content')
            ->post('/admin/content', [
                'revision' => 0,
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
                'revision' => 0,
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
            ->post('/admin/content/reset', ['revision' => 0])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(0, PageContent::query()->count());
    }

    public function test_saving_one_key_leaves_every_omitted_key_untouched(): void
    {
        $admin = User::factory()->admin()->create();
        PageContent::query()->create([
            'key' => 'hero.heading',
            'value_id' => 'Lama',
            'value_en' => 'Old',
        ]);
        PageContent::query()->create([
            'key' => 'about.body',
            'value_id' => 'Tubuh',
            'value_en' => 'Body',
        ]);

        $this->actingAs($admin)
            ->post('/admin/content', [
                'revision' => 0,
                'values' => [
                    'hero.heading' => ['id' => 'Baru', 'en' => 'New'],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $heading = PageContent::query()->where('key', 'hero.heading')->firstOrFail();
        $this->assertSame('Baru', $heading->value_id);
        $this->assertSame('New', $heading->value_en);

        $body = PageContent::query()->where('key', 'about.body')->firstOrFail();
        $this->assertSame('Tubuh', $body->value_id);
        $this->assertSame('Body', $body->value_en);
        $this->assertSame(1, $this->revision());
    }

    public function test_an_omitted_locale_keeps_its_stored_value(): void
    {
        $admin = User::factory()->admin()->create();
        PageContent::query()->create([
            'key' => 'hero.sub',
            'value_id' => 'Lama',
            'value_en' => 'Old',
        ]);

        $this->actingAs($admin)
            ->post('/admin/content', [
                'revision' => 0,
                'values' => [
                    'hero.sub' => ['id' => 'Baru'],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $stored = PageContent::query()->where('key', 'hero.sub')->firstOrFail();
        $this->assertSame('Baru', $stored->value_id);
        $this->assertSame('Old', $stored->value_en);
    }

    public function test_an_explicit_empty_locale_clears_only_that_locale_and_both_empty_deletes_the_row(): void
    {
        $admin = User::factory()->admin()->create();
        PageContent::query()->create([
            'key' => 'hero.sub',
            'value_id' => 'Lama',
            'value_en' => 'Old',
        ]);

        $this->actingAs($admin)
            ->post('/admin/content', [
                'revision' => 0,
                'values' => [
                    'hero.sub' => ['en' => ''],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $stored = PageContent::query()->where('key', 'hero.sub')->firstOrFail();
        $this->assertSame('Lama', $stored->value_id);
        $this->assertNull($stored->value_en);

        $this->actingAs($admin)
            ->post('/admin/content', [
                'revision' => 1,
                'values' => [
                    'hero.sub' => ['id' => '   '],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(0, PageContent::query()->where('key', 'hero.sub')->count());
        $this->assertSame(2, $this->revision());
    }

    public function test_missing_or_empty_values_are_rejected_without_touching_rows_or_revision(): void
    {
        $admin = User::factory()->admin()->create();
        PageContent::query()->create([
            'key' => 'hero.heading',
            'value_id' => 'Tetap',
            'value_en' => 'Stay',
        ]);
        PageContent::query()->create([
            'key' => 'about.body',
            'value_id' => 'Tetap',
            'value_en' => 'Stay',
        ]);

        $this->actingAs($admin)
            ->from('/admin/content')
            ->post('/admin/content', ['revision' => 0])
            ->assertRedirect('/admin/content')
            ->assertSessionHasErrors('values');

        $this->actingAs($admin)
            ->from('/admin/content')
            ->post('/admin/content', ['revision' => 0, 'values' => []])
            ->assertRedirect('/admin/content')
            ->assertSessionHasErrors('values');

        $this->actingAs($admin)
            ->from('/admin/content')
            ->post('/admin/content', [
                'revision' => 0,
                'values' => ['hero.heading' => []],
            ])
            ->assertRedirect('/admin/content')
            ->assertSessionHasErrors('values');

        $this->assertSame(2, PageContent::query()->count());
        $this->assertSame(
            'Tetap',
            PageContent::query()->where('key', 'hero.heading')->firstOrFail()->value_id,
        );
        $this->assertSame(
            'Stay',
            PageContent::query()->where('key', 'about.body')->firstOrFail()->value_en,
        );
        $this->assertSame(0, $this->revision());
    }

    public function test_a_literal_zero_is_stored_instead_of_being_treated_as_empty(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/admin/content', [
                'revision' => 0,
                'values' => [
                    'hero.eyebrow' => ['id' => '0'],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $stored = PageContent::query()->where('key', 'hero.eyebrow')->firstOrFail();
        $this->assertSame('0', $stored->value_id);
        $this->assertNull($stored->value_en);
        $this->assertSame(1, $this->revision());
    }

    public function test_a_stale_revision_is_rejected_and_writes_nothing(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/admin/content', [
                'revision' => 0,
                'values' => ['hero.heading' => ['id' => 'Pertama']],
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(1, $this->revision());

        $this->actingAs($admin)
            ->from('/admin/content')
            ->post('/admin/content', [
                'revision' => 0,
                'values' => ['hero.heading' => ['id' => 'Kedua']],
            ])
            ->assertRedirect('/admin/content')
            ->assertSessionHasErrors('revision');

        $this->assertSame(
            'Pertama',
            PageContent::query()->where('key', 'hero.heading')->firstOrFail()->value_id,
        );
        $this->assertSame(1, $this->revision());
    }

    public function test_a_failure_part_way_through_a_save_rolls_back_every_key_and_the_revision(): void
    {
        $admin = User::factory()->admin()->create();
        PageContent::query()->create([
            'key' => 'hero.heading',
            'value_id' => 'Lama',
            'value_en' => null,
        ]);
        PageContent::query()->create([
            'key' => 'about.body',
            'value_id' => 'Lama',
            'value_en' => null,
        ]);

        $writes = 0;
        DB::beforeExecuting(function (string $sql) use (&$writes): void {
            if (preg_match('/^(insert|update) .*["`]page_contents["`]/i', $sql) && ++$writes === 2) {
                throw new RuntimeException('second write fails');
            }
        });

        try {
            $this->actingAs($admin)
                ->withoutExceptionHandling()
                ->post('/admin/content', [
                    'revision' => 0,
                    'values' => [
                        'hero.heading' => ['id' => 'Baru'],
                        'about.body' => ['id' => 'Baru'],
                    ],
                ]);
            $this->fail('The second page_contents write should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('second write fails', $exception->getMessage());
        }

        $this->assertSame(2, $writes);
        $this->assertSame(
            'Lama',
            PageContent::query()->where('key', 'hero.heading')->firstOrFail()->value_id,
        );
        $this->assertSame(
            'Lama',
            PageContent::query()->where('key', 'about.body')->firstOrFail()->value_id,
        );
        $this->assertSame(0, $this->revision());
    }

    public function test_reset_requires_a_current_revision_before_deleting_anything(): void
    {
        $admin = User::factory()->admin()->create();
        PageContent::query()->create([
            'key' => 'hero.heading',
            'value_id' => 'X',
            'value_en' => 'Y',
        ]);

        $this->actingAs($admin)
            ->post('/admin/content', [
                'revision' => 0,
                'values' => ['hero.heading' => ['id' => 'X2']],
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(1, $this->revision());

        $this->actingAs($admin)
            ->from('/admin/content')
            ->post('/admin/content/reset', [])
            ->assertRedirect('/admin/content')
            ->assertSessionHasErrors('revision');
        $this->assertSame(1, PageContent::query()->count());

        $this->actingAs($admin)
            ->from('/admin/content')
            ->post('/admin/content/reset', ['revision' => 0])
            ->assertRedirect('/admin/content')
            ->assertSessionHasErrors('revision');
        $this->assertSame(1, PageContent::query()->count());
        $this->assertSame(1, $this->revision());

        $this->actingAs($admin)
            ->post('/admin/content/reset', ['revision' => 1])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(0, PageContent::query()->count());
        $this->assertSame(2, $this->revision());
    }

    public function test_content_save_is_throttled_after_the_catalog_write_limit(): void
    {
        $admin = User::factory()->admin()->create();
        $ipAddress = '203.0.113.66';

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->actingAs($admin)
                ->withServerVariables(['REMOTE_ADDR' => $ipAddress])
                ->postJson('/admin/content', [])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('revision');
        }

        $response = $this->actingAs($admin)
            ->withServerVariables(['REMOTE_ADDR' => $ipAddress])
            ->postJson('/admin/content', [])
            ->assertTooManyRequests();

        $response->assertHeader('Retry-After');
        $this->assertSame(0, $this->revision());
    }

    public function test_a_missing_revision_row_is_recreated_instead_of_locking_the_editor(): void
    {
        $admin = User::factory()->admin()->create();
        DB::table('content_revisions')->delete();

        $this->actingAs($admin)
            ->post('/admin/content', [
                'revision' => 0,
                'values' => ['hero.heading' => ['id' => 'Pulih']],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Pulih', PageContent::query()->where('key', 'hero.heading')->value('value_id'));
        $this->assertSame(1, $this->revision());
    }

    private function revision(): int
    {
        return (int) DB::table('content_revisions')->where('id', 1)->value('revision');
    }
}
