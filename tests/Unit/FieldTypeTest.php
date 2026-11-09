<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Tests\Unit;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use KenPrz\CustomFields\FieldTypeRegistry;
use KenPrz\CustomFields\Models\CustomField;
use KenPrz\CustomFields\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use stdClass;

class FieldTypeTest extends TestCase
{
    private const INVALID = '__invalid__';

    /**
     * [type, settings, input, stored value or INVALID]
     *
     * @return array<string, array{string, array<string, mixed>, mixed, mixed}>
     */
    public static function cases(): array
    {
        $options = ['options' => ['vip' => 'VIP', 'walk_in' => 'Walk-in', 1 => 'One']];

        return [
            'text' => ['text', [], 'hello', 'hello'],
            'text numeric string' => ['text', [], '123', '123'],
            'text too long' => ['text', ['max_length' => 3], 'abcd', self::INVALID],
            'text array' => ['text', [], ['a'], self::INVALID],
            'text int' => ['text', [], 12, self::INVALID],
            'textarea long' => ['textarea', [], str_repeat('a', 1000), str_repeat('a', 1000)],
            'textarea at the 65535 default limit' => ['textarea', [], str_repeat('a', 65535), str_repeat('a', 65535)],
            'textarea over the 65535 default limit' => ['textarea', [], str_repeat('a', 65536), self::INVALID],

            'number int' => ['number', [], 5, 5],
            'number float' => ['number', [], 2.5, 2.5],
            'number string int' => ['number', [], '42', 42],
            'number string float' => ['number', [], '1.50', 1.5],
            'number text' => ['number', [], 'abc', self::INVALID],

            'integer' => ['integer', [], 7, 7],
            'integer string' => ['integer', [], '-7', -7],
            'integer float' => ['integer', [], 1.5, self::INVALID],
            'integer text' => ['integer', [], 'seven', self::INVALID],

            'decimal pads' => ['decimal', [], '12.5', '12.50'],
            'decimal int' => ['decimal', [], 12, '12.00'],
            'decimal float' => ['decimal', [], 0.1, '0.10'],
            'decimal negative' => ['decimal', [], '-3.1', '-3.10'],
            'decimal negative zero' => ['decimal', [], '-0.00', '0.00'],
            'decimal leading dot' => ['decimal', [], '.5', '0.50'],
            'decimal plus sign' => ['decimal', [], '+4', '4.00'],
            'decimal leading zeros' => ['decimal', [], '007.1', '7.10'],
            'decimal no places' => ['decimal', ['places' => 0], '9', '9'],
            'decimal places given as a string' => ['decimal', ['places' => '3'], '1.5', '1.500'],
            'decimal big' => ['decimal', ['places' => 4], '123456789012345678901.1234', '123456789012345678901.1234'],
            'decimal too many places' => ['decimal', [], '1.234', self::INVALID],
            'decimal exponent' => ['decimal', [], '1e3', self::INVALID],

            'boolean true' => ['boolean', [], true, true],
            'boolean false' => ['boolean', [], false, false],
            'boolean 1' => ['boolean', [], 1, true],
            'boolean "0"' => ['boolean', [], '0', false],
            'boolean "yes"' => ['boolean', [], 'yes', self::INVALID],

            'date' => ['date', [], '2026-11-09', '2026-11-09'],
            'date with time' => ['date', [], '2026-11-09 23:59:00', '2026-11-09'],
            'date object' => ['date', [], Carbon::parse('2026-01-02'), '2026-01-02'],
            'date invalid' => ['date', [], '2026-02-30', self::INVALID],
            'date text' => ['date', [], 'soon', self::INVALID],

            'datetime utc' => ['datetime', [], '2026-11-09 14:30:00', '2026-11-09T14:30:00Z'],
            'datetime offset' => ['datetime', [], '2026-11-09T14:30:00+08:00', '2026-11-09T06:30:00Z'],
            'datetime text' => ['datetime', [], 'later', self::INVALID],

            'time short' => ['time', [], '09:15', '09:15:00'],
            'time long' => ['time', [], '23:59:59', '23:59:59'],
            'time invalid' => ['time', [], '25:00', self::INVALID],
            'time date' => ['time', [], '2026-11-09', self::INVALID],

            'select' => ['select', $options, 'vip', 'vip'],
            'select int key' => ['select', $options, 1, '1'],
            'select unknown' => ['select', $options, 'gold', self::INVALID],
            'select array' => ['select', $options, ['vip'], self::INVALID],
            'radio' => ['radio', $options, 'walk_in', 'walk_in'],

            'multiselect' => ['multiselect', $options, ['vip', 'walk_in'], ['vip', 'walk_in']],
            'multiselect dedupes' => ['multiselect', $options, ['vip', 'vip', 1], ['vip', '1']],
            'multiselect empty' => ['multiselect', $options, [], []],
            'multiselect unknown' => ['multiselect', $options, ['vip', 'gold'], self::INVALID],
            'multiselect nested' => ['multiselect', $options, [['vip']], self::INVALID],
            'multiselect map' => ['multiselect', $options, ['a' => 'vip'], self::INVALID],
            'multiselect string' => ['multiselect', $options, 'vip', self::INVALID],
            'checkbox' => ['checkbox', $options, ['vip'], ['vip']],

            'email' => ['email', [], 'ken@example.com', 'ken@example.com'],
            'email invalid' => ['email', [], 'not-an-email', self::INVALID],
            'email at the length limit' => ['email', [], str_repeat('a', 243).'@example.com', str_repeat('a', 243).'@example.com'],
            'email over the length limit' => ['email', [], str_repeat('a', 244).'@example.com', self::INVALID],
            'email not a string' => ['email', [], ['ken@example.com'], self::INVALID],

            'url' => ['url', [], 'https://example.com/a?b=c', 'https://example.com/a?b=c'],
            'url invalid' => ['url', [], 'example', self::INVALID],
            'url at the 2048 default limit' => ['url', [], 'https://example.com/'.str_repeat('a', 2028), 'https://example.com/'.str_repeat('a', 2028)],
            'url over the 2048 default limit' => ['url', [], 'https://example.com/'.str_repeat('a', 2029), self::INVALID],
            'url not a string' => ['url', [], ['https://example.com'], self::INVALID],

            'phone' => ['phone', [], ' +63 (2) 123-4567 ', '+63 (2) 123-4567'],
            'phone letters' => ['phone', [], '555-CALL', self::INVALID],
            'phone no digits' => ['phone', [], '()-', self::INVALID],
            'phone as an integer' => ['phone', [], 5551234, self::INVALID],

            'json array' => ['json', [], ['a' => [1, 2]], ['a' => [1, 2]]],
            'json string' => ['json', [], '{"a":true}', ['a' => true]],
            'json quoted string' => ['json', [], '"hi"', 'hi'],
            'json number' => ['json', [], 3, 3],
            'json float' => ['json', [], 1.5, 1.5],
            'json boolean' => ['json', [], true, true],
            'json invalid string' => ['json', [], '{a:1}', self::INVALID],
            'json plain string' => ['json', [], 'hello', self::INVALID],
            'json object' => ['json', [], new stdClass, self::INVALID],
        ];
    }

