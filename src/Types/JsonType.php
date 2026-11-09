<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Types;

use Closure;
use KenPrz\CustomFields\Models\CustomField;

/**
 * Free-form structured data.
 *
 * Arrays, numbers and booleans are stored as given. Strings are treated as
 * JSON text and decoded, so '{"a":1}' is stored as ['a' => 1] and a string
 * that isn't valid JSON is rejected. To store a plain string, pass '"text"'
 * or use the text type.
 */
class JsonType extends Type
{
    public function rules(CustomField $field): array
    {
        return [
            function (string $attribute, mixed $value, Closure $fail): void {
                $valid = is_string($value)
                    ? json_validate($value)
                    : is_array($value) || is_int($value) || is_float($value) || is_bool($value);

                if (! $valid) {
                    $fail('The :attribute field must be valid JSON.');
                }
            },
        ];
    }

    public function normalize(mixed $value, CustomField $field): mixed
    {
        return is_string($value) ? json_decode($value, true) : $value;
    }
}
