<?php

namespace Tests\Feature;

use Illuminate\Support\Env;
use Tests\TestCase;

class TelegramConfigurationTest extends TestCase
{
    private const TELEGRAM_ENVIRONMENT_KEYS = [
        'TELEGRAM_BOOKING_NOTIFICATIONS_ENABLED',
        'TELEGRAM_ACTIONS_ENABLED',
        'TELEGRAM_BOT_TOKEN',
        'TELEGRAM_BOT_USERNAME',
        'TELEGRAM_WEBHOOK_SECRET',
        'TELEGRAM_STAFF_CHAT_ID',
        'TELEGRAM_MESSAGE_THREAD_ID',
        'TELEGRAM_CA_BUNDLE',
    ];

    public function test_booted_testing_configuration_defaults_to_a_disabled_feature_without_credentials(): void
    {
        $this->assertSafeTelegramDefaults(config('services.telegram'));
    }

    public function test_telegram_configuration_defaults_to_a_disabled_feature_without_credentials(): void
    {
        $environment = Env::getRepository();
        $originalValues = $this->snapshotTelegramEnvironment();

        foreach (self::TELEGRAM_ENVIRONMENT_KEYS as $key) {
            $environment->clear($key);
        }

        try {
            $services = require config_path('services.php');
            $telegram = $services['telegram'];

            $this->assertSafeTelegramDefaults($telegram);
        } finally {
            $this->restoreTelegramEnvironment($originalValues);
        }
    }

    public function test_telegram_configuration_uses_the_deployment_environment_variable_mappings(): void
    {
        $environment = Env::getRepository();
        $originalValues = $this->snapshotTelegramEnvironment();
        $values = [
            'TELEGRAM_BOOKING_NOTIFICATIONS_ENABLED' => 'true',
            'TELEGRAM_ACTIONS_ENABLED' => 'true',
            'TELEGRAM_BOT_TOKEN' => 'not-a-real-bot-token',
            'TELEGRAM_BOT_USERNAME' => 'not_a_real_bot',
            'TELEGRAM_WEBHOOK_SECRET' => 'not-a-real-webhook-secret',
            'TELEGRAM_STAFF_CHAT_ID' => 'not-a-real-chat-id',
            'TELEGRAM_MESSAGE_THREAD_ID' => 'not-a-real-topic-id',
            'TELEGRAM_CA_BUNDLE' => 'storage/app/private/ca.pem',
        ];

        foreach (self::TELEGRAM_ENVIRONMENT_KEYS as $key) {
            $environment->clear($key);
        }

        foreach ($values as $key => $value) {
            $environment->set($key, $value);
        }

        try {
            $telegram = require config_path('services.php');
            $telegram = $telegram['telegram'];

            $this->assertTrue($telegram['booking_notifications_enabled']);
            $this->assertTrue($telegram['actions_enabled']);
            $this->assertSame('not-a-real-bot-token', $telegram['bot_token']);
            $this->assertSame('not_a_real_bot', $telegram['bot_username']);
            $this->assertSame('not-a-real-webhook-secret', $telegram['webhook_secret']);
            $this->assertSame('not-a-real-chat-id', $telegram['staff_chat_id']);
            $this->assertSame('not-a-real-topic-id', $telegram['message_thread_id']);
            $this->assertSame('storage/app/private/ca.pem', $telegram['ca_bundle']);
        } finally {
            $this->restoreTelegramEnvironment($originalValues);
        }
    }

    public function test_the_actions_switch_reads_the_usual_true_and_false_spellings(): void
    {
        $environment = Env::getRepository();
        $originalValues = $this->snapshotTelegramEnvironment();

        try {
            // The keyboard checks for a strict true, so TELEGRAM_ACTIONS_ENABLED=1 must not leave the buttons off.
            foreach (['true' => true, '1' => true, 'yes' => true, 'on' => true, 'false' => false, '0' => false, '' => false] as $value => $expected) {
                $environment->clear('TELEGRAM_ACTIONS_ENABLED');
                $environment->set('TELEGRAM_ACTIONS_ENABLED', (string) $value);

                $this->assertSame($expected, (require config_path('services.php'))['telegram']['actions_enabled'], "value '{$value}'");
            }
        } finally {
            $this->restoreTelegramEnvironment($originalValues);
        }
    }

