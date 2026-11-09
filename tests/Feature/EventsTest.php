<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use KenPrz\CustomFields\Events\CustomFieldActivated;
use KenPrz\CustomFields\Events\CustomFieldCreated;
use KenPrz\CustomFields\Events\CustomFieldDeactivated;
use KenPrz\CustomFields\Events\CustomFieldDeleted;
use KenPrz\CustomFields\Events\CustomFieldUpdated;
use KenPrz\CustomFields\Events\CustomFieldValueCreated;
use KenPrz\CustomFields\Events\CustomFieldValueDeleted;
use KenPrz\CustomFields\Events\CustomFieldValueUpdated;
use KenPrz\CustomFields\Facades\CustomFields;
use KenPrz\CustomFields\Models\CustomField;
use KenPrz\CustomFields\Tests\Fixtures\Reservation;
use KenPrz\CustomFields\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class EventsTest extends TestCase
{
    use DatabaseMigrations;

    private const EVENTS = [
        CustomFieldCreated::class, CustomFieldUpdated::class, CustomFieldDeleted::class,
        CustomFieldActivated::class, CustomFieldDeactivated::class,
        CustomFieldValueCreated::class, CustomFieldValueUpdated::class, CustomFieldValueDeleted::class,
    ];

    #[Test]
    public function field_lifecycle_events(): void
    {
        Event::fake(self::EVENTS);

        $field = $this->field();
        Event::assertDispatched(CustomFieldCreated::class, fn ($e) => $e->field->is($field));

        CustomFields::update($field, ['label' => 'Renamed']);
        Event::assertDispatched(CustomFieldUpdated::class);
        Event::assertNotDispatched(CustomFieldDeactivated::class);

        CustomFields::deactivate($field);
        Event::assertDispatched(CustomFieldDeactivated::class, fn ($e) => $e->field->is($field));

        CustomFields::activate($field);
        Event::assertDispatched(CustomFieldActivated::class, fn ($e) => $e->field->is($field));

        CustomFields::delete($field);
        Event::assertDispatched(CustomFieldDeleted::class, fn ($e) => ! $e->forced);

        CustomFields::forceDelete($field);
        Event::assertDispatched(CustomFieldDeleted::class, fn ($e) => $e->forced);
    }

    #[Test]
    public function an_update_without_changes_fires_nothing(): void
    {
        $field = $this->field();
        Event::fake(self::EVENTS);

        CustomFields::update($field, ['label' => 'Notes']);
        CustomFields::activate($field);

        Event::assertNothingDispatched();
    }

    #[Test]
    public function value_lifecycle_events(): void
    {
        $this->field();
        $reservation = Reservation::create(['code' => 'R1']);
        Event::fake(self::EVENTS);

        $reservation->setCustomField('notes', 'one');
        Event::assertDispatched(CustomFieldValueCreated::class, fn ($e) => $e->value->value === 'one' && $e->field->key === 'notes' && $e->entity->is($reservation));

        $reservation->setCustomField('notes', 'one');
        Event::assertNotDispatched(CustomFieldValueUpdated::class);

        $reservation->setCustomField('notes', 'two');
        Event::assertDispatched(CustomFieldValueUpdated::class, fn ($e) => $e->oldValue === 'one' && $e->value->value === 'two');

        $reservation->forgetCustomField('notes');
        Event::assertDispatched(CustomFieldValueDeleted::class, fn ($e) => $e->value->value === 'two');
    }

    #[Test]
    public function events_can_be_turned_off(): void
    {
        config(['custom-fields.events' => false]);
        Event::fake(self::EVENTS);

        $this->field();
        Reservation::create(['code' => 'R1'])->setCustomField('notes', 'x');

        Event::assertNothingDispatched();
    }

    #[Test]
    public function events_wait_for_the_transaction(): void
    {
        $this->field();
        $reservation = Reservation::create(['code' => 'R1']);
        $fired = [];
        Event::listen(CustomFieldValueCreated::class, function () use (&$fired) {
            $fired[] = 'created';
        });

        try {
            DB::transaction(function () use ($reservation) {
                $reservation->setCustomField('notes', 'x');
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame([], $fired);

        $reservation->setCustomField('notes', 'y');
        $this->assertSame(['created'], $fired);
    }

    private function field(): CustomField
    {
        return CustomFields::create(['model' => Reservation::class, 'key' => 'notes', 'label' => 'Notes', 'type' => 'text']);
    }
}
