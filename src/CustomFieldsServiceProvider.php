<?php

declare(strict_types=1);

namespace KenPrz\CustomFields;

use Illuminate\Support\ServiceProvider;

class CustomFieldsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/custom-fields.php', 'custom-fields');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/custom-fields.php' => config_path('custom-fields.php'),
        ], 'custom-fields-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'custom-fields-migrations');
    }
}
