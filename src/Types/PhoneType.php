<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Types;

use KenPrz\CustomFields\Models\CustomField;

/**
 * A phone number. Stored as a trimmed string.
 *
 * Only checks the characters: digits, spaces, ( ) . - and a leading +.
 * It doesn't know real numbering plans. For that, register your own type
 * backed by a library such as libphonenumber.
 */
class PhoneType extends Type
{
    public function rules(CustomField $field): array
    {
        return ['string', 'max:32', 'regex:/^\s*\+?[0-9 ().-]*[0-9][0-9 ().-]*$/'];
    }

    public function normalize(mixed $value, CustomField $field): mixed
    {
        return trim((string) $value);
    }
}
