<?php

namespace Tests\Feature;

use ArrayObject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Vite as ViteFacade;
use Illuminate\Support\HtmlString;
use Tests\TestCase;

// The hero <img> only exists once the page's JavaScript has run, so the HTML
// names the photo up front. Home.vue must use the same srcset and sizes
// (homeImages.test.ts compares them), or the browser downloads it twice.
class HomeHeroPreloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_home_page_preloads_its_hero_photo(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<link rel="preload" as="image" href="/img/hero.webp" imagesrcset="/img/hero-1600.webp 1600w, /img/hero.webp 2000w" imagesizes="(min-aspect-ratio: 39/23) 100vw, 156vh" fetchpriority="high">', false);

        $this->assertFileExists(public_path('img/hero.webp'));
        $this->assertFileExists(public_path('img/hero-1600.webp'));
    }

    public function test_other_pages_do_not_preload_it(): void
    {
        $this->get('/services')->assertOk()->assertDontSee('/img/hero.webp', false);
    }

    public function test_each_page_requests_its_own_script_from_the_html(): void
    {
        // Without this the page script is only found once app.js has run, one
        // more round trip before anything (the hero included) can be drawn.
        $entries = new ArrayObject;
        ViteFacade::clearResolvedInstance();
        $this->swap(Vite::class, new class($entries) extends Vite
        {
            public function __construct(private ArrayObject $entries) {}

            public function __invoke($entrypoints, $buildDirectory = null)
            {
                $this->entries->exchangeArray((array) $entrypoints);

                return new HtmlString('');
            }

            public function __call($method, $parameters)
            {
                return '';
            }

            public function __toString(): string
            {
                return '';
            }
        });

        $this->get('/')->assertOk();
        $this->assertSame(['resources/js/app.ts', 'resources/js/pages/Home.vue'], $entries->getArrayCopy());

        $this->get('/login')->assertOk();
        $this->assertSame(['resources/js/app.ts', 'resources/js/pages/auth/Login.vue'], $entries->getArrayCopy());
    }
}
