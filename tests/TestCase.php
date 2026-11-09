<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Tests;

use KenPrz\CustomFields\CustomFieldsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [CustomFieldsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
    }
}
