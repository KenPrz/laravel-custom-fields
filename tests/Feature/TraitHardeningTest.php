<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Tests\Feature;

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use KenPrz\CustomFields\CustomFieldManager;
use KenPrz\CustomFields\Exceptions\CustomFieldException;
use KenPrz\CustomFields\Facades\CustomFields;
use KenPrz\CustomFields\Tests\Fixtures\EagerMember;
use KenPrz\CustomFields\Tests\Fixtures\Member;
use KenPrz\CustomFields\Tests\Fixtures\Mood;
use KenPrz\CustomFields\Tests\Fixtures\Reservation;
use KenPrz\CustomFields\Tests\Fixtures\SelfSavingMember;
use KenPrz\CustomFields\Tests\Fixtures\ShapedMember;
use KenPrz\CustomFields\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Trait behaviour that mutation testing showed the other suites didn't pin down.
 */
class TraitHardeningTest extends TestCase
{
    use DatabaseMigrations;

    #[Test]
    public function mass_assignment_only_lets_custom_field_keys_through(): void
    {
        $this->field(Member::class, 'nickname');

        $member = new Member(['name' => 'Ken', 'nickname' => 'kp', 'id' => 99, 'not_a_column' => 'x']);

        $this->assertNull($member->id);
        $this->assertArrayNotHasKey('not_a_column', $member->getAttributes());
        $this->assertSame('kp', $member->nickname);
    }

    #[Test]
    public function models_that_override_save_still_validate_and_write_through_events(): void
    {
        $this->field(SelfSavingMember::class, 'nickname', ['validation_rules' => ['min:2']]);

        $member = new SelfSavingMember(['name' => 'Ken', 'nickname' => 'kp']);
        $member->save();
        $this->assertSame(1, $member->saves);
        $this->assertSame('kp', SelfSavingMember::find($member->id)->nickname);

        $this->expectException(ValidationException::class);
        try {
            SelfSavingMember::create(['name' => 'Bad', 'nickname' => 'k']);
        } finally {
            $this->assertSame(1, SelfSavingMember::count(), 'the invalid model was not inserted');
        }
    }

    #[Test]
    public function forgetting_needs_a_saved_model(): void
    {
        $this->expectException(CustomFieldException::class);

        (new Reservation)->forgetCustomField('anything');
    }

    #[Test]
    public function forgetting_refreshes_loaded_values(): void
    {
        $this->field(Reservation::class, 'notes');
        $reservation = Reservation::create(['code' => 'R1'])->setCustomField('notes', 'x');
        $loaded = Reservation::withCustomFields()->find($reservation->id);

        $loaded->forgetCustomField('notes');

        $this->assertFalse($loaded->hasCustomField('notes'));
        $this->assertSame([], $loaded->listCustomFields());
    }

    #[Test]
    public function a_set_mutator_wins_over_an_allow_listed_key(): void
    {
        $member = new ShapedMember;
        $member->plain_note = 'hello';

        $this->assertSame('hello', $member->mutated);
        $this->assertSame([], $member->listCustomFields());
    }

    #[Test]
    public function to_array_keeps_every_column_when_values_are_not_loaded(): void
    {
        $member = Member::find(Member::create(['name' => 'Ken', 'email' => 'k@example.com'])->id);

        $this->assertSame(['id' => $member->id, 'name' => 'Ken', 'email' => 'k@example.com'], $member->toArray());
    }

    #[Test]
    public function hidden_and_appended_attributes_dont_break_casting(): void
    {
        $this->field(ShapedMember::class, 'birthday', [], 'date');
        $this->field(ShapedMember::class, 'arrival', [], 'date');

        $member = ShapedMember::create(['name' => 'Ken', 'email' => 'k@example.com', 'birthday' => '1990-05-01', 'arrival' => '2026-12-24']);

        // email and birthday are hidden; shout is appended; arrival is a cast custom field.
        $this->assertSame(['name' => 'Ken', 'id' => $member->id, 'shout' => 'KEN!', 'arrival' => '2026-12-24'], $member->toArray());
        $this->assertArrayNotHasKey('birthday', $member->toArray());
        $this->assertSame('1990-05-01', $member->birthday->toDateString());
    }

    #[Test]
    public function visible_limits_custom_fields_too(): void
    {
        $this->field(ShapedMember::class, 'arrival', [], 'date');
        $member = ShapedMember::create(['name' => 'Ken', 'arrival' => '2026-12-24']);

        $member->setVisible(['name']);

        $this->assertSame(['name' => 'Ken'], $member->toArray());
    }

    #[Test]
    public function pure_enums_are_stored_by_name(): void
    {
        $this->field(ShapedMember::class, 'mood', ['settings' => ['options' => ['Happy' => 'Happy', 'Grumpy' => 'Grumpy']]], 'select');

        $member = ShapedMember::create(['name' => 'Ken', 'mood' => Mood::Grumpy]);

        $this->assertSame('Grumpy', $member->getCustomField('mood'));
    }

