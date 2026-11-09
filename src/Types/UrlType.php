<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Types;

use KenPrz\CustomFields\Models\CustomField;

/**
 * An absolute URL. Stored as a string.
 *
 * Settings: `max_length` (default 2048).
 */
class UrlType extends TextType
{
    public function rules(CustomField $field): array
    {
        return [...parent::rules($field), 'url'];
    }

    public function defaultSettings(): array
    {
        return ['max_length' => 2048];
    }
}
