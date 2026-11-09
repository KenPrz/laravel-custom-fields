<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\File;
use KenPrz\CustomFields\Facades\CustomFields;
use KenPrz\CustomFields\Models\CustomField;
use KenPrz\CustomFields\Models\CustomFieldValue;
use KenPrz\CustomFields\Tests\Fixtures\Member;
use KenPrz\CustomFields\Tests\Fixtures\Property;
use KenPrz\CustomFields\Tests\Fixtures\Reservation;
use KenPrz\CustomFields\Tests\TestCase;
use Laravel\Prompts\Prompt;
use PHPUnit\Framework\Attributes\Test;

class MakeCustomFieldCommandTest extends TestCase
{
    use DatabaseMigrations;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = 'storage/framework/testing/custom-field-migrations-'.uniqid();
    }

    protected function tearDown(): void
    {
        // DatabaseMigrations rolls back after each test, and migrate:rollback asks first in production.
        $this->app['env'] = 'testing';
        File::deleteDirectory(base_path($this->directory));

        parent::tearDown();
    }

    #[Test]
    public function it_writes_a_migration_from_options(): void
    {
        $this->artisan('make:custom-field', [
            'key' => 'guest_classification',
            '--model' => Reservation::class,
            '--type' => 'select',
            '--options' => 'vip:VIP Guest, corporate:Corporate,walk_in',
            '--rules' => 'string|max:20',
            '--description' => 'How the guest booked',
            '--required' => true,
            '--path' => $this->directory,
            '--no-interaction' => true,
        ])->expectsOutputToContain('Migration [')->assertSuccessful();

        $this->assertSame(0, CustomField::count(), 'migration mode writes nothing to the database');

        $migration = $this->migrationFile();
        $code = file_get_contents($migration);
        $this->assertStringContainsString("'model' => \\".Reservation::class.'::class,', $code);
        $this->assertStringContainsString("            'validation_rules' => [\n                'string',\n                'max:20',\n            ],", $code);
        $this->assertStringNotContainsString('0 =>', $code);

        (require $migration)->up();

        $field = CustomFields::findByKey(Reservation::class, 'guest_classification');
        $this->assertSame('Guest Classification', $field->label);
        $this->assertSame(['options' => ['vip' => 'VIP Guest', 'corporate' => 'Corporate', 'walk_in' => 'walk_in']], $field->settings);
        $this->assertSame(['string', 'max:20'], $field->validation_rules);
        $this->assertSame('How the guest booked', $field->description);
        $this->assertTrue($field->is_required);
    }

    #[Test]
    public function the_migration_rolls_back_cleanly(): void
    {
        $this->artisan('make:custom-field', [
            'key' => 'notes', '--model' => Reservation::class, '--type' => 'text',
            '--path' => $this->directory, '--no-interaction' => true,
        ])->assertSuccessful();

        $migration = require $this->migrationFile();
        $migration->up();
        Reservation::create(['code' => 'R1'])->setCustomField('notes', 'hello');
        CustomFields::delete(CustomFields::findByKey(Reservation::class, 'notes'));

        $migration->down();
        $this->assertSame(0, CustomField::withTrashed()->count());
        $this->assertSame(0, CustomFieldValue::count());

        $migration->up();
        $this->assertNotNull(CustomFields::findByKey(Reservation::class, 'notes'));
    }

    #[Test]
    public function short_model_names_resolve_like_laravel_commands(): void
    {
        $this->app->setBasePath($this->app->basePath());
        config(['app.namespace' => 'KenPrz\\CustomFields\\Tests\\Fixtures\\']);

        $this->artisan('make:custom-field', [
            'key' => 'notes', '--model' => Reservation::class, '--type' => 'text',
            '--mode' => 'db', '--no-interaction' => true,
        ])->assertSuccessful();

        $this->assertNotNull(CustomFields::findByKey(Reservation::class, 'notes'));
    }

    #[Test]
    public function a_migration_that_has_not_run_yet_still_counts_as_a_duplicate(): void
    {
        $options = ['key' => 'notes', '--model' => Reservation::class, '--type' => 'text', '--path' => $this->directory, '--no-interaction' => true];

        $this->artisan('make:custom-field', $options)->assertSuccessful();
        $this->artisan('make:custom-field', $options)
            ->expectsOutputToContain('A migration for the key [notes] already exists')
            ->assertFailed();

        $this->migrationFile();
    }

    #[Test]
    public function db_mode_creates_the_field_right_away(): void
    {
        $this->artisan('make:custom-field', [
            'key' => 'notes', '--model' => Reservation::class, '--type' => 'textarea', '--mode' => 'db', '--no-interaction' => true,
        ])->expectsOutputToContain('Custom field [notes] created with id')->assertSuccessful();

        $this->assertSame('textarea', CustomFields::findByKey(Reservation::class, 'notes')->type);
        $this->assertFalse(File::isDirectory(base_path($this->directory)));
    }

    #[Test]
    public function db_mode_needs_force_in_production(): void
    {
        $this->app['env'] = 'production';
        // Outside the testing environment Laravel stops routing prompts through the testable fallback.
        Prompt::fallbackWhen(true);
        $options = ['key' => 'notes', '--model' => Reservation::class, '--type' => 'text', '--mode' => 'db', '--no-interaction' => true];

        $this->artisan('make:custom-field', $options)
            ->expectsConfirmation('Are you sure you want to run this command?', 'no')
            ->expectsOutputToContain('Command cancelled.')
            ->assertFailed();
        $this->assertSame(0, CustomField::count());

        $this->artisan('make:custom-field', [...$options, '--force' => true])->assertSuccessful();
        $this->assertSame(1, CustomField::count());
    }

    #[Test]
    public function migration_mode_needs_no_confirmation_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('make:custom-field', [
            'key' => 'notes', '--model' => Reservation::class, '--type' => 'text',
            '--path' => $this->directory, '--no-interaction' => true,
        ])->assertSuccessful();

        $this->migrationFile();
    }

    #[Test]
    public function db_mode_can_create_a_scoped_field(): void
    {
        $hotel = Property::create(['name' => 'A']);

        $this->artisan('make:custom-field', [
            'key' => 'vip_code', '--model' => Reservation::class, '--type' => 'text',
            '--mode' => 'db', '--scope' => Property::class.':'.$hotel->id, '--no-interaction' => true,
        ])->assertSuccessful();

        $this->assertNull(CustomFields::findByKey(Reservation::class, 'vip_code'));
        $this->assertNotNull(CustomFields::forScope($hotel)->findByKey(Reservation::class, 'vip_code'));
    }

    #[Test]
    public function a_scope_is_rejected_in_migration_mode(): void
    {
        $this->artisan('make:custom-field', [
            'key' => 'vip_code', '--model' => Reservation::class, '--type' => 'text',
            '--scope' => Property::class.':1', '--path' => $this->directory, '--no-interaction' => true,
        ])->expectsOutputToContain('A scope only works with --mode=db')->assertFailed();
    }

    #[Test]
    public function bad_options_fail_before_anything_is_written(): void
    {
        $this->field('notes');

        $cases = [
            'The mode [x] is not supported' => ['--mode' => 'x'],
            'The model [Nope] could not be found' => ['--model' => 'Nope'],
            'The key [notes] is already in use' => ['key' => 'notes'],
            'The key [code] is already used by the' => ['key' => 'code'],
            'The key must start with a letter' => ['key' => 'Bad Key'],
            'The type [nope] is not registered' => ['--type' => 'nope'],
            'Select types need --options' => ['--type' => 'select'],
            'The option [vip] is listed twice' => ['--type' => 'select', '--options' => 'vip,vip'],
            'Unknown validation rules: nonsense' => ['--rules' => 'min:2|nonsense'],
            'No ['.Property::class.'] with id [99] exists' => ['--mode' => 'db', '--scope' => Property::class.':99'],
            'must look like Model:id' => ['--mode' => 'db', '--scope' => 'Property'],
            'The --type option is required' => ['--type' => null],
        ];

        foreach ($cases as $message => $override) {
            $this->artisan('make:custom-field', array_filter([
                'key' => 'tier', '--model' => Reservation::class, '--type' => 'text',
                '--path' => $this->directory, '--no-interaction' => true, ...$override,
            ], fn ($value) => $value !== null))->expectsOutputToContain($message)->assertFailed();
        }

        $this->assertSame(1, CustomField::count());
        $this->assertFalse(File::isDirectory(base_path($this->directory)));
    }

    #[Test]
    public function it_asks_for_each_field_and_lets_optional_ones_be_skipped(): void
    {
        $this->artisan('make:custom-field', ['--path' => $this->directory])
            ->expectsQuestion('Where should the field be created?', 'migration')
            ->expectsQuestion('Which model gets the field?', Member::class)
            ->expectsQuestion('Field key', 'tax_bracket')
            ->expectsQuestion('Label', 'Tax bracket')
            ->expectsQuestion('Field type', 'select')
            ->expectsQuestion('Option value', 'a')
            ->expectsQuestion('Label for [a]', 'Bracket A')
            ->expectsQuestion('Option value', 'b')
            ->expectsQuestion('Label for [b]', 'b')
            ->expectsQuestion('Option value', '')
            ->expectsConfirmation('Is this field required?', 'no')
            ->expectsQuestion('Description', '')
            ->expectsQuestion('Extra validation rules', '')
            ->expectsTable(['Setting', 'Value'], [
                ['Model', Member::class],
                ['Key', 'tax_bracket'],
                ['Label', 'Tax bracket'],
                ['Type', 'select'],
                ['Option', 'a (Bracket A)'],
                ['Option', 'b (b)'],
                ['Required', 'no'],
                ['Description', '-'],
                ['Rules', '-'],
                ['Scope', 'global'],
                ['Created in', 'a migration file'],
            ])
            ->expectsConfirmation('Create this field?', 'yes')
            ->assertSuccessful();

        (require $this->migrationFile())->up();

        $field = CustomFields::findByKey(Member::class, 'tax_bracket');
        $this->assertSame(['options' => ['a' => 'Bracket A', 'b' => 'b']], $field->settings);
        $this->assertFalse($field->is_required);
        $this->assertNull($field->description);
        $this->assertSame([], $field->validation_rules);
    }

    #[Test]
    public function a_duplicate_key_is_caught_as_soon_as_it_is_typed(): void
    {
        $this->field('notes');

        $this->artisan('make:custom-field', ['--mode' => 'db'])
            ->expectsQuestion('Which model gets the field?', Reservation::class)
            ->expectsQuestion('Scope', '')
            ->expectsQuestion('Field key', 'notes')
            ->expectsOutputToContain('The key [notes] is already in use.')
            ->assertFailed();
    }

    #[Test]
    public function a_column_name_is_caught_as_soon_as_it_is_typed(): void
    {
        $this->artisan('make:custom-field', ['--mode' => 'db'])
            ->expectsQuestion('Which model gets the field?', Reservation::class)
            ->expectsQuestion('Scope', '')
            ->expectsQuestion('Field key', 'code')
            ->expectsOutputToContain('The key [code] is already used by the')
            ->assertFailed();
    }

    #[Test]
    public function a_duplicate_option_is_caught_as_soon_as_it_is_typed(): void
    {
        $this->artisan('make:custom-field', ['--mode' => 'db'])
            ->expectsQuestion('Which model gets the field?', Reservation::class)
            ->expectsQuestion('Scope', '')
            ->expectsQuestion('Field key', 'tier')
            ->expectsQuestion('Label', 'Tier')
            ->expectsQuestion('Field type', 'select')
            ->expectsQuestion('Option value', 'vip')
            ->expectsQuestion('Label for [vip]', 'VIP')
            ->expectsQuestion('Option value', 'vip')
            ->expectsOutputToContain('The option [vip] is already added.')
            ->assertFailed();
    }

    #[Test]
    public function unknown_rules_are_caught_as_soon_as_they_are_typed(): void
    {
        $this->artisan('make:custom-field', ['--mode' => 'db'])
            ->expectsQuestion('Which model gets the field?', Reservation::class)
            ->expectsQuestion('Scope', '')
            ->expectsQuestion('Field key', 'age')
            ->expectsQuestion('Label', 'Age')
            ->expectsQuestion('Field type', 'integer')
            ->expectsConfirmation('Is this field required?', 'yes')
            ->expectsQuestion('Description', 'Guest age')
            ->expectsQuestion('Extra validation rules', 'min:18|bogus')
            ->expectsOutputToContain('Unknown validation rules: bogus.')
            ->assertFailed();
    }

    #[Test]
    public function the_interactive_scope_is_checked_before_the_key(): void
    {
        $hotel = Property::create(['name' => 'A']);
        CustomFields::forScope($hotel)->create(['model' => Reservation::class, 'key' => 'vip_code', 'label' => 'VIP', 'type' => 'text']);

        // The key is free globally but taken in the scope, so the check has to know the scope first.
        $this->artisan('make:custom-field', ['--mode' => 'db'])
            ->expectsQuestion('Which model gets the field?', Reservation::class)
            ->expectsQuestion('Scope', Property::class.':'.$hotel->id)
            ->expectsQuestion('Field key', 'vip_code')
            ->expectsOutputToContain('The key [vip_code] is already in use.')
            ->assertFailed();
    }

    #[Test]
    public function declining_the_summary_creates_nothing(): void
    {
        $this->artisan('make:custom-field', ['key' => 'notes', '--model' => Reservation::class, '--type' => 'text', '--mode' => 'db'])
            ->expectsQuestion('Scope', '')
            ->expectsQuestion('Label', 'Notes')
            ->expectsConfirmation('Is this field required?', 'no')
            ->expectsQuestion('Description', '')
            ->expectsQuestion('Extra validation rules', '')
            ->expectsConfirmation('Create this field?', 'no')
            ->expectsOutputToContain('Nothing was created.')
            ->assertSuccessful();

        $this->assertSame(0, CustomField::count());
    }

    private function migrationFile(): string
    {
        $files = glob(base_path($this->directory).'/*_custom_field_to_*.php');
        $this->assertCount(1, $files);

        return $files[0];
    }

    private function field(string $key): void
    {
        CustomFields::create(['model' => Reservation::class, 'key' => $key, 'label' => ucfirst($key), 'type' => 'text']);
    }
}
