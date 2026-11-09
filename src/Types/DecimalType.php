<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Types;

use KenPrz\CustomFields\Models\CustomField;

/**
 * A fixed-point number such as a price. Stored as a string padded to the
 * configured number of places ("12.50"), so no precision is lost to floats.
 *
 * Settings: `places` (default 2). Inputs with more places are rejected.
 */
class DecimalType extends NumberType
{
    public function rules(CustomField $field): array
    {
        // decimal checks numeric itself.
        return ['decimal:0,'.$field->settings['places']];
    }

    public function normalize(mixed $value, CustomField $field): mixed
    {
        $places = (int) $field->settings['places'];
        $string = ltrim((string) $value, '+');
        $negative = str_starts_with($string, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($string, '-'), 2), 2, '');

        $whole = ltrim($whole, '0') ?: '0';
        $fraction = str_pad($fraction, $places, '0');
        $result = $places > 0 ? $whole.'.'.$fraction : $whole;

        return $negative && trim($result, '0.') !== '' ? '-'.$result : $result;
    }

    public function defaultSettings(): array
    {
        return ['places' => 2];
    }

    public function settingsRules(): array
    {
        return ['places' => ['integer', 'min:0', 'max:30']];
    }
}