    #[Test]
    public function saving_only_checks_the_values_being_saved(): void
    {
        $this->field(Member::class, 'tier', ['is_required' => true]);
        $this->field(Member::class, 'nickname');

        $member = Member::create(['name' => 'Ken', 'nickname' => 'kp']);

        $this->assertSame(['nickname' => 'kp'], $member->listCustomFields());
    }

    #[Test]
    public function saving_without_custom_fields_opens_no_transaction(): void
    {
        $member = Member::create(['name' => 'Ken']);
        $transactions = 0;
        Event::listen(TransactionBeginning::class, function () use (&$transactions) {
            $transactions++;
        });

        $member->update(['name' => 'Kenneth']);

        $this->assertSame(0, $transactions);
    }

    #[Test]
    public function save_quietly_validates_before_inserting_and_keeps_the_written_values_loaded(): void
    {
        $this->field(Member::class, 'nickname', ['validation_rules' => ['min:2']]);

        DB::enableQueryLog();
        try {
            (new Member(['name' => 'Ken', 'nickname' => 'k']))->saveQuietly();
            $this->fail('Expected a validation error.');
        } catch (ValidationException) {
        }
        $this->assertSame([], array_filter(DB::getQueryLog(), fn ($q) => str_starts_with($q['query'], 'insert')));

        $member = new Member(['name' => 'Ken', 'nickname' => 'kp']);
        $member->saveQuietly();
        DB::flushQueryLog();

        $this->assertSame('kp', $member->toArray()['nickname']);
        $this->assertSame([], DB::getQueryLog());
    }

    #[Test]
    public function building_models_runs_no_query(): void
    {
        $this->field(Member::class, 'nickname');
        Member::create(['name' => 'Ken']);
        app(CustomFieldManager::class)->flush();

        DB::enableQueryLog();
        new Member;
        new Member(['name' => 'Fillable only']);
        Member::query()->get();

        $this->assertCount(1, DB::getQueryLog(), 'only the select itself');
    }

    #[Test]
    public function reading_the_primary_key_of_a_new_model_runs_no_query(): void
    {
        $member = new Reservation(['code' => 'R1']);
        app(CustomFieldManager::class)->flush();

        DB::enableQueryLog();
        $this->assertNull($member->id);
        $this->assertNull($member->getKey());

        $this->assertSame([], DB::getQueryLog());
    }

    #[Test]
    public function syncing_only_unknown_keys_reads_nothing(): void
    {
        config(['custom-fields.strict' => false]);
        $this->field(Reservation::class, 'notes');
        $reservation = Reservation::create(['code' => 'R1']);
        $reservation->customFieldDefinitions();

        DB::enableQueryLog();
        $reservation->syncCustomFields(['nope' => 1]);

        $this->assertSame([], DB::getQueryLog());
    }

    #[Test]
    public function load_custom_fields_adds_to_existing_eager_loads_once(): void
    {
        $member = new class extends EagerMember
        {
            protected $with = ['notes', 'customFieldValues', 'notes'];
        };

        $this->assertSame(['notes', 'customFieldValues'], (fn () => $this->with)->call($member));
        $this->assertSame(['customFieldValues'], (fn () => $this->with)->call(new EagerMember));
    }

    #[Test]
    public function bad_operators_are_named_in_the_error(): void
    {
        $this->field(Reservation::class, 'age', [], 'integer');

        foreach ([['~', "Unsupported custom field operator ['~']."], [[1], "Unsupported custom field operator [array (\n  0 => 1,\n)]."]] as [$operator, $message]) {
            try {
                Reservation::whereCustomField('age', $operator, 1);
                $this->fail('Expected an exception.');
            } catch (InvalidArgumentException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }
    }

    #[Test]
    public function not_equal_to_null_means_has_a_value(): void
    {
        $this->field(Reservation::class, 'notes');
        Reservation::create(['code' => 'A'])->setCustomField('notes', 'x');
        Reservation::create(['code' => 'B']);

        $this->assertSame(['A'], Reservation::whereCustomField('notes', '<>', null)->pluck('code')->all());
    }

    #[Test]
    public function where_in_accepts_any_array(): void
    {
        $this->field(Reservation::class, 'notes');
        Reservation::create(['code' => 'A'])->setCustomField('notes', 'x');

        $this->assertSame(['A'], Reservation::whereCustomFieldIn('notes', ['first' => 'x', 'second' => 'y'])->pluck('code')->all());
    }

    #[Test]
    public function query_value_errors_use_the_field_label(): void
    {
        $this->field(Reservation::class, 'guest_age', ['label' => 'Guest age'], 'integer');

        try {
            Reservation::whereCustomField('guest_age', 'old');
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertSame(['The Guest age field must be an integer.'], $e->errors()['guest_age']);
        }
    }

    /**
     * @param  class-string  $model
     * @param  array<string, mixed>  $extra
     */
    private function field(string $model, string $key, array $extra = [], string $type = 'text'): void
    {
        CustomFields::create(['model' => $model, 'key' => $key, 'label' => ucfirst($key), 'type' => $type, ...$extra]);
    }
}
