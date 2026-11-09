<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Tests\Integration;

use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use KenPrz\CustomFields\Facades\CustomFields;
use KenPrz\CustomFields\Models\CustomField;
use KenPrz\CustomFields\Models\CustomFieldValue;
use KenPrz\CustomFields\Tests\Fixtures\FixedScopeResolver;
use KenPrz\CustomFields\Tests\Fixtures\Reservation;
use KenPrz\CustomFields\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class EdgeCaseTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        FixedScopeResolver::$scope = null;

        parent::tearDown();
    }

    #[Test]
    public function a_value_row_inserted_after_our_lookup_is_updated_not_duplicated(): void
    {
        $field = $this->field('notes');
        $reservation = Reservation::create(['code' => 'R1']);
        $this->insertBehindOurBack(DB::connection(), $field, $reservation);

        $reservation->syncCustomFields(['notes' => 'ours']);

        $this->assertSame(1, CustomFieldValue::count());
        $this->assertSame('ours', Reservation::find($reservation->id)->getCustomField('notes'));
    }

    #[Test]
    public function a_value_row_committed_by_another_connection_is_updated_not_duplicated(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('In-memory SQLite has no second connection to race with. Runs on MySQL and Postgres.');
        }

        config(['database.connections.racer' => config('database.connections.'.config('database.default'))]);
        $field = $this->field('notes');
        $reservation = Reservation::create(['code' => 'R1']);
        $this->insertBehindOurBack(DB::connection('racer'), $field, $reservation);

        // Our own transaction has already read the table when the other connection commits,
        // which is the case MySQL's repeatable read hides from a plain select.
        DB::transaction(fn () => $reservation->syncCustomFields(['notes' => 'ours']));

        $this->assertSame(1, CustomFieldValue::count());
        $this->assertSame('ours', Reservation::find($reservation->id)->getCustomField('notes'));
    }

    /**
     * Insert the value row right after our "does it exist?" lookup runs, the way a
     * concurrent request would.
     */
    private function insertBehindOurBack(Connection $racer, CustomField $field, Reservation $reservation): void
    {
        $done = false;

        DB::listen(function ($query) use (&$done, $racer, $field, $reservation) {
            if ($done || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, 'custom_field_values')) {
                return;
            }

            $done = true;
            $racer->table('custom_field_values')->insert([
                'custom_field_id' => $field->id,
                'entity_type' => $reservation->getMorphClass(),
                'entity_id' => $reservation->id,
                'value' => json_encode('theirs'),
            ]);
        });
    }

    #[Test]
    public function fields_added_later_work_on_existing_records(): void
    {
        $reservation = Reservation::create(['code' => 'old']);

        $this->field('notes');
        $reservation->setCustomField('notes', 'added later');

        $this->assertSame(['notes' => 'added later'], Reservation::find($reservation->id)->getCustomFieldValues());
    }

    #[Test]
    public function the_type_can_change_again_after_values_are_force_deleted(): void
    {
        $field = $this->field('notes');
        Reservation::create(['code' => 'R1'])->setCustomField('notes', 'x');

        $field->values()->delete();
        CustomFields::update($field, ['type' => 'integer']);

        $this->assertSame('integer', $field->fresh()->type);
    }

    #[Test]
    public function a_resolver_returning_null_means_global_only(): void
    {
        config(['custom-fields.scope_resolver' => FixedScopeResolver::class]);
        $this->field('notes');

        $this->assertNull(CustomFields::currentScope());
        $this->assertSame(['notes'], CustomFields::fieldsFor(Reservation::class)->keys()->all());
    }

    #[Test]
    public function a_corrupt_stored_value_reads_as_null(): void
    {
        $field = $this->field('notes');
        $reservation = Reservation::create(['code' => 'R1']);
        DB::table('custom_field_values')->insert([
            'custom_field_id' => $field->id,
            'entity_type' => $reservation->getMorphClass(),
            'entity_id' => $reservation->id,
            'value' => json_encode('fine'),
        ]);

        // SQLite stores JSON as text, so it is the only driver that can hold broken JSON.
        if (DB::getDriverName() === 'sqlite') {
            DB::table('custom_field_values')->update(['value' => '{broken']);
            $this->assertNull(Reservation::find($reservation->id)->getCustomField('notes'));
        }

        $this->assertSame(1, CustomFieldValue::count());
    }

    #[Test]
    public function values_survive_unrelated_field_changes(): void
    {
        $this->field('a');
        $b = $this->field('b');
        $reservation = Reservation::create(['code' => 'R1'])->syncCustomFields(['a' => 'one', 'b' => 'two']);

        CustomFields::update($b, ['label' => 'Bee', 'key' => 'bee', 'sort_order' => 9]);
        CustomFields::deactivate($b);

        $this->assertSame(['a' => 'one', 'bee' => 'two'], Reservation::find($reservation->id)->getCustomFieldValues());
    }

    private function field(string $key): CustomField
    {
        return CustomFields::create(['model' => Reservation::class, 'key' => $key, 'label' => ucfirst($key), 'type' => 'text']);
    }
}
