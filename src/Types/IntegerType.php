<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Types;

use KenPrz\CustomFields\Models\CustomField;

/**
 * A whole number. Stored as an int.
 */
class IntegerType extends NumberType
{
    public function rules(CustomField $field): array
    {
        return ['integer'];
    }

    public function normalize(mixed $value, CustomField $field): mixed
    {
        return (int) $value;
    }
}
