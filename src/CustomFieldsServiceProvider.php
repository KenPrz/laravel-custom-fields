<?php

declare(strict_types=1);

namespace KenPrz\CustomFields;

use Illuminate\Support\ServiceProvider;

class CustomFieldsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/custom-fields.php', 'custom-fields');

        $this->app->singleton(FieldTypeRegistry::class, function ($app) {
            $registry = new FieldTypeRegistry($app);
            $registry->registerBuiltIns();

            return $registry;
        });

        // Scoped, so loaded field lists are dropped between Octane requests and queue jobs.
        $this->app->scoped(CustomFieldManager::class);
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        if (config('custom-fields.run_migrations')) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        $this->publishes([
            __DIR__.'/../config/custom-fields.php' => config_path('custom-fields.php'),
        ], 'custom-fields-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'custom-fields-migrations');
    }
}
