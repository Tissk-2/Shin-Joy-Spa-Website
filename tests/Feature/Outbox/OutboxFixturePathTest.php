<?php

namespace Tests\Feature\Outbox;

use PHPUnit\Framework\TestCase;

// Windows and default macOS filesystems ignore case, so the outbox suite can
// pass locally while its fixture paths break on Linux CI. These checks walk
// real directory listings instead of trusting file_exists().
class OutboxFixturePathTest extends TestCase
{
    public function test_fixture_paths_match_on_disk_case_and_fixture_classes_are_required_explicitly(): void
    {
        $root = dirname(__DIR__, 3);
        $this->assertExactPath($root, 'tests/fixtures/outbox');

        $files = [];
        foreach ([__DIR__, $root.'/tests/fixtures/outbox'] as $directory) {
            foreach (scandir($directory) as $entry) {
                if (str_ends_with($entry, '.php')) {
                    $files[] = $directory.'/'.$entry;
                }
            }
        }

        foreach ($files as $file) {
            $source = file_get_contents($file);
            preg_match_all('~(require(?:_once)?\s+)?(__DIR__\s*\.\s*|\bbase_path\(\s*)([\'"])([^\'"]+\.php)\3~', $source, $matches, PREG_SET_ORDER);
            $requires = [];

            foreach ($matches as [, $require, $anchor, , $path]) {
                $absolute = (str_starts_with($anchor, '__DIR__') ? dirname($file) : $root).'/'.ltrim($path, '/');
                $relative = $this->relativeTo($root, $absolute);
                $this->assertExactPath($root, $relative, basename($file));
                if ($require !== '') {
                    $requires[] = $relative;
                }
            }

            // Composer maps Tests\ to tests/, which expects tests/Fixtures; on a
            // case-sensitive disk only an explicit require can load these classes.
            // ponytail: grouped `use ...\{A, B}` imports are not parsed; the suite uses none.
            preg_match_all('~Tests\\\\Fixtures\\\\Outbox\\\\(\w+)~', $source, $classes);
            foreach (array_unique($classes[1]) as $class) {
                $this->assertContains('tests/fixtures/outbox/'.$class.'.php', $requires, basename($file)." must require {$class}.php");
            }
        }
    }

    private function relativeTo(string $root, string $absolute): string
    {
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $absolute)) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '' && $segment !== '.') {
                $segments[] = $segment;
            }
        }
        $prefix = array_values(array_filter(explode('/', str_replace('\\', '/', $root)), fn ($segment) => $segment !== ''));

        $this->assertSame($prefix, array_slice($segments, 0, count($prefix)), "{$absolute} escapes the project root");

        return implode('/', array_slice($segments, count($prefix)));
    }

    private function assertExactPath(string $root, string $relative, string $from = 'suite'): void
    {
        $current = $root;
        foreach (explode('/', $relative) as $segment) {
            $this->assertContains($segment, is_dir($current) ? scandir($current) : [], "{$from}: '{$relative}' has no exact-case '{$segment}' in {$current}");
            $current .= '/'.$segment;
        }
    }
}
