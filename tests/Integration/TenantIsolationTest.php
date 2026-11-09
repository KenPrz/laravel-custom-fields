<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Tests\Integration;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Validation\ValidationException;
use KenPrz\CustomFields\Facades\CustomFields;
use KenPrz\CustomFields\Models\CustomFieldValue;
use KenPrz\CustomFields\Tests\Fixtures\FixedScopeResolver;
use KenPrz\CustomFields\Tests\Fixtures\Property;
use KenPrz\CustomFields\Tests\Fixtures\PropertyReservation;
use KenPrz\CustomFields\Tests\Fixtures\Reservation;
use KenPrz\CustomFields\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class TenantIsolationTest extends TestCase
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
    public function a_model_uses_its_own_scope(): void
    {
        $this->field($this->hotelA, 'vip_reference');
        $this->field(null, 'notes');

        $a = PropertyReservation::create(['code' => 'A1', 'property_id' => $this->hotelA->id]);
        $b = PropertyReservation::create(['code' => 'B1', 'property_id' => $this->hotelB->id]);

        $a->syncCustomFields(['vip_reference' => 'VIP-1', 'notes' => 'hello']);
        $b->setCustomField('notes', 'world');

        $this->assertSame(['vip_reference', 'notes'], $a->customFields()->keys()->all());
        $this->assertSame(['notes'], $b->customFields()->keys()->all());

        $this->expectException(ValidationException::class);
        $b->setCustomField('vip_reference', 'sneaky');
    }

    #[Test]
    public function values_written_in_one_scope_are_invisible_in_another(): void
    {
        $this->field($this->hotelA, 'secret');
        $reservation = PropertyReservation::create(['code' => 'A1', 'property_id' => $this->hotelA->id]);
        $reservation->setCustomField('secret', 'classified');

        // The same row read through another tenant.
        $moved = PropertyReservation::find($reservation->id);
        $moved->property_id = $this->hotelB->id;

        $this->assertSame([], $moved->getCustomFieldValues());
        $this->assertFalse($moved->hasCustomField('secret'));
        $this->assertSame(1, CustomFieldValue::count(), 'the value is hidden, not deleted');
    }

    #[Test]
    public function a_scoped_field_shadows_the_global_one_on_read_and_write(): void
    {
        $this->field(null, 'tier');
        $this->field($this->hotelA, 'tier');

        $global = Reservation::create(['code' => 'G']);
        $global->setCustomField('tier', 'global value');

        $scoped = PropertyReservation::create(['code' => 'A1', 'property_id' => $this->hotelA->id]);
        $scoped->setCustomField('tier', 'scoped value');

        $this->assertSame('scoped value', $scoped->getCustomField('tier'));
        $this->assertSame('global value', $global->getCustomField('tier'));
        $this->assertSame(2, CustomFieldValue::count());
    }

    #[Test]
    public function the_resolver_scopes_plain_models(): void
    {
        config(['custom-fields.scope_resolver' => FixedScopeResolver::class]);
        CustomFields::forScope($this->hotelA)->create([
            'model' => Reservation::class, 'key' => 'vip_reference', 'label' => 'VIP reference', 'type' => 'text',
        ]);
        $reservation = Reservation::create(['code' => 'R']);

        FixedScopeResolver::$scope = $this->hotelA;
        $reservation->setCustomField('vip_reference', 'VIP');
        $this->assertSame('VIP', $reservation->getCustomField('vip_reference'));

        FixedScopeResolver::$scope = $this->hotelB;
        $this->assertSame([], Reservation::find($reservation->id)->getCustomFieldValues());

        FixedScopeResolver::$scope = null;
        $this->assertSame([], Reservation::find($reservation->id)->getCustomFieldValues());
    }

    private function field(?Property $scope, string $key): void
    {
        CustomFields::forScope($scope)->create([
            'model' => $scope ? PropertyReservation::class : Reservation::class,
            'key' => $key,
            'label' => ucfirst($key),
            'type' => 'text',
        ]);

        if ($scope === null) {
            CustomFields::create(['model' => PropertyReservation::class, 'key' => $key, 'label' => ucfirst($key), 'type' => 'text']);
        }
    }
}
