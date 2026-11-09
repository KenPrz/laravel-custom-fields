<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Tests\Feature;

use KenPrz\CustomFields\CustomFieldsServiceProvider;
use KenPrz\CustomFields\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ServiceProviderTest extends TestCase
{
    #[Test]
    public function it_registers_the_provider(): void
    {
        $this->assertArrayHasKey(CustomFieldsServiceProvider::class, $this->app->getLoadedProviders());
    }

    #[Test]
    public function it_merges_the_default_config(): void
    {
        $this->assertSame('custom_fields', config('custom-fields.tables.fields'));
        $this->assertSame('custom_field_values', config('custom-fields.tables.values'));
    }

    #[Test]
    public function it_publishes_the_config_file(): void
    {
        $paths = CustomFieldsServiceProvider::pathsToPublish(CustomFieldsServiceProvider::class, 'custom-fields-config');

        $this->assertSame([realpath(__DIR__.'/../../config/custom-fields.php') => config_path('custom-fields.php')], $this->resolve($paths));
    }

    #[Test]
    public function it_publishes_the_migrations_from_the_package(): void
    {
        $paths = CustomFieldsServiceProvider::pathsToPublish(CustomFieldsServiceProvider::class, 'custom-fields-migrations');

        $this->assertSame([realpath(__DIR__.'/../../database/migrations') => database_path('migrations')], $this->resolve($paths));
    }

    /**
     * @param  array<string, string>  $paths
     * @return array<string, string>
     */
    private function resolve(array $paths): array
    {
        return collect($paths)->mapWithKeys(fn ($to, $from) => [realpath($from) ?: $from => $to])->all();
    }
}
