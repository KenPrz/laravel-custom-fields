<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Tests\Feature;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use KenPrz\CustomFields\Exceptions\CustomFieldException;
use KenPrz\CustomFields\Facades\CustomFields;
use KenPrz\CustomFields\Models\CustomField;
use KenPrz\CustomFields\Models\CustomFieldValue;
use KenPrz\CustomFields\Tests\Fixtures\Guest;
use KenPrz\CustomFields\Tests\Fixtures\Reservation;
use KenPrz\CustomFields\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class HasCustomFieldsTest extends TestCase
{
    use DatabaseMigrations;

    private Reservation $reservation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->field('guest_classification', 'select', ['settings' => ['options' => ['vip' => 'VIP', 'corporate' => 'Corporate']]]);
        $this->field('internal_reference', 'text', ['sort_order' => -1]);
        $this->field('guest_age', 'integer', ['validation_rules' => ['min:18']]);
        $this->field('amenities', 'multiselect', ['settings' => ['options' => ['wifi' => 'Wi-Fi', 'parking' => 'Parking']]]);

        $this->reservation = Reservation::create(['code' => 'R1']);
    }

    protected function tearDown(): void
    {
        Relation::morphMap([], false);

        parent::tearDown();
    }

    #[Test]
    public function it_sets_and_gets_a_value(): void
    {
        $result = $this->reservation->setCustomField('guest_classification', 'vip');

        $this->assertSame($this->reservation, $result);
        $this->assertSame('vip', $this->reservation->getCustomField('guest_classification'));
        $this->assertSame('vip', Reservation::find($this->reservation->id)->getCustomField('guest_classification'));
    }

    #[Test]
    public function it_returns_normalized_values(): void
    {
        $this->reservation->syncCustomFields(['guest_age' => '30', 'amenities' => ['wifi', 'wifi']]);

        $fresh = Reservation::find($this->reservation->id);
        $this->assertSame(30, $fresh->getCustomField('guest_age'));
        $this->assertSame(['wifi'], $fresh->getCustomField('amenities'));
    }

    #[Test]
    public function missing_values_return_the_default(): void
    {
        $this->assertNull($this->reservation->getCustomField('guest_age'));
        $this->assertSame(0, $this->reservation->getCustomField('guest_age', 0));
    }

    #[Test]
    public function stored_nulls_return_the_default_but_are_listed(): void
    {
        $this->reservation->setCustomField('internal_reference', null);

        $this->assertSame('none', $this->reservation->getCustomField('internal_reference', 'none'));
        $this->assertSame(['internal_reference' => null], $this->reservation->listCustomFields());
        $this->assertFalse($this->reservation->hasCustomField('internal_reference'));
        $this->assertSame(1, CustomFieldValue::count());
    }

    #[Test]
    public function empty_strings_are_stored_as_null(): void
    {
        $this->reservation->setCustomField('internal_reference', '');

        $this->assertNull(CustomFieldValue::first()->value);
    }

    #[Test]
    public function empty_arrays_are_kept(): void
    {
        $this->reservation->setCustomField('amenities', []);

        $this->assertSame([], Reservation::find($this->reservation->id)->getCustomField('amenities'));
    }

    #[Test]
    public function sync_only_touches_the_given_keys(): void
    {
        $this->reservation->syncCustomFields(['guest_classification' => 'vip', 'internal_reference' => 'REF-001']);
        $this->reservation->syncCustomFields(['guest_classification' => 'corporate']);

        $this->assertSame(
            ['internal_reference' => 'REF-001', 'guest_classification' => 'corporate'],
            $this->reservation->listCustomFields(),
        );
    }

    #[Test]
    public function updating_a_value_keeps_one_row(): void
    {
        $this->reservation->setCustomField('guest_age', 20);
        $this->reservation->setCustomField('guest_age', 21);
        $this->reservation->setCustomField('guest_age', 21);

        $this->assertSame(1, CustomFieldValue::count());
        $this->assertSame(21, $this->reservation->getCustomField('guest_age'));
    }

    #[Test]
    public function sync_writes_nothing_when_any_value_is_invalid(): void
    {
        try {
            $this->reservation->syncCustomFields(['internal_reference' => 'REF', 'guest_age' => 12]);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertSame(['guest_age'], array_keys($e->errors()));
        }

        $this->assertSame(0, CustomFieldValue::count());
    }

    #[Test]
    public function sync_rolls_back_when_a_write_fails(): void
    {
        CustomFieldValue::creating(function (CustomFieldValue $value) {
            if ($value->value === 'boom') {
                throw new RuntimeException('Write failed');
            }
        });

        try {
            $this->reservation->syncCustomFields(['guest_classification' => 'vip', 'internal_reference' => 'boom']);
            $this->fail('Expected the write to fail.');
        } catch (RuntimeException) {
        }

        $this->assertSame(0, CustomFieldValue::count());
    }

    #[Test]
    public function invalid_select_options_are_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->reservation->setCustomField('guest_classification', 'gold');
    }

    #[Test]
    public function unknown_keys_throw_in_strict_mode(): void
    {
        try {
            $this->reservation->setCustomField('nope', 1);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('nope', $e->errors());
        }

        $this->expectException(CustomFieldException::class);
        $this->reservation->getCustomField('nope');
    }

    #[Test]
    public function unknown_keys_are_ignored_when_not_strict(): void
    {
        config(['custom-fields.strict' => false]);

        $this->reservation->syncCustomFields(['nope' => 1, 'guest_age' => 40]);
        $this->reservation->forgetCustomField('nope');

        $this->assertSame('x', $this->reservation->getCustomField('nope', 'x'));
        $this->assertSame(['guest_age' => 40], $this->reservation->listCustomFields());
    }

    #[Test]
    public function has_never_throws(): void
    {
        $this->assertFalse($this->reservation->hasCustomField('nope'));
        $this->assertFalse($this->reservation->hasCustomField('guest_age'));

        $this->reservation->setCustomField('guest_age', 30);
        $this->assertTrue($this->reservation->hasCustomField('guest_age'));
    }

    #[Test]
    public function inactive_fields_keep_their_values_but_reject_writes(): void
    {
        $this->reservation->setCustomField('guest_age', 30);
        CustomFields::deactivate(CustomFields::findByKey(Reservation::class, 'guest_age'));

        $this->assertSame(30, $this->reservation->getCustomField('guest_age'));

        foreach ([fn () => $this->reservation->setCustomField('guest_age', 31), fn () => $this->reservation->forgetCustomField('guest_age')] as $write) {
            try {
                $write();
                $this->fail('Expected a validation error.');
            } catch (ValidationException) {
            }
        }

        $this->assertSame(30, Reservation::find($this->reservation->id)->getCustomField('guest_age'));
    }

    #[Test]
    public function soft_deleted_fields_disappear_and_come_back(): void
    {
        $this->reservation->setCustomField('guest_age', 30);
        $field = CustomFields::findByKey(Reservation::class, 'guest_age');

        CustomFields::delete($field);
        $this->assertArrayNotHasKey('guest_age', Reservation::find($this->reservation->id)->listCustomFields());

        CustomFields::restore($field);
        $this->assertSame(30, Reservation::find($this->reservation->id)->getCustomField('guest_age'));
    }

    #[Test]
    public function it_forgets_a_value(): void
    {
        $this->reservation->syncCustomFields(['guest_age' => 30, 'internal_reference' => 'REF']);

        $this->assertSame($this->reservation, $this->reservation->forgetCustomField('guest_age'));
        $this->reservation->forgetCustomField('guest_age');

        $this->assertSame(['internal_reference' => 'REF'], $this->reservation->listCustomFields());
    }

    #[Test]
    public function required_fields_cannot_be_forgotten_or_nulled(): void
    {
        CustomFields::update(CustomFields::findByKey(Reservation::class, 'guest_age'), ['is_required' => true]);
        $this->reservation->setCustomField('guest_age', 30);

        foreach ([fn () => $this->reservation->forgetCustomField('guest_age'), fn () => $this->reservation->setCustomField('guest_age', null)] as $write) {
            try {
                $write();
                $this->fail('Expected a validation error.');
            } catch (ValidationException) {
            }
        }

        $this->assertSame(30, $this->reservation->getCustomField('guest_age'));
    }

    #[Test]
    public function values_are_listed_in_field_order(): void
    {
        $this->reservation->syncCustomFields(['guest_age' => 30, 'guest_classification' => 'vip', 'internal_reference' => 'REF']);

        $this->assertSame(['internal_reference', 'guest_classification', 'guest_age'], array_keys($this->reservation->listCustomFields()));
    }

    #[Test]
    public function custom_field_definitions_are_listed(): void
    {
        $this->assertSame(
            ['internal_reference', 'guest_classification', 'guest_age', 'amenities'],
            $this->reservation->customFieldDefinitions()->keys()->all(),
        );
    }

    #[Test]
    public function unsaved_models_cannot_be_written(): void
    {
        $this->expectException(CustomFieldException::class);

        (new Reservation(['code' => 'new']))->setCustomField('guest_age', 30);
    }

    #[Test]
    public function eager_loading_avoids_n_plus_one(): void
    {
        foreach (range(1, 5) as $i) {
            Reservation::create(['code' => "R{$i}0"])->syncCustomFields(['guest_age' => 20 + $i, 'guest_classification' => 'vip']);
        }
        CustomFields::flush();

        DB::enableQueryLog();
        $values = Reservation::withCustomFields()->get()->map->listCustomFields();
        $queries = count(DB::getQueryLog());

        $this->assertSame(3, $queries, 'reservations + values + field definitions');
        $this->assertSame(['guest_classification' => 'vip', 'guest_age' => 25], $values->last());
    }

    #[Test]
    public function fields_belong_to_one_model(): void
    {
        $this->field('guest_age', 'integer', ['model' => Guest::class]);
        $guest = Guest::create(['name' => 'Ken']);

        $guest->setCustomField('guest_age', 50);
        $this->reservation->setCustomField('guest_age', 20);

        $this->assertSame(50, $guest->getCustomField('guest_age'));
        $this->assertSame(20, $this->reservation->getCustomField('guest_age'));
        $this->assertNotSame(
            CustomFields::findByKey(Guest::class, 'guest_age')->id,
            CustomFields::findByKey(Reservation::class, 'guest_age')->id,
        );
    }

    #[Test]
    public function it_works_with_a_morph_map(): void
    {
        Relation::morphMap(['guest' => Guest::class]);
        $this->field('vip', 'boolean', ['model' => Guest::class]);

        $guest = Guest::create(['name' => 'Ken'])->setCustomField('vip', true);

        $this->assertSame('guest', CustomFieldValue::first()->entity_type);
        $this->assertTrue(Guest::find($guest->id)->getCustomField('vip'));
    }

    #[Test]
    public function deleting_a_model_deletes_its_values(): void
    {
        $this->reservation->setCustomField('guest_age', 30);
        $other = Reservation::create(['code' => 'R2'])->setCustomField('guest_age', 40);

        $this->reservation->delete();

        $this->assertSame(1, CustomFieldValue::count());
        $this->assertSame(40, $other->getCustomField('guest_age'));
    }

    #[Test]
    public function soft_deleted_models_keep_values_until_force_deleted(): void
    {
        $this->field('vip', 'boolean', ['model' => Guest::class]);
        $guest = Guest::create(['name' => 'Ken'])->setCustomField('vip', true);

        $guest->delete();
        $this->assertSame(1, CustomFieldValue::count());

        $guest->restore();
        $this->assertTrue(Guest::find($guest->id)->getCustomField('vip'));

        $guest->forceDelete();
        $this->assertSame(0, CustomFieldValue::count());
    }

    #[Test]
    public function value_cleanup_can_be_turned_off(): void
    {
        config(['custom-fields.delete_values_with_model' => false]);
        $this->reservation->setCustomField('guest_age', 30);

        $this->reservation->delete();

        $this->assertSame(1, CustomFieldValue::count());
    }

    #[Test]
    public function large_bulk_assignments_work(): void
    {
        foreach (range(1, 60) as $i) {
            $this->field("f{$i}", 'integer');
        }

        $input = [];
        foreach (range(1, 60) as $i) {
            $input["f{$i}"] = $i;
        }

        $this->reservation->syncCustomFields($input);

        $this->assertSame($input, Reservation::find($this->reservation->id)->listCustomFields());
    }

    #[Test]
    public function definitions_cannot_be_changed_through_values(): void
    {
        $this->reservation->syncCustomFields(['guest_age' => 30]);

        $this->assertSame(['min:18'], CustomField::where('key', 'guest_age')->first()->validation_rules);
        $this->assertSame(4, CustomField::count());
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function field(string $key, string $type, array $extra = []): CustomField
    {
        return CustomFields::create([
            'model' => Reservation::class,
            'key' => $key,
            'label' => ucfirst(str_replace('_', ' ', $key)),
            'type' => $type,
            ...$extra,
        ]);
    }
}
