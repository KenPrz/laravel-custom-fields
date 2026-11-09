<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Tests;

use KenPrz\CustomFields\CustomFieldsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

use function Orchestra\Testbench\load_migration_paths;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [CustomFieldsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // CI sets DB_CONNECTION to mysql or pgsql. Locally this is in-memory SQLite.
        $app['config']->set('database.default', env('DB_CONNECTION', 'testing'));

        // Registered on the migrator (not migrated directly) so migrate:fresh picks it up.
        load_migration_paths($app, __DIR__.'/Fixtures/migrations');
    }
}
