<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Types;

use KenPrz\CustomFields\Models\CustomField;

/**
 * A single line of text. Stored as a string.
 *
 * Settings: `max_length` (default 255).
 */
class TextType extends Type
{
    public function rules(CustomField $field): array
    {
        return ['string', 'max:'.$field->settings['max_length']];
    }

    public function defaultSettings(): array
    {
        return ['max_length' => 255];
    }

    public function settingsRules(): array
    {
        return ['max_length' => ['integer', 'min:1']];
    }
}
