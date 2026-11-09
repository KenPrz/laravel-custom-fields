<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Tests\Feature;

use Illuminate\Database\Eloquent\MissingAttributeException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use KenPrz\CustomFields\Facades\CustomFields;
use KenPrz\CustomFields\Models\CustomFieldValue;
use KenPrz\CustomFields\Tests\Fixtures\EagerMember;
use KenPrz\CustomFields\Tests\Fixtures\Member;
use KenPrz\CustomFields\Tests\Fixtures\Property;
use KenPrz\CustomFields\Tests\Fixtures\PropertyReservation;
use KenPrz\CustomFields\Tests\Fixtures\Reservation;
use KenPrz\CustomFields\Tests\Fixtures\Tier;
use KenPrz\CustomFields\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class AttributeAccessTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->field('tier', 'select', ['settings' => ['options' => ['standard' => 'Standard', 'gold' => 'Gold']]]);
        $this->field('birthday', 'date');
        $this->field('checked_in_at', 'datetime');
        $this->field('credit_limit', 'decimal');
        $this->field('preferences', 'json');
        $this->field('nickname', 'text', ['validation_rules' => ['min:2']]);
        $this->field('secret_note', 'text');
        $this->field('not_listed', 'text');
    }

    protected function tearDown(): void
    {
        Model::preventAccessingMissingAttributes(false);
        Model::preventSilentlyDiscardingAttributes(false);

        parent::tearDown();
    }

    #[Test]
    public function custom_fields_read_and_write_like_attributes(): void
    {
        $member = Member::create(['name' => 'Ken']);

        $member->nickname = 'kp';
        $member->save();

        $this->assertSame('kp', $member->nickname);
        $this->assertSame('kp', Member::find($member->id)->nickname);
        $this->assertSame('Ken', $member->name);
    }

    #[Test]
    public function create_and_update_take_custom_fields_without_fillable(): void
    {
        $member = Member::create(['name' => 'Ken', 'nickname' => 'kp', 'tier' => 'gold']);

        $this->assertSame(['tier' => 'gold', 'nickname' => 'kp'], Member::find($member->id)->listCustomFields());

        $member->update(['nickname' => 'kenny', 'email' => 'ken@example.com']);

        $fresh = Member::find($member->id);
        $this->assertSame('kenny', $fresh->nickname);
        $this->assertSame('ken@example.com', $fresh->email);
        $this->assertSame(Tier::Gold, $fresh->tier);
    }

    #[Test]
    public function only_allow_listed_fields_act_as_attributes(): void
    {
        $member = Member::create(['name' => 'Ken']);
        $member->setCustomField('not_listed', 'hidden');

        $this->assertNull(Member::find($member->id)->not_listed);
        $this->assertSame('hidden', $member->getCustomField('not_listed'));
        $this->assertArrayNotHasKey('not_listed', Member::withCustomFields()->find($member->id)->toArray());
    }

    #[Test]
    public function an_empty_allow_list_exposes_every_field(): void
    {
        CustomFields::create(['model' => Reservation::class, 'key' => 'room', 'label' => 'Room', 'type' => 'text']);

        $reservation = Reservation::create(['code' => 'R1', 'room' => '101']);

        $this->assertSame('101', Reservation::find($reservation->id)->room);
    }

    #[Test]
    public function invalid_values_stop_the_save_before_anything_is_written(): void
    {
        try {
            Member::create(['name' => 'Ken', 'nickname' => 'k']);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('nickname', $e->errors());
        }

        $this->assertSame(0, Member::count());

        $member = Member::create(['name' => 'Ken']);
        try {
            $member->update(['name' => 'Changed', 'tier' => 'platinum']);
            $this->fail('Expected a validation error.');
        } catch (ValidationException) {
        }

        $this->assertSame('Ken', Member::find($member->id)->name);
    }

    #[Test]
    public function the_model_and_its_values_save_in_one_transaction(): void
    {
        CustomFieldValue::creating(function () {
            throw new RuntimeException('Value write failed');
        });

        try {
            Member::create(['name' => 'Ken', 'nickname' => 'kp']);
            $this->fail('Expected the write to fail.');
        } catch (RuntimeException) {
        }

        $this->assertSame(0, Member::count());
    }

    #[Test]
    public function save_quietly_still_writes_custom_fields(): void
    {
        $member = Member::create(['name' => 'Ken']);
        $member->nickname = 'quiet';
        $member->saveQuietly();

        $this->assertSame('quiet', Member::find($member->id)->nickname);
    }

    #[Test]
    public function saving_without_dirty_columns_still_writes_custom_fields(): void
    {
        $member = Member::create(['name' => 'Ken']);
        $member->fill(['nickname' => 'only custom']);

        $this->assertTrue($member->save());
        $this->assertSame('only custom', Member::find($member->id)->nickname);
    }

    #[Test]
    public function assigned_values_are_readable_before_saving(): void
    {
        $member = new Member(['name' => 'Ken', 'birthday' => '1990-05-01']);

        $this->assertInstanceOf(Carbon::class, $member->birthday);
        $this->assertSame('1990-05-01', $member->birthday->toDateString());
    }

    #[Test]
    public function dates_cast_to_carbon_by_default(): void
    {
        $member = Member::create(['name' => 'Ken', 'birthday' => '1990-05-01', 'checked_in_at' => '2026-11-09T22:00:00+08:00']);
        $fresh = Member::find($member->id);

        $this->assertSame('1990-05-01', $fresh->birthday->toDateString());
        $this->assertSame('2026-11-09 14:00:00', $fresh->checked_in_at->utc()->toDateTimeString());
        $this->assertSame('1990-05-01', $fresh->getCustomField('birthday'));
    }

    #[Test]
    public function custom_field_casts_use_eloquent_casting(): void
    {
        $member = Member::create([
            'name' => 'Ken',
            'tier' => Tier::Gold,
            'credit_limit' => '1500',
            'preferences' => collect(['theme' => 'dark']),
        ]);
        $fresh = Member::find($member->id);

        $this->assertSame(Tier::Gold, $fresh->tier);
        $this->assertSame('1500.00', $fresh->credit_limit);
        $this->assertInstanceOf(Collection::class, $fresh->preferences);
        $this->assertSame('dark', $fresh->preferences->get('theme'));
        $this->assertSame('gold', $fresh->getCustomField('tier'));
    }

    #[Test]
    public function to_array_includes_loaded_custom_fields_with_casts_applied(): void
    {
        $member = Member::create(['name' => 'Ken', 'tier' => 'gold', 'birthday' => '1990-05-01', 'secret_note' => 'shh']);

        $array = Member::withCustomFields()->find($member->id)->toArray();

        $this->assertSame([
            'id' => $member->id,
            'name' => 'Ken',
            'email' => null,
            'tier' => 'gold',
            'birthday' => '1990-05-01',
        ], $array);
    }

    #[Test]
    public function a_created_model_serializes_its_custom_fields_without_another_query(): void
    {
        DB::enableQueryLog();
        $member = Member::create(['name' => 'Ken', 'tier' => 'gold']);
        DB::flushQueryLog();

        $this->assertSame(['name' => 'Ken', 'id' => $member->id, 'tier' => 'gold'], $member->toArray());
        $this->assertSame([], DB::getQueryLog());
    }

    #[Test]
    public function an_updated_model_reloads_values_instead_of_guessing(): void
    {
        $member = Member::create(['name' => 'Ken', 'tier' => 'gold']);
        $member->setCustomField('nickname', 'kp');

        $fresh = Member::find($member->id);
        $fresh->update(['tier' => 'standard']);

        $this->assertFalse($fresh->relationLoaded('customFieldValues'));
        $this->assertSame(['tier' => 'standard', 'nickname' => 'kp'], $fresh->listCustomFields());
    }

    #[Test]
    public function to_array_does_not_load_values_on_its_own(): void
    {
        $member = Member::create(['name' => 'Ken', 'nickname' => 'kp']);
        $fresh = Member::find($member->id);

        DB::enableQueryLog();
        $array = $fresh->toArray();

        $this->assertSame([], DB::getQueryLog());
        $this->assertArrayNotHasKey('nickname', $array);
    }

    #[Test]
    public function columns_accessors_and_relations_win(): void
    {
        $member = Member::create(['name' => 'Ken']);

        $this->assertSame('KEN', $member->display_name);
        $this->assertSame('Ken', $member->name);
    }

    #[Test]
    public function field_keys_cannot_shadow_the_model(): void
    {
        foreach (['name', 'email', 'display_name', 'save', 'delete'] as $key) {
            try {
                $this->field($key, 'text');
                $this->fail("Expected [{$key}] to be rejected.");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('already used', $e->errors()['key'][0]);
            }
        }

        $this->expectException(ValidationException::class);
        CustomFields::forScope(Property::create(['name' => 'A']))->create([
            'model' => PropertyReservation::class, 'key' => 'property', 'label' => 'Property', 'type' => 'text',
        ]);
    }

    #[Test]
    public function the_allow_list_survives_mass_assignment_protection(): void
    {
        Model::preventSilentlyDiscardingAttributes();

        $member = Member::create(['name' => 'Ken', 'nickname' => 'kp']);

        $this->assertSame('kp', Member::find($member->id)->nickname);
    }

    #[Test]
    public function unknown_attributes_still_throw_when_eloquent_is_strict(): void
    {
        Model::preventAccessingMissingAttributes();
        $member = Member::find(Member::create(['name' => 'Ken'])->id);

        $this->assertNull($member->nickname);

        $this->expectException(MissingAttributeException::class);
        $member->definitely_not_a_thing; // @phpstan-ignore expr.resultUnused
    }

    #[Test]
    public function isset_works(): void
    {
        $member = Member::create(['name' => 'Ken', 'nickname' => 'kp']);

        $this->assertTrue(isset($member->nickname));
        $this->assertFalse(isset($member->tier));
    }

    #[Test]
    public function load_custom_fields_eager_loads_and_without_skips_it(): void
    {
        CustomFields::create(['model' => EagerMember::class, 'key' => 'nickname', 'label' => 'Nickname', 'type' => 'text']);

        foreach (range(1, 3) as $i) {
            EagerMember::create(['name' => "M{$i}", 'nickname' => "n{$i}"]);
        }
        CustomFields::flush();

        DB::enableQueryLog();
        $nicknames = EagerMember::all()->map(fn ($m) => $m->nickname)->all();
        $this->assertSame(['n1', 'n2', 'n3'], $nicknames);
        $this->assertCount(3, DB::getQueryLog(), 'members + values + field definitions');

        $this->assertFalse(EagerMember::withoutCustomFields()->first()->relationLoaded('customFieldValues'));
        $this->assertTrue(EagerMember::first()->relationLoaded('customFieldValues'));
    }

    #[Test]
    public function list_custom_fields_returns_stored_values(): void
    {
        $member = Member::create(['name' => 'Ken', 'tier' => 'gold', 'nickname' => 'kp']);

        $this->assertSame(['tier' => 'gold', 'nickname' => 'kp'], Member::find($member->id)->listCustomFields());
    }

    #[Test]
    public function custom_field_rules_depend_on_whether_the_model_exists(): void
    {
        CustomFields::update(CustomFields::findByKey(Member::class, 'nickname'), ['is_required' => true]);

        $new = (new Member)->customFieldRules();
        $existing = Member::create(['name' => 'Ken', 'nickname' => 'kp'])->customFieldRules();

        $this->assertSame(['required', 'string', 'max:255', 'min:2'], $new['nickname']);
        $this->assertSame(['sometimes', 'required', 'string', 'max:255', 'min:2'], $existing['nickname']);
        $this->assertArrayNotHasKey('not_listed', $new);
    }

    #[Test]
    public function custom_fields_follow_the_record_scope(): void
    {
        $hotelA = Property::create(['name' => 'A']);
        $hotelB = Property::create(['name' => 'B']);
        CustomFields::forScope($hotelA)->create(['model' => PropertyReservation::class, 'key' => 'vip_code', 'label' => 'VIP code', 'type' => 'text']);

        $a = PropertyReservation::create(['code' => 'A1', 'property_id' => $hotelA->id, 'vip_code' => 'V1']);
        $this->assertSame('V1', PropertyReservation::find($a->id)->vip_code);

        $this->expectException(ValidationException::class);
        PropertyReservation::create(['code' => 'B1', 'property_id' => $hotelB->id, 'vip_code' => 'V2']);
    }

    #[Test]
    public function fill_order_does_not_matter_for_scoped_fields(): void
    {
        $hotel = Property::create(['name' => 'A']);
        CustomFields::forScope($hotel)->create(['model' => PropertyReservation::class, 'key' => 'vip_code', 'label' => 'VIP code', 'type' => 'text']);

        // The custom field comes before the attribute that decides the scope.
        $reservation = PropertyReservation::create(['vip_code' => 'V1', 'code' => 'A1', 'property_id' => $hotel->id]);

        $this->assertSame('V1', PropertyReservation::find($reservation->id)->vip_code);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function field(string $key, string $type, array $extra = []): void
    {
        CustomFields::create(['model' => Member::class, 'key' => $key, 'label' => ucfirst($key), 'type' => $type, ...$extra]);
    }
}
