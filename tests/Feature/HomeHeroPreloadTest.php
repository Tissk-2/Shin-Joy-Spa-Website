<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The hero <img> only exists once the page's JavaScript has run, so the HTML
// names the photo up front. Home.vue must use the same file (homeImages.test.ts).
class HomeHeroPreloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_home_page_preloads_its_hero_photo(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<link rel="preload" as="image" href="/img/hero.webp" fetchpriority="high">', false);

        $this->assertFileExists(public_path('img/hero.webp'));
    }

    public function test_other_pages_do_not_preload_it(): void
    {
        $this->get('/services')->assertOk()->assertDontSee('/img/hero.webp', false);
    }
}
