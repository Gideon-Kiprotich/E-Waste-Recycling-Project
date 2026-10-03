<?php

namespace Tests;

use Dotenv\Dotenv;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        if ($app->configurationIsCached()) {
            throw new RuntimeException('Tests refuse cached configuration. Run php artisan config:clear first.');
        }

        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $app->make(Kernel::class)->bootstrap();

        $database = $app['config']->get('database.connections.pgsql');

        // This runs before database traits or test code can execute.
        if (! $app->environment('testing')
            || $app['config']->get('database.default') !== 'pgsql'
            || $database['database'] !== 'ewaste_testing'
            || $database['host'] !== '127.0.0.1'
            || (string) $database['port'] !== '5432'
            || $database['username'] !== 'ewaste_app'
            || ! empty($database['url'])
            || $database['database'] === $this->developmentDatabase()) {
            throw new RuntimeException('Tests require the dedicated local ewaste_testing PostgreSQL database, distinct from development.');
        }

        return $app;
    }

    protected function developmentDatabase(): string
    {
        $path = __DIR__.'/../.env';

        if (! is_file($path)) {
            throw new RuntimeException('Create the local .env before running tests so database isolation can be verified.');
        }

        $environment = Dotenv::parse(file_get_contents($path));
        $url = $environment['DB_URL'] ?? '';
        $database = $url !== ''
            ? rawurldecode(ltrim((string) parse_url($url, PHP_URL_PATH), '/'))
            : ($environment['DB_DATABASE'] ?? '');

        if ($database === '') {
            throw new RuntimeException('The development database must be explicitly configured in .env.');
        }

        return $database;
    }
}
