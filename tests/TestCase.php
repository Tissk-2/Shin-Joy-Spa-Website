<?php

namespace Tests;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    private const TEST_APPLICATION_KEY = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

    private const TEST_ENVIRONMENT = [
        'APP_ENV' => 'testing',
        'APP_KEY' => self::TEST_APPLICATION_KEY,
        'APP_PREVIOUS_KEYS' => '',
        'APP_DEBUG' => 'false',
        'APP_URL' => 'http://localhost',
        'APP_MAINTENANCE_DRIVER' => 'file',
        'BCRYPT_ROUNDS' => '4',
        'CACHE_STORE' => 'array',
        'DB_CONNECTION' => 'sqlite',
        'DB_URL' => '',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '1',
        'DB_DATABASE' => ':memory:',
        'DB_USERNAME' => '',
        'DB_PASSWORD' => '',
        'DB_SOCKET' => '',
        'REDIS_URL' => '',
        'REDIS_USERNAME' => '',
        'REDIS_PASSWORD' => '',
        'MEMCACHED_USERNAME' => '',
        'MEMCACHED_PASSWORD' => '',
        'MAIL_MAILER' => 'array',
        'MAIL_URL' => '',
        'MAIL_USERNAME' => '',
        'MAIL_PASSWORD' => '',
        'POSTMARK_TOKEN' => '',
        'RESEND_KEY' => '',
        'AWS_ACCESS_KEY_ID' => '',
        'AWS_SECRET_ACCESS_KEY' => '',
        'LOG_SLACK_WEBHOOK_URL' => '',
        'PAPERTRAIL_URL' => '',
        'SLACK_BOT_USER_OAUTH_TOKEN' => '',
        'PULSE_ENABLED' => 'false',
        'QUEUE_CONNECTION' => 'sync',
        'SESSION_DRIVER' => 'array',
        'TELESCOPE_ENABLED' => 'false',
    ];

    private const EXPECTED_TEST_CONFIGURATION = [
        'app.env' => 'testing',
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => ':memory:',
        'cache.default' => 'array',
        'queue.default' => 'sync',
        'session.driver' => 'array',
        'mail.default' => 'array',
        'app.key' => self::TEST_APPLICATION_KEY,
    ];

    private const SENSITIVE_CONFIGURATION = [
        'app.previous_keys',
        'database.connections.mysql.url',
        'database.connections.mysql.username',
        'database.connections.mysql.password',
        'database.connections.mariadb.url',
        'database.connections.mariadb.username',
        'database.connections.mariadb.password',
        'database.connections.pgsql.url',
        'database.connections.pgsql.username',
        'database.connections.pgsql.password',
        'database.connections.sqlsrv.url',
        'database.connections.sqlsrv.username',
        'database.connections.sqlsrv.password',
        'database.redis.default.url',
        'database.redis.default.username',
        'database.redis.default.password',
        'database.redis.cache.url',
        'database.redis.cache.username',
        'database.redis.cache.password',
        'mail.mailers.smtp.url',
        'mail.mailers.smtp.username',
        'mail.mailers.smtp.password',
        'cache.stores.memcached.sasl.0',
        'cache.stores.memcached.sasl.1',
        'cache.stores.dynamodb.key',
        'cache.stores.dynamodb.secret',
        'filesystems.disks.s3.key',
        'filesystems.disks.s3.secret',
        'logging.channels.slack.url',
        'logging.channels.papertrail.handler_with.host',
        'queue.connections.sqs.key',
        'queue.connections.sqs.secret',
        'services.postmark.token',
        'services.ses.key',
        'services.ses.secret',
        'services.resend.key',
        'services.slack.notifications.bot_user_oauth_token',
    ];

    public function createApplication()
    {
        $this->forceIsolatedTestEnvironment();

        $app = parent::createApplication();

        $this->assertIsolatedTestConfiguration($app);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function forceIsolatedTestEnvironment(): void
    {
        $environment = [
            'APP_CONFIG_CACHE' => 'storage/framework/testing/phpunit-config-'.getmypid().'.php',
            ...self::TEST_ENVIRONMENT,
        ];

        foreach ($environment as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    private function assertIsolatedTestConfiguration(Application $app): void
    {
        $config = $app->make('config');

        foreach (self::EXPECTED_TEST_CONFIGURATION as $key => $expected) {
            if (! hash_equals($expected, (string) $config->get($key))) {
                throw new RuntimeException(
                    "Refusing to run tests because [{$key}] is not safely isolated.",
                );
            }
        }

        foreach (self::SENSITIVE_CONFIGURATION as $key) {
            if (! $this->isBlank($config->get($key))) {
                throw new RuntimeException(
                    "Refusing to run tests because [{$key}] contains non-testing configuration.",
                );
            }
        }
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
