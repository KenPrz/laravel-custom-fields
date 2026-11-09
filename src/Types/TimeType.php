<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Types;

use KenPrz\CustomFields\Models\CustomField;

/**
 * A time of day. Accepts "H:i" or "H:i:s". Stored as "H:i:s".
 */
class TimeType extends Type
{
    public function rules(CustomField $field): array
    {
        return ['date_format:H:i,H:i:s'];
    }

    public function normalize(mixed $value, CustomField $field): mixed
    {
        // Validation guarantees a string in H:i or H:i:s.
        return strlen($value) === 5 ? $value.':00' : $value;
    }
}
