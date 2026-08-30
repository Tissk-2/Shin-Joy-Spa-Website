<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SecurityConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_controlled_production_configuration_meets_security_requirements(): void
    {
        $controlledConfiguration = [
            'app.env' => 'production',
            'app.debug' => false,
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
            'session.driver' => 'database',
            'mail.from.address' => 'noreply@example.invalid',
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'controlled_database',
            'database.connections.mysql.username' => 'controlled_user',
            'database.connections.mysql.password' => 'controlled_password',
        ];
        $originalConfiguration = [];

        foreach (array_keys($controlledConfiguration) as $key) {
            $originalConfiguration[$key] = Config::get($key);
        }

        try {
            Config::set($controlledConfiguration);

            $this->assertSame('production', config('app.env'));
            $this->assertFalse(config('app.debug'));
            $this->assertTrue(config('session.secure'));
            $this->assertTrue(config('session.http_only'));
            $this->assertSame('lax', config('session.same_site'));
            $this->assertSame('database', config('session.driver'));
            $this->assertNotSame('', config('mail.from.address'));
            $this->assertSame('mysql', config('database.default'));
            $this->assertSame('controlled_database', config('database.connections.mysql.database'));
            $this->assertSame('controlled_user', config('database.connections.mysql.username'));
            $this->assertSame('controlled_password', config('database.connections.mysql.password'));
        } finally {
            Config::set($originalConfiguration);
        }
    }

    public function test_mysql_ssl_configuration_does_not_raise_php_deprecations(): void
    {
        $environment = Env::getRepository();
        $environment->set('MYSQL_ATTR_SSL_CA', 'controlled-ca.pem');
        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        }, E_DEPRECATED);

        try {
            $database = require config_path('database.php');
        } finally {
            restore_error_handler();
            $environment->clear('MYSQL_ATTR_SSL_CA');
        }

        $sslAttribute = PHP_VERSION_ID >= 80500
            ? \Pdo\Mysql::ATTR_SSL_CA
            : \PDO::MYSQL_ATTR_SSL_CA;

        $this->assertArrayHasKey($sslAttribute, $database['connections']['mysql']['options']);
        $this->assertArrayHasKey($sslAttribute, $database['connections']['mariadb']['options']);
    }

    public function test_testing_link_helpers_normalize_email_and_return_only_a_url(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'ayu@example.com',
        ]);

        $verification = $this->getJson('/__testing/verification-link?email=AYU%40EXAMPLE.COM');
        $reset = $this->getJson('/__testing/reset-link?email=AYU%40EXAMPLE.COM');

        $verification->assertOk()->assertJsonStructure(['url'])->assertJsonCount(1);
        $reset->assertOk()->assertJsonStructure(['url'])->assertJsonCount(1);

        $this->assertTrue(URL()->hasValidSignature(
            \Illuminate\Http\Request::create($verification->json('url')),
        ));
        $this->assertStringContainsString('/verify-email/'.$user->getKey().'/', $verification->json('url'));
        $this->assertStringContainsString('/reset-password/', $reset->json('url'));
        $this->assertStringContainsString('email=ayu%40example.com', $reset->json('url'));

        foreach (['testing.verification-link', 'testing.reset-link'] as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);

            $this->assertNotNull($route);
            $this->assertContains('throttle:10,1', $route->gatherMiddleware());
        }
    }

    public function test_testing_link_helpers_validate_email_input(): void
    {
        $this->getJson('/__testing/verification-link?email=not-an-email')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->getJson('/__testing/reset-link')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_testing_link_helpers_are_not_registered_in_production(): void
    {
        $originalEnvironment = $this->app->environment();
        $originalRouter = $this->app['router'];
        $productionRouter = new Router($this->app['events'], $this->app);

        try {
            $this->app->detectEnvironment(static fn (): string => 'production');
            $this->app->instance('router', $productionRouter);
            Route::swap($productionRouter);

            require base_path('routes/web.php');

            $this->assertNull($productionRouter->getRoutes()->getByName('testing.verification-link'));
            $this->assertNull($productionRouter->getRoutes()->getByName('testing.reset-link'));
        } finally {
            $this->app->detectEnvironment(static fn (): string => $originalEnvironment);
            $this->app->instance('router', $originalRouter);
            Route::swap($originalRouter);
        }
    }
}
