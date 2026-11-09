<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use KenPrz\CustomFields\CustomFieldsServiceProvider;
use KenPrz\CustomFields\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;

class MigrationTest extends TestCase
{
    use DatabaseMigrations;

    #[Test]
    public function it_creates_both_tables(): void
    {
        $this->assertTrue(Schema::hasColumns('custom_fields', [
            'id', 'model_type', 'scope_type', 'scope_id', 'scope_key', 'key', 'label',
            'description', 'type', 'settings', 'validation_rules', 'is_required', 'is_active',
            'is_searchable', 'is_filterable', 'sort_order', 'created_at', 'updated_at', 'deleted_at',
        ]));

        $this->assertTrue(Schema::hasColumns('custom_field_values', [
            'id', 'custom_field_id', 'entity_type', 'entity_id', 'value', 'created_at', 'updated_at',
        ]));
    }

    #[Test]
    public function it_leaves_existing_tables_alone(): void
    {
        $this->assertSame(['id', 'code'], Schema::getColumnListing('reservations'));
    }

    #[Test]
    public function it_rolls_back_and_runs_again(): void
    {
        $this->artisan('migrate:rollback')->assertSuccessful();

        $this->assertFalse(Schema::hasTable('custom_fields'));
        $this->assertFalse(Schema::hasTable('custom_field_values'));

        $this->artisan('migrate')->assertSuccessful();

        $this->assertTrue(Schema::hasTable('custom_fields'));
        $this->assertTrue(Schema::hasTable('custom_field_values'));
    }

    #[Test]
    #[DefineEnvironment('useCustomTableNames')]
    public function it_uses_configured_table_names(): void
    {
        $this->assertTrue(Schema::hasTable('udf_fields'));
        $this->assertTrue(Schema::hasTable('udf_values'));
        $this->assertFalse(Schema::hasTable('custom_fields'));
    }

    #[Test]
    #[DefineEnvironment('disableMigrations')]
    public function it_skips_migrations_when_disabled(): void
    {
        $this->assertFalse(Schema::hasTable('custom_fields'));
    }

    #[Test]
    public function it_publishes_the_migrations(): void
    {
        $paths = CustomFieldsServiceProvider::pathsToPublish(
            CustomFieldsServiceProvider::class,
            'custom-fields-migrations',
        );

        $this->assertContains(database_path('migrations'), $paths);
    }

    protected function useCustomTableNames($app): void
    {
        $app['config']->set('custom-fields.tables.fields', 'udf_fields');
        $app['config']->set('custom-fields.tables.values', 'udf_values');
    }

    protected function disableMigrations($app): void
    {
        $app['config']->set('custom-fields.run_migrations', false);
    }
}
