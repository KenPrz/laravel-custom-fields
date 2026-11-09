<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Tests\Feature;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;
use KenPrz\CustomFields\Console\MakeCustomFieldCommand;
use KenPrz\CustomFields\Facades\CustomFields;
use KenPrz\CustomFields\Tests\Fixtures\Models\Invoice;
use KenPrz\CustomFields\Tests\Fixtures\Property;
use KenPrz\CustomFields\Tests\Fixtures\Reservation;
use KenPrz\CustomFields\Tests\Fixtures\Team;
use KenPrz\CustomFields\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionProperty;
use stdClass;

/**
 * Command behaviour that mutation testing showed the other suites didn't pin down.
 */
class MakeCustomFieldCommandHardeningTest extends TestCase
{
    use DatabaseMigrations;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = 'storage/framework/testing/custom-field-hardening-'.uniqid();
        $this->app->useDatabasePath(base_path($this->directory.'/database'));
    }

    protected function tearDown(): void
    {
        $this->app['env'] = 'testing';
        Relation::morphMap([], false);
        File::deleteDirectory(base_path($this->directory));

        parent::tearDown();
    }

    #[Test]
    public function the_key_and_model_are_required_without_interaction(): void
    {
        $this->artisan('make:custom-field', ['--model' => Reservation::class, '--type' => 'text', '--no-interaction' => true])
            ->expectsOutputToContain('The key argument is required.')->assertFailed();

        $this->artisan('make:custom-field', ['key' => 'notes', '--type' => 'text', '--no-interaction' => true])
            ->expectsOutputToContain('The --model option is required.')->assertFailed();
    }

    #[Test]
    public function labels_are_limited_to_255_characters_not_bytes(): void
    {
        $this->run_(['key' => 'a', '--label' => str_repeat('é', 255)])->assertSuccessful();
        $this->run_(['key' => 'b', '--label' => str_repeat('a', 256)])
            ->expectsOutputToContain('The label may not be longer than 255 characters.')->assertFailed();
    }

    #[Test]
    public function an_unknown_type_lists_the_available_ones(): void
    {
        $this->run_(['--type' => 'nope'])
            ->expectsOutputToContain('The type [nope] is not registered. Available: text, textarea, number, integer, decimal, boolean, date, datetime, time, select, radio, multiselect, checkbox, email, url, phone, json.')
            ->assertFailed();
    }

    #[Test]
    public function options_are_trimmed_and_labels_may_contain_colons(): void
    {
        $this->run_(['--type' => 'select', '--options' => ' early : Early: 6:00 ,, late:Late ,', '--mode' => 'db'])->assertSuccessful();

        // Empty entries anywhere in the list are skipped, not treated as the end.
        $this->assertSame(
            ['options' => ['early' => 'Early: 6:00', 'late' => 'Late']],
            CustomFields::findByKey(Reservation::class, 'tier')->settings,
        );
    }

    #[Test]
    public function rules_are_split_trimmed_and_listed(): void
    {
        $this->run_(['--rules' => ' min:2 || max:20 |', '--mode' => 'db'])->assertSuccessful();

        $this->assertSame(['min:2', 'max:20'], CustomFields::findByKey(Reservation::class, 'tier')->validation_rules);

        $this->run_(['key' => 'other', '--rules' => 'nope'])->expectsOutputToContain('Unknown validation rules: nope.')->assertFailed();
    }

    #[Test]
    public function the_interactive_form_needs_at_least_one_option(): void
    {
        $this->artisan('make:custom-field', ['--mode' => 'migration'])
            ->expectsQuestion('Which model gets the field?', Reservation::class)
            ->expectsQuestion('Field key', 'tier')
            ->expectsQuestion('Label', 'Tier')
            ->expectsQuestion('Field type', 'select')
            ->expectsQuestion('Option value', '')
            ->expectsOutputToContain('Add at least one option.')
            ->assertFailed();
    }

    #[Test]
    public function the_summary_shows_every_answer(): void
    {
        $hotel = Property::create(['name' => 'A']);

        $this->artisan('make:custom-field', ['--mode' => 'db'])
            ->expectsQuestion('Which model gets the field?', Reservation::class)
            ->expectsQuestion('Scope', Property::class.':'.$hotel->id)
            ->expectsQuestion('Field key', 'age')
            ->expectsQuestion('Label', 'Age')
            ->expectsQuestion('Field type', 'integer')
            ->expectsConfirmation('Is this field required?', 'yes')
            ->expectsQuestion('Description', 'Guest age')
            ->expectsQuestion('Extra validation rules', 'min:18')
            ->expectsTable(['Setting', 'Value'], [
                ['Model', Reservation::class],
                ['Key', 'age'],
                ['Label', 'Age'],
                ['Type', 'integer'],
                ['Required', 'yes'],
                ['Description', 'Guest age'],
                ['Rules', 'min:18'],
                ['Scope', Property::class.':'.$hotel->id],
                ['Created in', 'the database'],
            ])
            ->expectsConfirmation('Create this field?', 'yes')
            ->assertSuccessful();

        $this->assertTrue(CustomFields::forScope($hotel)->findByKey(Reservation::class, 'age')->is_required);
    }

    #[Test]
    public function the_migration_file_is_named_like_laravel_migrations(): void
    {
        $this->run_(['key' => 'notes', '--path' => $this->directory.'/out/'])->assertSuccessful();

        $files = File::files(base_path($this->directory.'/out'));
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('/^\d{4}_\d{2}_\d{2}_\d{6}_add_notes_custom_field_to_reservations\.php$/', $files[0]->getFilename());
    }

    #[Test]
    public function a_pending_migration_is_named_in_the_error_and_ignored_in_db_mode(): void
    {
        $this->run_(['key' => 'notes'])->assertSuccessful();
        $file = basename(File::files(database_path('migrations'))[0]->getFilename());

        $this->run_(['key' => 'notes'])
            ->expectsOutputToContain("A migration for the key [notes] already exists: {$file}.")->assertFailed();

        // Database mode creates the field now; a migration that hasn't run doesn't block it.
        $this->run_(['key' => 'notes', '--mode' => 'db'])->assertSuccessful();
    }

    #[Test]
    public function a_model_with_a_leading_backslash_is_accepted(): void
    {
        $this->run_(['--model' => '\\'.Reservation::class, '--mode' => 'db'])->assertSuccessful();
        $this->run_(['key' => 'notes', '--model' => '\\'.Reservation::class])->assertSuccessful();

        $this->assertStringContainsString("'model' => \\".Reservation::class.'::class,', File::get(File::files(database_path('migrations'))[0]->getPathname()));
    }

    #[Test]
    public function short_names_resolve_in_the_models_namespace_then_the_app_namespace(): void
    {
        (new ReflectionProperty($this->app, 'namespace'))->setValue($this->app, 'KenPrz\\CustomFields\\Tests\\Fixtures\\');

        $this->run_(['key' => 'a', '--model' => 'Invoice', '--mode' => 'db'])->assertSuccessful();
        $this->run_(['key' => 'b', '--model' => 'Reservation', '--mode' => 'db'])->assertSuccessful();

        $this->assertNotNull(CustomFields::findByKey(Invoice::class, 'a'));
        $this->assertNotNull(CustomFields::findByKey(Reservation::class, 'b'));
    }

    #[Test]
    public function morph_aliases_resolve_only_to_models(): void
    {
        Relation::morphMap(['booking' => Reservation::class, 'thing' => stdClass::class]);

        $this->run_(['--model' => 'booking', '--mode' => 'db'])->assertSuccessful();
        $this->run_(['key' => 'x', '--model' => 'thing', '--mode' => 'db'])
            ->expectsOutputToContain('The model [thing] could not be found.')->assertFailed();
    }

    #[Test]
    public function a_scope_id_must_match_exactly(): void
    {
        $hotel = Property::create(['name' => 'A']);

        $this->run_(['--mode' => 'db', '--scope' => Property::class.':'.$hotel->id.':junk'])
            ->expectsOutputToContain('No ['.Property::class.'] with id ['.$hotel->id.':junk] exists.')->assertFailed();

        // A non-numeric id for an integer key is a clear error on every database, not a SQL error.
        $this->run_(['--mode' => 'db', '--scope' => Property::class.':abc'])
            ->expectsOutputToContain('No ['.Property::class.'] with id [abc] exists.')->assertFailed();
    }

    #[Test]
    public function a_scope_with_a_string_key_accepts_any_id(): void
    {
        Team::create(['slug' => 'blue-team']);
        Team::create(['slug' => '2026']);

        $this->run_(['--mode' => 'db', '--scope' => Team::class.':blue-team'])->assertSuccessful();
        $this->run_(['key' => 'year', '--mode' => 'db', '--scope' => Team::class.':2026'])->assertSuccessful();
        $this->run_(['key' => 'x', '--mode' => 'db', '--scope' => Team::class.':red-team'])
            ->expectsOutputToContain('No ['.Team::class.'] with id [red-team] exists.')->assertFailed();

        $this->assertNotNull(CustomFields::forScope(Team::find('blue-team'))->findByKey(Reservation::class, 'tier'));
    }

    #[Test]
    public function an_invalid_scope_is_caught_as_soon_as_it_is_typed(): void
    {
        $this->artisan('make:custom-field', ['--mode' => 'db'])
            ->expectsQuestion('Which model gets the field?', Reservation::class)
            ->expectsQuestion('Scope', 'Nope:1')
            ->expectsOutputToContain('The scope [Nope:1] must look like Model:id')
            ->assertFailed();
    }

    #[Test]
    public function a_scope_needs_an_id(): void
    {
        $this->run_(['--mode' => 'db', '--scope' => Property::class])
            ->expectsOutputToContain('The scope ['.Property::class.'] must look like Model:id')->assertFailed();
    }

    #[Test]
    public function an_existing_field_is_reported_before_a_pending_migration(): void
    {
        $this->run_(['key' => 'notes'])->assertSuccessful();
        CustomFields::create(['model' => Reservation::class, 'key' => 'notes', 'label' => 'Notes', 'type' => 'text']);

        $this->run_(['key' => 'notes'])->expectsOutputToContain('The key [notes] is already in use.')->assertFailed();
    }

    #[Test]
    public function the_model_suggestions_come_from_app_models(): void
    {
        $app = base_path($this->directory.'/app');
        File::ensureDirectoryExists($app.'/Models/Billing');
        File::put($app.'/Models/User.php', '<?php');
        File::put($app.'/Models/Billing/Invoice.php', '<?php');
        $this->app->useAppPath($app);

        $command = $this->app->make(MakeCustomFieldCommand::class);
        $command->setLaravel($this->app);
        $models = (new ReflectionMethod($command, 'appModels'))->invoke($command);

        sort($models);
        $this->assertSame(['Billing\\Invoice', 'User'], $models);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function run_(array $options): PendingCommand
    {
        return $this->artisan('make:custom-field', [
            'key' => 'tier', '--model' => Reservation::class, '--type' => 'text', '--no-interaction' => true, ...$options,
        ]);
    }
}
