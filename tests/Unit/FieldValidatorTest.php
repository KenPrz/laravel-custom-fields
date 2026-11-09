<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Tests\Unit;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use KenPrz\CustomFields\FieldValidator;
use KenPrz\CustomFields\Models\CustomField;
use KenPrz\CustomFields\Tests\Fixtures\Uppercase;
use KenPrz\CustomFields\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class FieldValidatorTest extends TestCase
{
    #[Test]
    public function it_returns_normalized_values(): void
    {
        $values = $this->validate(['age' => '42', 'tier' => 'vip']);

        $this->assertSame(['age' => 42, 'tier' => 'vip'], $values);
    }

    #[Test]
    public function it_builds_rules_in_order(): void
    {
        $rules = app(FieldValidator::class)->rulesFor($this->fields()['age']);

        $this->assertSame(['required', 'integer', 'min:18', 'max:120'], $rules);
    }

    #[Test]
    public function it_uses_labels_in_messages(): void
    {
        $errors = $this->errors(['age' => 5]);

        $this->assertSame(['The Guest age field must be at least 18.'], $errors['age']);
    }

    #[Test]
    public function partial_validation_skips_missing_required_fields(): void
    {
        $this->assertSame(['tier' => 'corp'], $this->validate(['tier' => 'corp']));
        $this->assertArrayHasKey('age', $this->errors(['tier' => 'corp'], partial: false));
    }

    #[Test]
    public function a_present_required_field_cannot_be_null_or_empty(): void
    {
        $this->assertArrayHasKey('age', $this->errors(['age' => null]));
        $this->assertArrayHasKey('age', $this->errors(['age' => '']));
    }

    #[Test]
    public function optional_fields_accept_null_and_empty_strings_become_null(): void
    {
        $this->assertSame(['tier' => null, 'notes' => null], $this->validate(['tier' => null, 'notes' => '']));
    }

    #[Test]
    public function select_values_must_be_options(): void
    {
        $this->assertArrayHasKey('tier', $this->errors(['tier' => 'gold']));
    }

    #[Test]
    public function unknown_keys_fail_in_strict_mode_and_are_dropped_otherwise(): void
    {
        $this->assertSame(['The nope field does not exist.'], $this->errors(['nope' => 1])['nope']);
        $this->assertSame(['tier' => 'vip'], $this->validate(['nope' => 1, 'tier' => 'vip'], strict: false));
    }

    #[Test]
    public function inactive_fields_reject_values(): void
    {
        $errors = $this->errors(['legacy' => 'x']);

        $this->assertSame(['The Legacy field is not accepting values.'], $errors['legacy']);
    }

    #[Test]
    public function inactive_required_fields_are_not_demanded(): void
    {
        $fields = $this->fields();
        $fields['legacy']->is_required = true;

        $values = app(FieldValidator::class)->validate($fields, ['age' => 20], partial: false);

        $this->assertSame(['age' => 20], $values);
    }

    #[Test]
    public function rule_classes_are_resolved(): void
    {
        $this->assertArrayHasKey('notes', $this->errors(['notes' => 'lower']));
        $this->assertSame(['notes' => 'UPPER'], $this->validate(['notes' => 'UPPER']));
    }

    #[Test]
    public function it_reports_every_error_at_once(): void
    {
        $errors = $this->errors(['age' => 'x', 'tier' => 'gold', 'nope' => 1, 'legacy' => 1]);

        $this->assertEqualsCanonicalizing(['age', 'tier', 'nope', 'legacy'], array_keys($errors));
    }

    #[Test]
    public function partial_rules_start_with_sometimes(): void
    {
        $rules = app(FieldValidator::class)->rules($this->fields(), partial: true);

        $this->assertSame('sometimes', $rules['age'][0]);
        $this->assertArrayNotHasKey('legacy', $rules);
    }

    #[Test]
    public function it_flags_unknown_rules(): void
    {
        $unknown = app(FieldValidator::class)->unknownRules(['required', 'min:3', 'regex:/a|b/', 'nonsense', Uppercase::class, 'App\\Missing']);

        $this->assertSame(['nonsense', 'App\\Missing'], $unknown);
    }

    /**
     * @return Collection<string, CustomField>
     */
    private function fields(): Collection
    {
        return collect([
            'age' => new CustomField([
                'key' => 'age', 'label' => 'Guest age', 'type' => 'integer', 'is_required' => true,
                'validation_rules' => ['min:18', 'max:120'],
            ]),
            'tier' => new CustomField([
                'key' => 'tier', 'label' => 'Tier', 'type' => 'select',
                'settings' => ['options' => ['vip' => 'VIP', 'corp' => 'Corporate']],
            ]),
            'notes' => new CustomField([
                'key' => 'notes', 'label' => 'Notes', 'type' => 'text',
                'settings' => ['max_length' => 255], 'validation_rules' => [Uppercase::class],
            ]),
            'legacy' => new CustomField([
                'key' => 'legacy', 'label' => 'Legacy', 'type' => 'text', 'is_active' => false,
                'settings' => ['max_length' => 255],
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validate(array $input, bool $partial = true, bool $strict = true): array
    {
        return app(FieldValidator::class)->validate($this->fields(), $input, $partial, $strict);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, list<string>>
     */
    private function errors(array $input, bool $partial = true): array
    {
        try {
            $this->validate($input, $partial);
        } catch (ValidationException $e) {
            return $e->errors();
        }

        $this->fail('Expected validation to fail.');
    }
}
