<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Tests\Feature;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use KenPrz\CustomFields\CustomFieldManager;
use KenPrz\CustomFields\Exceptions\CustomFieldException;
use KenPrz\CustomFields\Facades\CustomFields;
use KenPrz\CustomFields\Models\CustomField;
use KenPrz\CustomFields\Tests\Fixtures\Guest;
use KenPrz\CustomFields\Tests\Fixtures\Member;
use KenPrz\CustomFields\Tests\Fixtures\Property;
use KenPrz\CustomFields\Tests\Fixtures\Reservation;
use KenPrz\CustomFields\Tests\Fixtures\ShapedMember;
use KenPrz\CustomFields\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Manager behaviour that mutation testing showed the other suites didn't pin down.
 */
class ManagerHardeningTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        Relation::morphMap([], false);

        parent::tearDown();
    }

    #[Test]
    public function a_scoped_field_wins_even_when_it_was_created_first(): void
    {
        $hotel = Property::create(['name' => 'A']);
        $scoped = CustomFields::forScope($hotel)->create(['model' => Reservation::class, 'key' => 'tier', 'label' => 'Scoped', 'type' => 'text']);
        CustomFields::create(['model' => Reservation::class, 'key' => 'tier', 'label' => 'Global', 'type' => 'text']);

        $this->assertTrue(CustomFields::forScope($hotel)->findByKey(Reservation::class, 'tier')->is($scoped));
    }

    #[Test]
    public function cached_definitions_never_mix_models_or_scopes(): void
    {
        config(['cache.default' => 'array', 'custom-fields.cache.enabled' => true]);
        $a = Property::create(['name' => 'A']);
        $b = Property::create(['name' => 'B']);
        CustomFields::forScope($a)->create(['model' => Reservation::class, 'key' => 'a_only', 'label' => 'A', 'type' => 'text']);
        CustomFields::forScope($b)->create(['model' => Reservation::class, 'key' => 'b_only', 'label' => 'B', 'type' => 'text']);
        CustomFields::create(['model' => Guest::class, 'key' => 'guest_only', 'label' => 'G', 'type' => 'text']);

        // Fill the cache for every combination, then read each one back in a fresh request.
        foreach ([[$a, Reservation::class], [$b, Reservation::class], [null, Guest::class], [null, Reservation::class]] as [$scope, $model]) {
            CustomFields::forScope($scope)->fieldsFor($model);
        }
        $this->app->forgetScopedInstances();
        CustomFields::clearResolvedInstances();

        $this->assertSame(['a_only'], CustomFields::forScope($a)->fieldsFor(Reservation::class)->keys()->all());
        $this->assertSame(['b_only'], CustomFields::forScope($b)->fieldsFor(Reservation::class)->keys()->all());
        $this->assertSame(['guest_only'], CustomFields::global()->fieldsFor(Guest::class)->keys()->all());
        $this->assertSame([], CustomFields::global()->fieldsFor(Reservation::class)->keys()->all());
    }

    #[Test]
    public function field_keys_are_loaded_once_per_model_and_kept_apart(): void
    {
        CustomFields::create(['model' => Reservation::class, 'key' => 'r_key', 'label' => 'R', 'type' => 'text']);
        CustomFields::create(['model' => Guest::class, 'key' => 'g_key', 'label' => 'G', 'type' => 'text']);
        $manager = app(CustomFieldManager::class);
        $manager->flush();

        DB::enableQueryLog();
        $this->assertSame(['r_key'], $manager->keysFor(Reservation::class));
        $this->assertSame(['r_key'], $manager->keysFor(Reservation::class));
        $this->assertSame(['g_key'], $manager->keysFor(Guest::class));

        $this->assertCount(2, DB::getQueryLog());
    }

    #[Test]
    public function updating_other_attributes_keeps_custom_settings(): void
    {
        $field = CustomFields::create(['model' => Reservation::class, 'key' => 'code_name', 'label' => 'Code', 'type' => 'text', 'settings' => ['max_length' => 12]]);

        CustomFields::update($field, ['label' => 'Code name']);

        $this->assertSame(['max_length' => 12], $field->fresh()->settings);
    }

    #[Test]
    public function changing_the_type_checks_settings_against_the_new_type(): void
    {
        $field = CustomFields::create(['model' => Reservation::class, 'key' => 'tier', 'label' => 'Tier', 'type' => 'text']);

        try {
            CustomFields::update($field, ['type' => 'select']);
            $this->fail('A select without options should be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('settings.options', $e->errors());
        }

        $this->assertSame('text', $field->fresh()->type);
    }

    #[Test]
    public function renaming_a_key_to_a_column_is_rejected(): void
    {
        $field = CustomFields::create(['model' => Reservation::class, 'key' => 'notes', 'label' => 'Notes', 'type' => 'text']);

        try {
            CustomFields::update($field, ['key' => 'code']);
            $this->fail('Expected the rename to be rejected.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already used by the', $e->errors()['key'][0]);
        }

        $this->assertSame('notes', $field->fresh()->key);
    }

    #[Test]
    public function restoring_a_field_of_another_scope_is_rejected(): void
    {
        $field = CustomFields::forScope(Property::create(['name' => 'A']))->create(['model' => Reservation::class, 'key' => 'x', 'label' => 'X', 'type' => 'text']);
        $field->delete();

        $this->expectException(CustomFieldException::class);
        CustomFields::forScope(Property::create(['name' => 'B']))->restore($field);
    }

    #[Test]
    public function reordering_with_repeated_keys_uses_the_first_position(): void
    {
        foreach (['a', 'b', 'c'] as $key) {
            CustomFields::create(['model' => Reservation::class, 'key' => $key, 'label' => $key, 'type' => 'text']);
        }

        CustomFields::reorder(Reservation::class, ['c', 'a', 'c', 'b']);

        $this->assertSame(['c', 'a', 'b'], CustomFields::fieldsFor(Reservation::class)->keys()->all());
        $this->assertSame([0, 1, 2], CustomField::orderBy('sort_order')->pluck('sort_order')->all());
    }

    #[Test]
    public function check_returns_the_definition_as_it_would_be_stored(): void
    {
        $definition = CustomFields::check(['model' => Reservation::class, 'key' => 'notes', 'label' => 'Notes', 'type' => 'text']);

        $this->assertSame([
            'model' => Reservation::class,
            'key' => 'notes',
            'label' => 'Notes',
            'description' => null,
            'type' => 'text',
            'settings' => ['max_length' => 255],
            'validation_rules' => [],
            'is_required' => false,
            'is_active' => true,
            'is_searchable' => false,
            'is_filterable' => false,
            'sort_order' => 0,
        ], $definition);
        $this->assertSame(0, CustomField::count());
    }

    #[Test]
    public function key_error_explains_each_problem(): void
    {
        CustomFields::create(['model' => Reservation::class, 'key' => 'notes', 'label' => 'Notes', 'type' => 'text']);
        $format = 'The key must start with a letter and contain only lowercase letters, numbers and underscores.';

        $this->assertSame($format, CustomFields::keyError(Reservation::class, 'Bad Key'));
        $this->assertSame($format, CustomFields::keyError(Reservation::class, '1abc'));
        $this->assertSame($format, CustomFields::keyError(Reservation::class, 'abc!'));
        $this->assertSame($format, CustomFields::keyError(Reservation::class, 'a'.str_repeat('b', 64)));
        $this->assertSame('The key [notes] is already in use.', CustomFields::keyError(Reservation::class, 'notes'));
        $this->assertStringContainsString('already used by the', CustomFields::keyError(Reservation::class, 'code'));
        $this->assertNull(CustomFields::keyError(Reservation::class, 'a'.str_repeat('b', 63)));
    }

    #[Test]
    public function a_taken_key_is_reported_before_a_shadowed_one(): void
    {
        // Created directly, so the shadow check that create() runs doesn't stop it.
        (new CustomField(['model_type' => Reservation::class, 'key' => 'code', 'label' => 'Code', 'type' => 'text']))->save();

        $this->assertSame('The key [code] is already in use.', CustomFields::keyError(Reservation::class, 'code'));
    }

    #[Test]
    public function definitions_need_a_model_key_label_and_type(): void
    {
        foreach (['model', 'key', 'label', 'type'] as $missing) {
            $attributes = ['model' => Reservation::class, 'key' => 'notes', 'label' => 'Notes', 'type' => 'text'];
            unset($attributes[$missing]);

            try {
                CustomFields::create($attributes);
                $this->fail("Expected a missing {$missing} to be rejected.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($missing, $e->errors(), $missing);
            }
        }
    }

    #[Test]
    public function definition_details_are_validated(): void
    {
        $field = CustomFields::create(['model' => Reservation::class, 'key' => 'notes', 'label' => 'Notes', 'type' => 'text', 'description' => null]);
        $this->assertNull($field->description);

        try {
            CustomFields::create(['model' => Reservation::class, 'key' => 'other', 'label' => 'Other', 'type' => 'text', 'validation_rules' => [123]]);
            $this->fail('Non-string rules should be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('validation_rules.0', $e->errors());
        }

        try {
            CustomFields::create(['model' => Reservation::class, 'key' => 'Other Key', 'label' => 'Other', 'type' => 'text']);
            $this->fail('A bad key should be rejected.');
        } catch (ValidationException $e) {
            $this->assertSame(['The key must start with a letter and contain only lowercase letters, numbers and underscores.'], $e->errors()['key']);
        }
    }

    #[Test]
    public function definition_flags_must_have_the_right_type(): void
    {
        foreach ([
            'is_required' => 'yes', 'is_active' => 'no', 'is_searchable' => 'maybe', 'is_filterable' => [],
            'sort_order' => 'first', 'settings' => 'max_length=5', 'validation_rules' => 'min:2',
        ] as $attribute => $value) {
            try {
                CustomFields::create(['model' => Reservation::class, 'key' => 'notes', 'label' => 'Notes', 'type' => 'text', $attribute => $value]);
                $this->fail("Expected {$attribute} to be rejected.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($attribute, $e->errors(), $attribute);
            }
        }

        $this->assertSame(0, CustomField::count());
    }

    #[Test]
    public function a_taken_key_is_reported_on_the_key(): void
    {
        CustomFields::create(['model' => Reservation::class, 'key' => 'a', 'label' => 'A', 'type' => 'text']);
        $b = CustomFields::create(['model' => Reservation::class, 'key' => 'b', 'label' => 'B', 'type' => 'text']);

        try {
            CustomFields::update($b, ['key' => 'a']);
            $this->fail('Expected the rename to be rejected.');
        } catch (ValidationException $e) {
            $this->assertSame(['key' => ['The key [a] is already in use.']], $e->errors());
        }
    }

    #[Test]
    public function keys_are_checked_against_the_model_behind_a_morph_alias(): void
    {
        Relation::morphMap(['member' => Member::class]);

        $this->expectException(ValidationException::class);
        CustomFields::create(['model' => 'member', 'key' => 'name', 'label' => 'Name', 'type' => 'text']);
    }

    #[Test]
    public function casts_and_set_mutators_count_as_taken(): void
    {
        foreach (['vip_flag' => ShapedMember::class, 'plain_note' => ShapedMember::class] as $key => $model) {
            try {
                CustomFields::create(['model' => $model, 'key' => $key, 'label' => 'X', 'type' => 'text']);
                $this->fail("Expected [{$key}] to be rejected on {$model}.");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('already used by the', $e->errors()['key'][0]);
            }
        }
    }
}
