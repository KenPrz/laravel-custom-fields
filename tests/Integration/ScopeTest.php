<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Tests\Integration;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use KenPrz\CustomFields\Exceptions\CustomFieldException;
use KenPrz\CustomFields\Facades\CustomFields;
use KenPrz\CustomFields\Models\CustomField;
use KenPrz\CustomFields\Tests\Fixtures\FixedScopeResolver;
use KenPrz\CustomFields\Tests\Fixtures\Property;
use KenPrz\CustomFields\Tests\Fixtures\Reservation;
use KenPrz\CustomFields\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ScopeTest extends TestCase
{
    use DatabaseMigrations;

    private Property $hotelA;

    private Property $hotelB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hotelA = Property::create(['name' => 'A']);
        $this->hotelB = Property::create(['name' => 'B']);
    }

    protected function tearDown(): void
    {
        FixedScopeResolver::$scope = null;

        parent::tearDown();
    }

    #[Test]
    public function without_a_scope_only_global_fields_are_visible(): void
    {
        $this->field(null, 'global');
        $this->field($this->hotelA, 'a_only');

        $this->assertSame(['global'], CustomFields::fieldsFor(Reservation::class)->keys()->all());
    }

    #[Test]
    public function a_scope_sees_global_fields_and_its_own(): void
    {
        $this->field(null, 'global');
        $this->field($this->hotelA, 'a_only');
        $this->field($this->hotelB, 'b_only');

        $this->assertSame(['global', 'a_only'], CustomFields::forScope($this->hotelA)->fieldsFor(Reservation::class)->keys()->all());
        $this->assertSame(['global', 'b_only'], CustomFields::forScope($this->hotelB)->fieldsFor(Reservation::class)->keys()->all());
    }

    #[Test]
    public function a_scoped_field_replaces_a_global_one_with_the_same_key(): void
    {
        $global = $this->field(null, 'tier');
        $scoped = $this->field($this->hotelA, 'tier');

        $this->assertTrue(CustomFields::forScope($this->hotelA)->findByKey(Reservation::class, 'tier')->is($scoped));
        $this->assertTrue(CustomFields::forScope($this->hotelB)->findByKey(Reservation::class, 'tier')->is($global));
        $this->assertTrue(CustomFields::findByKey(Reservation::class, 'tier')->is($global));
    }

    #[Test]
    public function fields_of_another_scope_cannot_be_found_or_changed(): void
    {
        $field = $this->field($this->hotelA, 'secret');
        $b = CustomFields::forScope($this->hotelB);

        $this->assertNull($b->find($field->id));
        $this->assertNull($b->findByKey(Reservation::class, 'secret'));

        foreach ([
            fn () => $b->update($field, ['label' => 'Hacked']),
            fn () => $b->deactivate($field),
            fn () => $b->delete($field),
            fn () => $b->forceDelete($field),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected an out of scope exception.');
            } catch (CustomFieldException) {
            }
        }

        $this->assertSame('Secret', $field->fresh()->label);
        $this->assertTrue($field->fresh()->is_active);
    }

    #[Test]
    public function the_resolver_provides_the_default_scope(): void
    {
        config(['custom-fields.scope_resolver' => FixedScopeResolver::class]);
        $this->field($this->hotelA, 'a_only');

        FixedScopeResolver::$scope = $this->hotelA;
        $this->assertTrue(CustomFields::fieldsFor(Reservation::class)->has('a_only'));

        FixedScopeResolver::$scope = $this->hotelB;
        $this->assertFalse(CustomFields::fieldsFor(Reservation::class)->has('a_only'));
    }

    #[Test]
    public function an_explicit_scope_beats_the_resolver(): void
    {
        config(['custom-fields.scope_resolver' => FixedScopeResolver::class]);
        FixedScopeResolver::$scope = $this->hotelA;
        $this->field($this->hotelA, 'a_only');

        $this->assertFalse(CustomFields::global()->fieldsFor(Reservation::class)->has('a_only'));
        $this->assertFalse(CustomFields::forScope($this->hotelB)->fieldsFor(Reservation::class)->has('a_only'));
    }

    #[Test]
    public function created_fields_land_in_the_current_scope(): void
    {
        config(['custom-fields.scope_resolver' => FixedScopeResolver::class]);
        FixedScopeResolver::$scope = $this->hotelA;

        $field = $this->field(null, 'x', useResolver: true);

        $this->assertTrue($field->scope->is($this->hotelA));
    }

    #[Test]
    public function two_scopes_can_use_the_same_key(): void
    {
        $this->field($this->hotelA, 'vip_reference');
        $this->field($this->hotelB, 'vip_reference');

        $this->assertCount(1, CustomFields::forScope($this->hotelA)->fieldsFor(Reservation::class));
    }

    private function field(?Property $scope, string $key, bool $useResolver = false): CustomField
    {
        $manager = $useResolver ? CustomFields::getFacadeRoot() : CustomFields::forScope($scope);

        return $manager->create([
            'model' => Reservation::class,
            'key' => $key,
            'label' => ucfirst($key),
            'type' => 'text',
        ]);
    }
}
