<?php

namespace Tests\Feature;

use Illuminate\Foundation\Vite;
use Tests\TestCase;

class ViteHotFileTest extends TestCase
{
    public function test_the_testing_environment_variable_redirects_the_vite_hot_file(): void
    {
        $hotFile = base_path('storage/framework/testing/playwright-vite.hot');

        $this->assertSame($this->normalize($hotFile), $this->normalize($this->hotFileWith($hotFile)));
    }

    public function test_the_default_hot_file_is_used_when_the_variable_is_unset_or_empty(): void
    {
        // Vite::hotFile() falls back to public_path('/hot'), which joinPaths renders with a double slash.
        $default = $this->normalize(public_path('/hot'));

        $this->assertSame($default, $this->normalize($this->hotFileWith(null)));
        $this->assertSame($default, $this->normalize($this->hotFileWith('')));
    }

    private function hotFileWith(?string $value): string
    {
        $previousPutenv = getenv('LARAVEL_VITE_HOT_FILE');
        $previousEnv = $_ENV['LARAVEL_VITE_HOT_FILE'] ?? null;
        $previousServer = $_SERVER['LARAVEL_VITE_HOT_FILE'] ?? null;

        try {
            if ($value === null) {
                putenv('LARAVEL_VITE_HOT_FILE');
                unset($_ENV['LARAVEL_VITE_HOT_FILE'], $_SERVER['LARAVEL_VITE_HOT_FILE']);
            } else {
                putenv('LARAVEL_VITE_HOT_FILE='.$value);
                $_ENV['LARAVEL_VITE_HOT_FILE'] = $value;
                $_SERVER['LARAVEL_VITE_HOT_FILE'] = $value;
            }

            $app = $this->createApplication();

            try {
                return $app->make(Vite::class)->hotFile();
            } finally {
                $app->flush();
            }
        } finally {
            $this->restoreVariable('putenv', $previousPutenv, 'putenv');
            $this->restoreVariable('_ENV', $previousEnv, 'array');
            $this->restoreVariable('_SERVER', $previousServer, 'array');
        }
    }

    private function restoreVariable(string $target, string|false|null $value, string $kind): void
    {
        if ($kind === 'putenv') {
            is_string($value) ? putenv('LARAVEL_VITE_HOT_FILE='.$value) : putenv('LARAVEL_VITE_HOT_FILE');

            return;
        }

        if (is_string($value)) {
            $target === '_ENV'
                ? $_ENV['LARAVEL_VITE_HOT_FILE'] = $value
                : $_SERVER['LARAVEL_VITE_HOT_FILE'] = $value;

            return;
        }

        unset($_ENV['LARAVEL_VITE_HOT_FILE'], $_SERVER['LARAVEL_VITE_HOT_FILE']);
    }

    private function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