    #[Test]
    #[DataProvider('cases')]
    public function it_validates_and_normalizes(string $type, array $settings, mixed $input, mixed $expected): void
    {
        $fieldType = app(FieldTypeRegistry::class)->get($type);
        $field = new CustomField(['type' => $type, 'settings' => [...$fieldType->defaultSettings(), ...$settings]]);

        $passes = Validator::make(['value' => $input], ['value' => $fieldType->rules($field)])->passes();

        if ($expected === self::INVALID) {
            $this->assertFalse($passes, 'Expected the value to be rejected.');

            return;
        }

        $this->assertTrue($passes, 'Expected the value to be accepted.');
        $this->assertSame($expected, $fieldType->normalize($input, $field));
    }

    #[Test]
    public function required_settings_cannot_be_null(): void
    {
        $registry = app(FieldTypeRegistry::class);

        foreach (['text' => 'max_length', 'decimal' => 'places', 'select' => 'options'] as $type => $setting) {
            $passes = Validator::make([$setting => null], $registry->get($type)->settingsRules())->passes();

            $this->assertFalse($passes, "{$type}.{$setting} accepted null");
        }
    }

    #[Test]
    public function a_multiselect_reports_one_error_however_many_items_are_wrong(): void
    {
        $type = app(FieldTypeRegistry::class)->get('multiselect');
        $field = new CustomField(['type' => 'multiselect', 'settings' => ['options' => ['a' => 'A']]]);

        $errors = Validator::make(['value' => ['x', 'y', 'z']], ['value' => $type->rules($field)])->errors()->get('value');

        $this->assertSame(['The selected value is invalid.'], $errors);
    }

    #[Test]
    public function every_built_in_type_has_valid_default_settings(): void
    {
        $registry = app(FieldTypeRegistry::class);

        foreach ($registry->names() as $name) {
            $type = $registry->get($name);
            $rules = $type->settingsRules();
            unset($rules['options']); // select types need options supplied by the user

            $this->assertTrue(Validator::make($type->defaultSettings(), $rules)->passes(), $name);
        }
    }
}
