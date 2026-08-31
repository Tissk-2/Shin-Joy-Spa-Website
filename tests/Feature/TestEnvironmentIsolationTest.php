<?php

namespace Tests\Feature;

use Tests\TestCase;

class TestEnvironmentIsolationTest extends TestCase
{
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

    public function test_phpunit_boot_ignores_local_cached_configuration_and_developer_secrets(): void
    {
        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('sync', config('queue.default'));
        $this->assertSame('array', config('session.driver'));
        $this->assertSame('array', config('mail.default'));

        $this->assertTrue(
            hash_equals(
                'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
                (string) config('app.key'),
            ),
            'The test application must use its explicit non-secret application key.',
        );

        foreach (self::SENSITIVE_CONFIGURATION as $key) {
            $this->assertTrue(
                $this->isBlank(config($key)),
                "The test application must not import developer configuration for [{$key}].",
            );
        }
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
