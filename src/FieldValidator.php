<?php

declare(strict_types=1);

namespace KenPrz\CustomFields;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Contracts\Validation\Rule as LegacyRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\ValidationRuleParser;
use Illuminate\Validation\Validator;
use KenPrz\CustomFields\Models\CustomField;

/**
 * Validates custom field values with Laravel's validator.
 */
class FieldValidator
{
    public function __construct(
        private readonly FieldTypeRegistry $types,
        private readonly ValidatorFactory $validator,
        private readonly Container $container,
    ) {}

    /**
     * The full rule list for one field: required/nullable, the type's rules,
     * then the field's own validation_rules.
     *
     * @return array<int, mixed>
     */
    public function rulesFor(CustomField $field): array
    {
        return [
            $field->is_required ? 'required' : 'nullable',
            ...$this->types->get($field->type)->rules($field),
            ...$this->customRules($field),
        ];
    }

    /**
     * Rules for every active field, keyed by field key. With $partial, each
     * rule list starts with `sometimes` so missing keys are skipped.
     *
     * @param  Collection<string, CustomField>  $fields
     * @return array<string, array<int, mixed>>
     */
    public function rules(Collection $fields, bool $partial = false): array
    {
        return $fields
            ->filter(fn (CustomField $field) => $field->is_active)
            ->map(fn (CustomField $field) => $partial ? ['sometimes', ...$this->rulesFor($field)] : $this->rulesFor($field))
            ->all();
    }

    /**
     * Validate input against the given fields and return the values to store,
     * keyed by field key.
     *
     * Empty strings become null, like Laravel's ConvertEmptyStringsToNull
     * middleware. Unknown keys fail in strict mode and are dropped otherwise.
     * Inactive fields always fail.
     *
     * With $partial, only the keys in $input are checked. Without it, missing
     * required fields fail too.
     *
     * @param  Collection<string, CustomField>  $fields
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(Collection $fields, array $input, bool $partial = true, bool $strict = true): array
    {
        $errors = [];
        $data = [];
        $accepted = [];

        foreach ($input as $key => $value) {
            $field = $fields->get($key);

            if ($field === null) {
                if ($strict) {
                    $errors[$key][] = "The {$key} field does not exist.";
                }

                continue;
            }

            if (! $field->is_active) {
                $errors[$key][] = "The {$field->label} field is not accepting values.";

                continue;
            }

            $data[$key] = $value === '' ? null : $value;
            $accepted[$key] = $field;
        }

        $rules = $partial
            ? array_intersect_key($this->rules($fields), $data)
            : $this->rules($fields);

        $validator = $this->validator->make(
            $data,
            $rules,
            [],
            $fields->map(fn (CustomField $field) => $field->label)->all(),
        );

        if ($errors !== [] || $validator->fails()) {
            throw ValidationException::withMessages(array_merge_recursive($validator->errors()->toArray(), $errors));
        }

        $values = [];

        foreach ($data as $key => $value) {
            $field = $accepted[$key];
            $values[$key] = $value === null ? null : $this->types->get($field->type)->normalize($value, $field);
        }

        return $values;
    }

    /**
     * Names of rules in the list that Laravel can't run: not a built-in rule,
     * not a Validator::extend() rule, not a rule class.
     *
     * @param  array<int, mixed>  $rules
     * @return list<string>
     */
    public function unknownRules(array $rules): array
    {
        /** @var Validator $probe */
        $probe = $this->validator->make([], []);
        $unknown = [];

        foreach ($rules as $rule) {
            if (! is_string($rule) || $this->isRuleClass($rule)) {
                continue;
            }

            [$name] = ValidationRuleParser::parse($rule);

            if (! method_exists($probe, 'validate'.$name) && ! isset($probe->extensions[Str::snake($name)])) {
                $unknown[] = $rule;
            }
        }

        return $unknown;
    }

    /**
     * @return array<int, mixed>
     */
    private function customRules(CustomField $field): array
    {
        return array_map(
            fn (mixed $rule) => is_string($rule) && $this->isRuleClass($rule) ? $this->container->make($rule) : $rule,
            $field->validation_rules,
        );
    }

    private function isRuleClass(string $rule): bool
    {
        return class_exists($rule)
            && (is_subclass_of($rule, ValidationRule::class) || is_subclass_of($rule, LegacyRule::class));
    }
}
