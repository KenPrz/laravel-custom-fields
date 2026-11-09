<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Tests\Feature;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use KenPrz\CustomFields\Models\CustomField;
use KenPrz\CustomFields\Models\CustomFieldValue;
use KenPrz\CustomFields\Tests\Fixtures\CustomFieldOverride;
use KenPrz\CustomFields\Tests\Fixtures\Property;
use KenPrz\CustomFields\Tests\Fixtures\Reservation;
use KenPrz\CustomFields\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class ModelTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        Relation::morphMap([], false);

        parent::tearDown();
    }

    #[Test]
    public function a_field_gets_sane_defaults(): void
    {
        $field = $this->field('notes')->fresh();

        $this->assertSame([], $field->settings);
        $this->assertSame([], $field->validation_rules);
        $this->assertFalse($field->is_required);
        $this->assertTrue($field->is_active);
        $this->assertSame(0, $field->sort_order);
        $this->assertSame('', $field->scope_key);
    }

    #[Test]
    public function scope_columns_cannot_be_mass_assigned(): void
    {
        $field = new CustomField(['scope_type' => 'x', 'scope_id' => 1, 'scope_key' => 'x:1', 'key' => 'a']);

        $this->assertNull($field->scope_type);
        $this->assertNull($field->scope_id);
        $this->assertSame('a', $field->key);
    }

    #[Test]
    public function global_keys_are_unique_per_model(): void
    {
        $this->field('notes');

        $this->expectException(QueryException::class);
        $this->field('notes');
    }

    #[Test]
    public function the_same_key_works_on_another_model(): void
    {
        $this->field('notes');
        $this->field('notes', Property::class);

        $this->assertSame(2, CustomField::count());
    }

    #[Test]
    public function the_same_key_works_in_another_scope(): void
    {
        $a = Property::create(['name' => 'A']);
        $b = Property::create(['name' => 'B']);

        $this->field('notes');
        $this->field('notes', scope: $a);
        $this->field('notes', scope: $b);

        $this->assertSame(
            ['', Property::class.':'.$a->id, Property::class.':'.$b->id],
            CustomField::orderBy('id')->pluck('scope_key')->all(),
        );
    }

    #[Test]
    public function scoped_keys_are_unique_within_their_scope(): void
    {
        $property = Property::create(['name' => 'A']);
        $this->field('notes', scope: $property);

        $this->expectException(QueryException::class);
        $this->field('notes', scope: $property);
    }

    #[Test]
    public function a_soft_deleted_field_keeps_its_key_reserved(): void
    {
        $this->field('notes')->delete();

        $this->expectException(QueryException::class);
        $this->field('notes');
    }

    #[Test]
    public function soft_deleting_a_field_keeps_its_values(): void
    {
        $field = $this->field('notes');
        $value = $this->value($field, Reservation::create(['code' => 'R1']), 'hello');

        $field->delete();

        $this->assertTrue($value->exists && CustomFieldValue::whereKey($value->id)->exists());
        $this->assertTrue($value->fresh()->field->trashed());
    }

    #[Test]
    public function force_deleting_a_field_removes_its_values(): void
    {
        $field = $this->field('notes');
        $this->value($field, Reservation::create(['code' => 'R1']), 'hello');

        $field->forceDelete();

        $this->assertSame(0, CustomFieldValue::count());
    }

    #[Test]
    public function an_entity_has_one_value_per_field(): void
    {
        $field = $this->field('notes');
        $reservation = Reservation::create(['code' => 'R1']);
        $this->value($field, $reservation, 'a');

        $this->expectException(QueryException::class);
        $this->value($field, $reservation, 'b');
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function values(): array
    {
        return [
            'string' => ['hello'],
            'empty string' => [''],
            'integer' => [42],
            'negative integer' => [-7],
            'float' => [3.25],
            'true' => [true],
            'false' => [false],
            'null' => [null],
            'list' => [['a', 'b']],
            'empty list' => [[]],
            'object' => [['nested' => ['n' => 1, 'ok' => true]]],
            'unicode' => ['日本語 ✓'],
        ];
    }

    #[Test]
    #[DataProvider('values')]
    public function values_keep_their_type(mixed $input): void
    {
        $value = $this->value($this->field('x'), Reservation::create(['code' => 'R1']), $input);

        $this->assertSame($input, CustomFieldValue::find($value->id)->value);
    }

    #[Test]
    public function setting_key_order_survives_every_database(): void
    {
        $options = ['zebra' => 'Z', 'a' => 'A', 'middle_one' => 'M', 'b' => 'B'];
        $field = $this->field('tier');
        $field->settings = ['options' => $options];
        $field->save();

        $this->assertSame($options, CustomField::find($field->id)->settings['options']);
    }

    #[Test]
    public function the_entity_relation_uses_the_morph_map(): void
    {
        Relation::morphMap(['reservation' => Reservation::class]);

        $reservation = Reservation::create(['code' => 'R1']);
        $value = $this->value($this->field('notes'), $reservation, 'x');

        $this->assertSame('reservation', $value->fresh()->entity_type);
        $this->assertTrue($value->fresh()->entity->is($reservation));
    }

    #[Test]
    public function relations_resolve_both_ways(): void
    {
        $property = Property::create(['name' => 'A']);
        $field = $this->field('notes', scope: $property);
        $value = $this->value($field, Reservation::create(['code' => 'R1']), 'x');

        $this->assertTrue($field->scope->is($property));
        $this->assertTrue($field->values->first()->is($value));
        $this->assertTrue($value->field->is($field));
    }

    #[Test]
    public function the_value_relation_uses_the_configured_field_model(): void
    {
        config(['custom-fields.models.field' => CustomFieldOverride::class]);

        $value = $this->value($this->field('notes'), Reservation::create(['code' => 'R1']), 'x');

        $this->assertInstanceOf(CustomFieldOverride::class, $value->fresh()->field);
    }

    private function field(string $key, string $model = Reservation::class, ?Property $scope = null): CustomField
    {
        $field = new CustomField([
            'model_type' => $model,
            'key' => $key,
            'label' => ucfirst($key),
            'type' => 'text',
        ]);

        if ($scope) {
            $field->scope()->associate($scope);
        }

        $field->save();

        return $field;
    }

    private function value(CustomField $field, Reservation $entity, mixed $value): CustomFieldValue
    {
        $model = new CustomFieldValue(['value' => $value]);
        $model->field()->associate($field);
        $model->entity()->associate($entity);
        $model->save();

        return $model;
    }
}
