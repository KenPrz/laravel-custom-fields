<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Types;

use KenPrz\CustomFields\Models\CustomField;

/**
 * An email address. Stored as a string.
 */
class EmailType extends TextType
{
    public function rules(CustomField $field): array
    {
        return [...parent::rules($field), 'email'];
    }
}
