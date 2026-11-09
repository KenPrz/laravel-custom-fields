<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Types;

/**
 * Multi-line text. Same as text with a 65535 character default limit.
 */
class TextareaType extends TextType
{
    public function defaultSettings(): array
    {
        return ['max_length' => 65535];
    }
}