    public function test_the_notifications_switch_reads_the_usual_true_and_false_spellings(): void
    {
        $environment = Env::getRepository();
        $originalValues = $this->snapshotTelegramEnvironment();

        try {
            // Laravel turns only true/false-style words into booleans, so "off" or "no" would stay a string and count as on.
            foreach (['true' => true, '1' => true, 'yes' => true, 'on' => true, 'false' => false, '0' => false, 'no' => false, 'off' => false, '' => false] as $value => $expected) {
                $environment->clear('TELEGRAM_BOOKING_NOTIFICATIONS_ENABLED');
                $environment->set('TELEGRAM_BOOKING_NOTIFICATIONS_ENABLED', (string) $value);

                $this->assertSame($expected, (require config_path('services.php'))['telegram']['booking_notifications_enabled'], "value '{$value}'");
            }
        } finally {
            $this->restoreTelegramEnvironment($originalValues);
        }
    }

    public function test_telegram_environment_example_contains_exactly_one_safe_placeholder_for_each_setting(): void
    {
        $lines = file(base_path('.env.example'), FILE_IGNORE_NEW_LINES);

        $this->assertIsArray($lines);

        foreach ([
            'TELEGRAM_BOOKING_NOTIFICATIONS_ENABLED' => 'false',
            'TELEGRAM_ACTIONS_ENABLED' => 'false',
            'TELEGRAM_BOT_TOKEN' => '',
            'TELEGRAM_BOT_USERNAME' => '',
            'TELEGRAM_WEBHOOK_SECRET' => '',
            'TELEGRAM_STAFF_CHAT_ID' => '',
            'TELEGRAM_MESSAGE_THREAD_ID' => '',
            'TELEGRAM_CA_BUNDLE' => '',
        ] as $key => $value) {
            $matches = array_values(array_filter(
                $lines,
                static fn (string $line): bool => str_starts_with($line, $key.'='),
            ));

            $this->assertSame([$key.'='.$value], $matches);
        }
    }

    public function test_telegram_configuration_files_do_not_contain_credentials_or_secret_like_fallbacks(): void
    {
        $servicesConfiguration = file_get_contents(config_path('services.php'));
        $environmentExample = file_get_contents(base_path('.env.example'));

        if ($servicesConfiguration === false || $environmentExample === false) {
            $this->fail('Telegram configuration files must be readable for credential-safety checks.');
        }

        $botTokenPattern = '/\b\d{6,12}:[A-Za-z0-9_-]{30,}\b/';

        $this->assertDoesNotMatchRegularExpression($botTokenPattern, $servicesConfiguration);
        $this->assertDoesNotMatchRegularExpression($botTokenPattern, $environmentExample);

        foreach ([
            'TELEGRAM_BOT_TOKEN',
            'TELEGRAM_WEBHOOK_SECRET',
            'TELEGRAM_STAFF_CHAT_ID',
            'TELEGRAM_MESSAGE_THREAD_ID',
        ] as $key) {
            $fallbackPattern = '/env\(\s*[\'\"]'.preg_quote($key, '/').'[\'\"]\s*,/';

            $this->assertDoesNotMatchRegularExpression($fallbackPattern, $servicesConfiguration);
            $this->assertDoesNotMatchRegularExpression('/^'.preg_quote($key, '/').'=.+$/m', $environmentExample);
        }
    }

    private function assertSafeTelegramDefaults(mixed $telegram): void
    {
        $this->assertIsArray($telegram);
        $this->assertEqualsCanonicalizing([
            'booking_notifications_enabled',
            'actions_enabled',
            'bot_token',
            'bot_username',
            'webhook_secret',
            'staff_chat_id',
            'message_thread_id',
            'ca_bundle',
        ], array_keys($telegram));
        $this->assertFalse($telegram['booking_notifications_enabled']);
        $this->assertFalse($telegram['actions_enabled']);
        $this->assertNull($telegram['bot_token']);
        $this->assertNull($telegram['bot_username']);
        $this->assertNull($telegram['webhook_secret']);
        $this->assertNull($telegram['staff_chat_id']);
        $this->assertNull($telegram['message_thread_id']);
        $this->assertNull($telegram['ca_bundle']);
    }

    /**
     * @return array<string, array{exists: bool, value: string|null}>
     */
    private function snapshotTelegramEnvironment(): array
    {
        $environment = Env::getRepository();
        $snapshot = [];

        foreach (self::TELEGRAM_ENVIRONMENT_KEYS as $key) {
            $snapshot[$key] = [
                'exists' => $environment->has($key),
                'value' => $environment->get($key),
            ];
        }

        return $snapshot;
    }

    /**
     * @param  array<string, array{exists: bool, value: string|null}>  $snapshot
     */
    private function restoreTelegramEnvironment(array $snapshot): void
    {
        $environment = Env::getRepository();

        foreach (self::TELEGRAM_ENVIRONMENT_KEYS as $key) {
            $environment->clear($key);

            if ($snapshot[$key]['exists']) {
                $environment->set($key, (string) $snapshot[$key]['value']);
            }
        }
    }
}
