<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Types;

use KenPrz\CustomFields\Models\CustomField;

/**
 * Any number. Stored as an int or float. Numeric strings are converted.
 */
class NumberType extends Type
{
    public function rules(CustomField $field): array
    {
        return ['numeric'];
    }

    public function normalize(mixed $value, CustomField $field): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : (float) $value;
    }
}
