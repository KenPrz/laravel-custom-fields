<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use KenPrz\CustomFields\Exceptions\CustomFieldException;
use KenPrz\CustomFields\Facades\CustomFields;
use KenPrz\CustomFields\Tests\Fixtures\FixedScopeResolver;
use KenPrz\CustomFields\Tests\Fixtures\Property;
use KenPrz\CustomFields\Tests\Fixtures\Reservation;
use KenPrz\CustomFields\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class QueryTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        $options = ['vip' => 'VIP', 'corporate' => 'Corporate', 'walk_in' => 'Walk-in'];
        $amenities = ['wifi' => 'Wi-Fi', 'parking' => 'Parking', 'pool' => 'Pool'];

        foreach ([
            ['tier', 'select', ['options' => $options]],
            ['amenities', 'multiselect', ['options' => $amenities]],
            ['age', 'integer', []],
            ['score', 'number', []],
            ['price', 'decimal', []],
            ['notes', 'text', []],
            ['arrival', 'date', []],
            ['checked_in_at', 'datetime', []],
            ['vip', 'boolean', []],
            ['meta', 'json', []],
        ] as [$key, $type, $settings]) {
            CustomFields::create(['model' => Reservation::class, 'key' => $key, 'label' => $key, 'type' => $type, 'settings' => $settings]);
        }

        $this->reservation('A', [
            'tier' => 'vip', 'amenities' => ['wifi', 'pool'], 'age' => 30, 'score' => 5, 'price' => '10.50',
            'notes' => 'Late arrival, 50% deposit', 'arrival' => '2026-11-09', 'checked_in_at' => '2026-11-09T22:00:00+08:00',
            'vip' => true, 'meta' => ['source' => 'web'],
        ]);
        $this->reservation('B', [
            'tier' => 'corporate', 'amenities' => ['parking'], 'age' => 45, 'score' => 7.5, 'price' => '9.99',
            'notes' => 'Invoice to ACME', 'arrival' => '2026-12-01', 'checked_in_at' => '2026-11-09 15:00:00',
            'vip' => false, 'meta' => ['source' => 'phone'],
        ]);
        $this->reservation('C', ['tier' => 'walk_in', 'age' => 18, 'price' => '100', 'notes' => '日本語のメモ', 'arrival' => '2025-01-31']);
        $this->reservation('D', ['notes' => null]);
        $this->reservation('E', []);
    }

    protected function tearDown(): void
    {
        FixedScopeResolver::$scope = null;

        parent::tearDown();
    }

    #[Test]
    public function equality(): void
    {
        $this->assertCodes(['A'], Reservation::whereCustomField('tier', 'vip'));
        $this->assertCodes(['A'], Reservation::whereCustomField('tier', '=', 'vip'));
    }

    #[Test]
    public function inequality_skips_records_without_a_value(): void
    {
        $this->assertCodes(['B', 'C'], Reservation::whereCustomField('tier', '!=', 'vip'));
        $this->assertCodes(['B', 'C'], Reservation::whereCustomField('tier', '<>', 'vip'));
    }

    #[Test]
    public function integer_comparisons(): void
    {
        $this->assertCodes(['B'], Reservation::whereCustomField('age', '>', 30));
        $this->assertCodes(['A', 'B'], Reservation::whereCustomField('age', '>=', '30'));
        $this->assertCodes(['C'], Reservation::whereCustomField('age', '<', 30));
        $this->assertCodes(['A', 'C'], Reservation::whereCustomField('age', '<=', 30));
        $this->assertCodes(['A'], Reservation::whereCustomField('age', '30'));
    }

    #[Test]
    public function numbers_compare_as_numbers(): void
    {
        $this->assertCodes(['A'], Reservation::whereCustomField('score', 5.0));
        $this->assertCodes(['A'], Reservation::whereCustomField('score', '5'));
        $this->assertCodes(['B'], Reservation::whereCustomField('score', '>', 5));
    }

    #[Test]
    public function decimals_compare_as_numbers_not_text(): void
    {
        $this->assertCodes(['A', 'C'], Reservation::whereCustomField('price', '>', '9.99'));
        $this->assertCodes(['C'], Reservation::whereCustomField('price', '>=', 100));
        $this->assertCodes(['A'], Reservation::whereCustomField('price', '10.5'));
    }

    #[Test]
    public function dates_compare_in_time_order(): void
    {
        $this->assertCodes(['A', 'B'], Reservation::whereCustomField('arrival', '>=', '2026-11-09'));
        $this->assertCodes(['C'], Reservation::whereCustomField('arrival', '<', 'November 1, 2026'));
        $this->assertCodes(['B'], Reservation::whereCustomField('arrival', '2026-12-01'));
    }

    #[Test]
    public function datetimes_compare_in_utc(): void
    {
        // A checked in at 14:00 UTC, B at 15:00 UTC.
        $this->assertCodes(['A'], Reservation::whereCustomField('checked_in_at', '2026-11-09 14:00:00'));
        $this->assertCodes(['B'], Reservation::whereCustomField('checked_in_at', '>', '2026-11-09T22:30:00+08:00'));
    }

    #[Test]
    public function booleans(): void
    {
        $this->assertCodes(['A'], Reservation::whereCustomField('vip', true));
        $this->assertCodes(['B'], Reservation::whereCustomField('vip', '0'));
    }

    #[Test]
    public function json_equality(): void
    {
        $this->assertCodes(['B'], Reservation::whereCustomField('meta', ['source' => 'phone']));
    }

    #[Test]
    public function unicode_text(): void
    {
        $this->assertCodes(['C'], Reservation::whereCustomField('notes', '日本語のメモ'));
        $this->assertCodes(['C'], Reservation::whereCustomFieldContains('notes', '日本'));
    }

    #[Test]
    public function text_orders_byte_by_byte_on_every_driver(): void
    {
        // Byte order puts every uppercase letter before "a"; a case-insensitive collation wouldn't.
        $this->assertCodes(['A', 'B'], Reservation::whereCustomField('notes', '<', 'a'));
        // Byte order puts " " before "!"; a punctuation-blind collation wouldn't.
        $this->assertCodes(['B'], Reservation::whereCustomField('notes', '<', 'Invoice!'));
    }

    #[Test]
    public function in(): void
    {
        $this->assertCodes(['A', 'C'], Reservation::whereCustomFieldIn('tier', ['vip', 'walk_in']));
        $this->assertCodes(['B', 'C'], Reservation::whereCustomFieldIn('age', ['45', 18]));
        $this->assertCodes([], Reservation::whereCustomFieldIn('tier', []));
    }

    #[Test]
    public function contains_on_lists(): void
    {
        $this->assertCodes(['A'], Reservation::whereCustomFieldContains('amenities', 'pool'));
        $this->assertCodes(['B'], Reservation::whereCustomFieldContains('amenities', 'parking'));
        $this->assertCodes([], Reservation::whereCustomFieldContains('amenities', 'park'));
    }

    #[Test]
    public function contains_on_text_is_case_insensitive_and_escapes_wildcards(): void
    {
        $this->assertCodes(['B'], Reservation::whereCustomFieldContains('notes', 'acme'));
        $this->assertCodes(['A'], Reservation::whereCustomFieldContains('notes', '50%'));
        $this->assertCodes([], Reservation::whereCustomFieldContains('notes', '%'.'_'.'%'));
    }

    #[Test]
    public function null_checks(): void
    {
        $this->assertCodes(['C', 'D', 'E'], Reservation::whereCustomFieldNull('vip'));
        $this->assertCodes(['D', 'E'], Reservation::whereCustomFieldNull('notes'));
        $this->assertCodes(['D', 'E'], Reservation::whereCustomField('notes', null));
        $this->assertCodes(['A', 'B', 'C'], Reservation::whereCustomFieldNotNull('notes'));
        $this->assertCodes(['A', 'B', 'C'], Reservation::whereCustomField('notes', '!=', null));
    }

    #[Test]
    public function filters_combine(): void
    {
        $query = Reservation::whereCustomField('age', '>=', 18)
            ->whereCustomFieldContains('amenities', 'wifi')
            ->where('code', '!=', 'Z');

        $this->assertCodes(['A'], $query);
        $this->assertCodes(['A', 'B'], Reservation::whereCustomField('tier', 'vip')->orWhere(fn ($q) => $q->whereCustomField('age', 45)));
    }

    #[Test]
    public function values_are_validated_with_the_field_type(): void
    {
        $this->expectException(ValidationException::class);

        Reservation::whereCustomField('age', 'thirty')->get();
    }

    #[Test]
    public function select_values_must_be_options(): void
    {
        $this->expectException(ValidationException::class);

        Reservation::whereCustomField('tier', 'gold')->get();
    }

    #[Test]
    public function ordering_needs_a_number_or_text(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Reservation::whereCustomField('vip', '>', true)->get();
    }

    #[Test]
    public function unknown_operators_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Reservation::whereCustomField('age', '= 1 OR 1 =', 1)->get();
    }

    #[Test]
    public function values_cannot_inject_sql(): void
    {
        $payload = "x' OR '1'='1";

        $this->assertCodes([], Reservation::whereCustomField('notes', $payload));
        $this->assertCodes([], Reservation::whereCustomFieldContains('notes', $payload));
        $this->assertCodes([], Reservation::whereCustomField('notes', '<', "') OR 1=1 --"));
        $this->assertSame(5, Reservation::count());
    }

    #[Test]
    public function unknown_keys_throw_in_strict_mode(): void
    {
        $this->expectException(CustomFieldException::class);

        Reservation::whereCustomField('nope', 1)->get();
    }

    #[Test]
    public function unknown_keys_match_nothing_when_not_strict(): void
    {
        config(['custom-fields.strict' => false]);

        $this->assertCodes([], Reservation::whereCustomField('nope', 1));
        $this->assertCodes([], Reservation::whereCustomFieldNull('nope'));
    }

    #[Test]
    public function queries_use_the_current_scope(): void
    {
        $hotel = Property::create(['name' => 'A']);
        CustomFields::forScope($hotel)->create(['model' => Reservation::class, 'key' => 'room', 'label' => 'Room', 'type' => 'text']);
        config(['custom-fields.scope_resolver' => FixedScopeResolver::class]);

        FixedScopeResolver::$scope = $hotel;
        Reservation::where('code', 'A')->first()->setCustomField('room', '101');
        $this->assertCodes(['A'], Reservation::whereCustomField('room', '101'));

        FixedScopeResolver::$scope = null;
        $this->expectException(CustomFieldException::class);
        Reservation::whereCustomField('room', '101')->get();
    }

    #[Test]
    public function values_of_deleted_fields_are_not_matched(): void
    {
        CustomFields::delete(CustomFields::findByKey(Reservation::class, 'tier'));

        $this->expectException(CustomFieldException::class);
        Reservation::whereCustomField('tier', 'vip')->get();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function reservation(string $code, array $values): void
    {
        Reservation::create(['code' => $code])->syncCustomFields($values);
    }

    /**
     * @param  list<string>  $expected
     */
    private function assertCodes(array $expected, mixed $query): void
    {
        $this->assertSame($expected, $query->orderBy('code')->pluck('code')->all());
    }
}
