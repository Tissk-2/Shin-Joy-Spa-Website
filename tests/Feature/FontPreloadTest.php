<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The fonts are only found once app.css has arrived, so pages first draw in a
// fallback font and then jump when the fonts land (0.385 layout shift on the
// booking settings page). Every page names them in the HTML instead.
class FontPreloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_preloads_the_fonts_app_css_uses(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        preg_match_all('#url\("(/fonts/[^"]+\.woff2)"\)#', $css, $matches);
        $fonts = $matches[1];
        $this->assertSame(['/fonts/marcellus-latin.woff2', '/fonts/mulish-latin.woff2'], $fonts);

        foreach (['/', '/login'] as $page) {
            $response = $this->get($page)->assertOk();
            foreach ($fonts as $font) {
                $this->assertFileExists(public_path(ltrim($font, '/')));
                $response->assertSee('<link rel="preload" href="'.$font.'" as="font" type="font/woff2" crossorigin>', false);
            }
        }
    }
}
