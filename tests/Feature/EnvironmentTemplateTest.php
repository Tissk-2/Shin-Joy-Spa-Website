<?php

namespace Tests\Feature;

use Dotenv\Dotenv;
use Tests\TestCase;

class EnvironmentTemplateTest extends TestCase
{
    public function test_the_template_exists_and_parses(): void
    {
        $this->assertNotEmpty($this->templateValues());
    }

    public function test_no_key_is_defined_twice(): void
    {
        // Mirrors phpdotenv, which also accepts `export ` prefixes and padded names.
        preg_match_all('/^\s*(?:export\s+)?([A-Z0-9_]+)\s*=/m', $this->templateContents(), $matches);
        $keys = $matches[1];

        $this->assertSame(
            array_unique($keys),
            $keys,
            'Duplicate keys: '.implode(', ', array_diff_assoc($keys, array_unique($keys))),
        );
    }

    public function test_app_key_is_present_and_empty(): void
    {
        $values = $this->templateValues();

        $this->assertArrayHasKey('APP_KEY', $values, '.env.example must define APP_KEY.');
        $this->assertSame('', $values['APP_KEY'], 'APP_KEY must stay empty in the template.');
    }

    public function test_secret_like_keys_use_empty_or_null_placeholders(): void
    {
        $checked = [];

        foreach ($this->templateValues() as $key => $value) {
            if (preg_match('/(KEY|SECRET|PASSWORD|TOKEN)(_ID)?$/', $key) !== 1) {
                continue;
            }

            $checked[] = $key;
            $this->assertContains(
                $value,
                ['', 'null'],
                "Secret-like key [{$key}] must be empty or null in the template.",
            );
        }

        // Guards against the loop silently checking nothing.
        foreach (['APP_KEY', 'DB_PASSWORD', 'REDIS_PASSWORD', 'MAIL_PASSWORD', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'TELEGRAM_BOT_TOKEN'] as $key) {
            $this->assertContains($key, $checked, "Secret-like key [{$key}] must be present and checked.");
        }
    }

    public function test_every_enabled_flag_defaults_to_false(): void
    {
        $values = $this->templateValues();
        $enabledKeys = array_values(array_filter(
            array_keys($values),
            static fn (string $key): bool => str_ends_with($key, '_ENABLED'),
        ));

        $this->assertContains(
            'BOOKING_SCHEDULING_ENABLED',
            $enabledKeys,
            '.env.example must document BOOKING_SCHEDULING_ENABLED.',
        );
        $this->assertContains(
            'TELEGRAM_BOOKING_NOTIFICATIONS_ENABLED',
            $enabledKeys,
            '.env.example must document TELEGRAM_BOOKING_NOTIFICATIONS_ENABLED.',
        );

        foreach ($enabledKeys as $key) {
            $this->assertSame('false', $values[$key], "[{$key}] must default to false.");
        }
    }

    public function test_project_keys_from_configuration_are_documented(): void
    {
        $projectKeys = array_merge(
            $this->keysUsedBy('booking.php'),
            array_filter(
                $this->keysUsedBy('services.php'),
                static fn (string $key): bool => str_starts_with($key, 'TELEGRAM_'),
            ),
        );

        $this->assertContains('BOOKING_SCHEDULING_ENABLED', $projectKeys, 'The config key scan found no booking keys.');
        $this->assertContains('TELEGRAM_BOT_TOKEN', $projectKeys, 'The config key scan found no Telegram keys.');

        $values = $this->templateValues();

        foreach (array_unique($projectKeys) as $key) {
            $this->assertArrayHasKey($key, $values, "Project key [{$key}] must appear in .env.example.");
        }
    }

    public function test_the_template_contains_no_bot_token_or_credentialed_url(): void
    {
        $contents = $this->templateContents();

        $this->assertDoesNotMatchRegularExpression(
            '/\b\d{6,12}:[A-Za-z0-9_-]{30,}\b/',
            $contents,
            '.env.example must not contain a Telegram bot token.',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/:\/\/[^\s\/:@]+:[^\s\/@]+@/',
            $contents,
            '.env.example must not contain a URL with embedded credentials.',
        );
    }

    private function templateContents(): string
    {
        $path = base_path('.env.example');

        $this->assertFileExists($path, '.env.example must exist so operators have a safe template.');

        $contents = file_get_contents($path);
        $this->assertIsString($contents, '.env.example must be readable.');

        return $contents;
    }

    /** @return array<string, string|null> */
    private function templateValues(): array
    {
        try {
            return Dotenv::parse($this->templateContents());
        } catch (\Throwable $exception) {
            $this->fail('.env.example must parse as a dotenv file: '.$exception->getMessage());
        }
    }

    /** @return list<string> */
    private function keysUsedBy(string $configFile): array
    {
        $contents = file_get_contents(config_path($configFile));
        $this->assertIsString($contents, "config/{$configFile} must be readable.");

        preg_match_all('/env\(\s*[\'"]([A-Z0-9_]+)[\'"]/', $contents, $matches);

        return $matches[1];
    }
}
