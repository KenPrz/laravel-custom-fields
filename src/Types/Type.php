<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Types;

use KenPrz\CustomFields\Contracts\FieldType;
use KenPrz\CustomFields\Models\CustomField;

/**
 * Defaults shared by the built-in types. Extending it is optional.
 */
abstract class Type implements FieldType
{
    public function normalize(mixed $value, CustomField $field): mixed
    {
        return $value;
    }

    public function defaultSettings(): array
    {
        return [];
    }

    public function settingsRules(): array
    {
        return [];
    }
}
