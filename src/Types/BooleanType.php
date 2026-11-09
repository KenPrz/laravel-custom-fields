<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Types;

use KenPrz\CustomFields\Models\CustomField;

/**
 * True or false. Accepts true, false, 1, 0, "1" and "0". Stored as a bool.
 */
class BooleanType extends Type
{
    public function rules(CustomField $field): array
    {
        return ['boolean'];
    }

    public function normalize(mixed $value, CustomField $field): mixed
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
