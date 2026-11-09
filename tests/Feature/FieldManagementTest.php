<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Tests\Feature;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use KenPrz\CustomFields\Exceptions\CustomFieldException;
use KenPrz\CustomFields\Facades\CustomFields;
use KenPrz\CustomFields\Models\CustomField;
use KenPrz\CustomFields\Models\CustomFieldValue;
use KenPrz\CustomFields\Tests\Fixtures\Property;
use KenPrz\CustomFields\Tests\Fixtures\Reservation;
use KenPrz\CustomFields\Tests\Fixtures\Uppercase;
use KenPrz\CustomFields\Tests\TestCase;
use KenPrz\CustomFields\Types\TextType;
use PHPUnit\Framework\Attributes\Test;

class FieldManagementTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        Relation::morphMap([], false);

        parent::tearDown();
    }

    #[Test]
    public function it_creates_a_field(): void
    {
        $field = CustomFields::create([
            'model' => Reservation::class,
            'key' => 'guest_classification',
            'label' => 'Guest Classification',
            'type' => 'select',
            'settings' => ['options' => ['vip' => 'VIP', 'walk_in' => 'Walk-in']],
            'validation_rules' => ['string'],
            'is_required' => true,
        ]);

        $field = $field->fresh();
        $this->assertSame(Reservation::class, $field->model_type);
        $this->assertSame('guest_classification', $field->key);
        $this->assertSame(['options' => ['vip' => 'VIP', 'walk_in' => 'Walk-in']], $field->settings);
        $this->assertSame(['string'], $field->validation_rules);
        $this->assertTrue($field->is_required);
        $this->assertNull($field->scope_type);
    }

    #[Test]
    public function it_merges_settings_over_the_type_defaults(): void
    {
        $field = $this->text('notes', ['settings' => ['extra' => true]]);

        $this->assertSame(['max_length' => 255, 'extra' => true], $field->settings);
    }

    #[Test]
    public function it_stores_the_morph_alias_when_the_model_has_one(): void
    {
        Relation::morphMap(['reservation' => Reservation::class]);

        $this->assertSame('reservation', $this->text('notes')->model_type);
        $this->assertSame('reservation', $this->text('other', ['model' => 'reservation'])->model_type);
    }

    #[Test]
    public function it_rejects_invalid_definitions(): void
    {
        $cases = [
            'model' => ['model' => 'NotAModel'],
            'key' => ['key' => 'Bad Key'],
            'type' => ['type' => 'nope'],
            'settings.options' => ['type' => 'select'],
            'settings.max_length' => ['settings' => ['max_length' => 0]],
            'validation_rules' => ['validation_rules' => ['not_a_rule']],
            'model_type' => ['model_type' => 'x'],
            'label' => ['label' => ''],
        ];

        foreach ($cases as $errorKey => $override) {
            try {
                $this->text('notes', $override);
                $this->fail("Expected [{$errorKey}] to be rejected.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($errorKey, $e->errors(), $errorKey);
            } catch (CustomFieldException $e) {
                $this->assertSame('model', $errorKey);
            }
        }

        $this->assertSame(0, CustomField::count());
    }

    #[Test]
    public function it_accepts_extension_rules_and_rule_classes(): void
    {
        Validator::extend('even', fn ($attribute, $value) => $value % 2 === 0);

        $field = $this->text('notes', ['validation_rules' => ['even', 'min:2', Uppercase::class]]);

        $this->assertCount(3, $field->validation_rules);
    }

    #[Test]
    public function it_rejects_a_duplicate_key(): void
    {
        $this->text('notes');

        $this->expectException(ValidationException::class);
        $this->text('notes');
    }

    #[Test]
    public function a_soft_deleted_key_stays_taken(): void
    {
        CustomFields::delete($this->text('notes'));

        $this->expectException(ValidationException::class);
        $this->text('notes');
    }

    #[Test]
    public function it_updates_a_field(): void
    {
        $field = CustomFields::update($this->text('notes'), ['label' => 'Internal notes', 'key' => 'internal_notes']);

        $this->assertSame('Internal notes', $field->fresh()->label);
        $this->assertNotNull(CustomFields::findByKey(Reservation::class, 'internal_notes'));
        $this->assertNull(CustomFields::findByKey(Reservation::class, 'notes'));
    }

    #[Test]
    public function the_model_cannot_be_changed(): void
    {
        $this->expectException(ValidationException::class);

        CustomFields::update($this->text('notes'), ['model' => Property::class]);
    }

    #[Test]
    public function renaming_to_a_taken_key_fails(): void
    {
        $this->text('a');

        $this->expectException(ValidationException::class);
        CustomFields::update($this->text('b'), ['key' => 'a']);
    }

    #[Test]
    public function the_type_can_change_while_there_are_no_values(): void
    {
        $field = CustomFields::update($this->text('notes'), ['type' => 'integer']);

        $this->assertSame('integer', $field->fresh()->type);
    }

    #[Test]
    public function the_type_is_locked_once_values_exist(): void
    {
        $field = $this->text('notes');
        $this->storeValue($field, 'hello');

        try {
            CustomFields::update($field, ['type' => 'integer']);
            $this->fail('Expected the type change to be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('type', $e->errors());
        }

        $this->assertSame('text', $field->fresh()->type);
        $this->assertSame('hello', CustomFieldValue::first()->value);
    }

    #[Test]
    public function new_settings_are_checked_against_the_current_type(): void
    {
        $field = CustomFields::create([
            'model' => Reservation::class, 'key' => 'tier', 'label' => 'Tier', 'type' => 'select',
            'settings' => ['options' => ['a' => 'A']],
        ]);

        $this->expectException(ValidationException::class);
        CustomFields::update($field, ['settings' => ['options' => []]]);
    }

    #[Test]
    public function it_activates_and_deactivates(): void
    {
        $field = CustomFields::deactivate($this->text('notes'));
        $this->assertFalse($field->fresh()->is_active);

        $field = CustomFields::activate($field);
        $this->assertTrue($field->fresh()->is_active);
    }

    #[Test]
    public function it_finds_fields(): void
    {
        $field = $this->text('notes');

        $this->assertTrue(CustomFields::find($field->id)->is($field));
        $this->assertTrue(CustomFields::findByKey(Reservation::class, 'notes')->is($field));
        $this->assertTrue(CustomFields::findByKey(new Reservation, 'notes')->is($field));
        $this->assertNull(CustomFields::findByKey(Property::class, 'notes'));
        $this->assertNull(CustomFields::find(999));
    }

    #[Test]
    public function it_lists_fields_by_sort_order_including_inactive_ones(): void
    {
        $this->text('c', ['sort_order' => 3]);
        $this->text('a', ['sort_order' => 1, 'is_active' => false]);
        $this->text('b', ['sort_order' => 2]);
        $this->text('other', ['model' => Property::class]);

        $this->assertSame(['a', 'b', 'c'], CustomFields::fieldsFor(Reservation::class)->keys()->all());
    }

    #[Test]
    public function it_reorders_fields(): void
    {
        $this->text('a');
        $this->text('b');
        $this->text('c');
        $this->text('d');

        CustomFields::reorder(Reservation::class, ['c', 'a']);

        $this->assertSame(['c', 'a', 'b', 'd'], CustomFields::fieldsFor(Reservation::class)->keys()->all());
        $this->assertSame([0, 1, 2, 3], CustomField::orderBy('sort_order')->pluck('sort_order')->all());
    }

    #[Test]
    public function reordering_an_unknown_key_changes_nothing(): void
    {
        $this->text('a', ['sort_order' => 5]);

        try {
            CustomFields::reorder(Reservation::class, ['a', 'nope']);
            $this->fail('Expected an exception.');
        } catch (CustomFieldException) {
        }

        $this->assertSame(5, CustomField::first()->sort_order);
    }

    #[Test]
    public function soft_delete_and_restore_keep_values(): void
    {
        $field = $this->text('notes');
        $this->storeValue($field, 'hello');

        CustomFields::delete($field);
        $this->assertNull(CustomFields::findByKey(Reservation::class, 'notes'));
        $this->assertSame(1, CustomFieldValue::count());

        CustomFields::restore($field);
        $this->assertNotNull(CustomFields::findByKey(Reservation::class, 'notes'));
    }

    #[Test]
    public function force_delete_removes_values(): void
    {
        $field = $this->text('notes');
        $this->storeValue($field, 'hello');

        CustomFields::forceDelete($field);

        $this->assertSame(0, CustomField::withTrashed()->count());
        $this->assertSame(0, CustomFieldValue::count());
    }

    #[Test]
    public function it_registers_custom_types(): void
    {
        CustomFields::registerType('currency', TextType::class);

        $this->assertSame('currency', $this->text('price', ['type' => 'currency'])->type);
    }

    #[Test]
    public function changes_made_outside_the_manager_are_seen(): void
    {
        $this->assertCount(0, CustomFields::fieldsFor(Reservation::class));

        $field = new CustomField(['model_type' => Reservation::class, 'key' => 'raw', 'label' => 'Raw', 'type' => 'text']);
        $field->save();

        $this->assertCount(1, CustomFields::fieldsFor(Reservation::class));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function text(string $key, array $overrides = []): CustomField
    {
        return CustomFields::create([
            'model' => Reservation::class,
            'key' => $key,
            'label' => ucfirst($key),
            'type' => 'text',
            ...$overrides,
        ]);
    }

    private function storeValue(CustomField $field, mixed $value): void
    {
        $model = new CustomFieldValue(['value' => $value]);
        $model->field()->associate($field);
        $model->entity()->associate(Reservation::create(['code' => 'R'.random_int(1, 99999)]));
        $model->save();
    }
}
