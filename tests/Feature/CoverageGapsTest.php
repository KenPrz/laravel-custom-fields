<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Tests\Feature;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use KenPrz\CustomFields\CustomFieldsServiceProvider;
use KenPrz\CustomFields\Facades\CustomFields;
use KenPrz\CustomFields\Models\CustomField;
use KenPrz\CustomFields\Tests\Fixtures\Member;
use KenPrz\CustomFields\Tests\Fixtures\Reservation;
use KenPrz\CustomFields\Tests\TestCase;
use KenPrz\CustomFields\Types\Type;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use stdClass;

/**
 * Behaviour that the other suites don't reach on their own, found with line coverage.
 */
class CoverageGapsTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        Relation::morphMap([], false);

        parent::tearDown();
    }

    #[Test]
    public function the_facade_builds_rules_for_a_model(): void
    {
        $this->field('age', 'integer', ['is_required' => true, 'validation_rules' => ['min:18']]);
        $this->field('legacy', 'text', ['is_active' => false]);

        $this->assertSame(['age' => ['required', 'integer', 'min:18']], CustomFields::rules(Reservation::class));
        $this->assertSame(['age' => ['sometimes', 'required', 'integer', 'min:18']], CustomFields::rules(Reservation::class, partial: true));
    }

    #[Test]
    public function the_facade_validates_full_and_partial_input(): void
    {
        $this->field('age', 'integer', ['is_required' => true]);
        $this->field('notes', 'text');

        $this->assertSame(['age' => 30], CustomFields::validate(Reservation::class, ['age' => '30']));
        $this->assertSame(['notes' => 'x'], CustomFields::validate(Reservation::class, ['notes' => 'x'], partial: true));

        $this->expectException(ValidationException::class);
        CustomFields::validate(Reservation::class, ['notes' => 'x']);
    }

    #[Test]
    public function list_custom_fields_includes_unsaved_assignments(): void
    {
        $this->field('tier', 'text', ['model' => Member::class]);
        $this->field('nickname', 'text', ['model' => Member::class]);
        $member = Member::create(['name' => 'Ken', 'tier' => 'gold']);

        $member->nickname = 'pending';

        $this->assertSame(['tier' => 'gold', 'nickname' => 'pending'], $member->listCustomFields());
    }

    #[Test]
    public function a_cancelled_save_keeps_assignments_and_writes_nothing(): void
    {
        $this->field('nickname', 'text', ['model' => Member::class]);
        Member::saving(fn () => false);

        $member = new Member(['name' => 'Ken', 'nickname' => 'kp']);

        $this->assertFalse($member->save());
        $this->assertSame(0, Member::count());
        $this->assertSame('kp', $member->nickname);
    }

    #[Test]
    public function ordering_against_null_is_rejected(): void
    {
        $this->field('age', 'integer');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot compare [age] with null using [>]');

        Reservation::whereCustomField('age', '>', null);
    }

    #[Test]
    public function an_allow_listed_key_without_a_definition_reads_as_null(): void
    {
        $this->assertNull((new Member)->nickname);
        $this->assertNull(Member::create(['name' => 'Ken'])->nickname);
    }

    #[Test]
    public function custom_types_can_rely_on_the_default_normalize(): void
    {
        CustomFields::registerType('code', new class extends Type
        {
            public function rules(CustomField $field): array
            {
                return ['string'];
            }
        });
        $this->field('promo', 'code');

        $reservation = Reservation::create(['code' => 'R1'])->setCustomField('promo', 'SAVE10');

        $this->assertSame('SAVE10', $reservation->getCustomField('promo'));
    }

    #[Test]
    public function a_morph_alias_for_a_non_model_skips_the_shadow_check(): void
    {
        Relation::morphMap(['thing' => stdClass::class]);

        $field = CustomFields::create(['model' => 'thing', 'key' => 'name', 'label' => 'Name', 'type' => 'text']);

        $this->assertSame('thing', $field->model_type);
    }

    #[Test]
    public function the_command_works_without_an_app_models_directory(): void
    {
        $empty = sys_get_temp_dir().'/lcf-app-'.uniqid();
        File::ensureDirectoryExists($empty);
        $this->app->useAppPath($empty);
        (new ReflectionProperty($this->app, 'namespace'))->setValue($this->app, 'App\\');

        $this->artisan('make:custom-field', ['--mode' => 'db'])
            ->expectsQuestion('Which model gets the field?', 'Nope')
            ->expectsOutputToContain('The model [Nope] could not be found.')
            ->assertFailed();

        File::deleteDirectory($empty);
    }

    #[Test]
    public function web_requests_skip_console_registration(): void
    {
        $running = new ReflectionProperty($this->app, 'isRunningInConsole');
        $running->setValue($this->app, false);
        $published = CustomFieldsServiceProvider::$publishes;
        CustomFieldsServiceProvider::$publishes = [];

        (new CustomFieldsServiceProvider($this->app))->boot();

        $this->assertSame([], CustomFieldsServiceProvider::$publishes);
        CustomFieldsServiceProvider::$publishes = $published;
        $running->setValue($this->app, null);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function field(string $key, string $type, array $extra = []): void
    {
        CustomFields::create(['model' => Reservation::class, 'key' => $key, 'label' => ucfirst($key), 'type' => $type, ...$extra]);
    }
}
