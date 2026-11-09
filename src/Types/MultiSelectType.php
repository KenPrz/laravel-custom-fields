<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Types;

use Closure;
use KenPrz\CustomFields\Models\CustomField;

/**
 * Any number of choices from a fixed list. Also registered as `checkbox`.
 *
 * Takes the same `options` setting as select. Stored as a list of option
 * keys (strings) with duplicates removed, for example ["wifi", "parking"].
 */
class MultiSelectType extends SelectType
{
    public function rules(CustomField $field): array
    {
        $keys = $this->keys($field);

        return [
            'list',
            function (string $attribute, mixed $value, Closure $fail) use ($keys): void {
                $invalid = array_filter((array) $value, fn (mixed $item) => ! is_scalar($item) || ! in_array((string) $item, $keys, true));

                if ($invalid !== []) {
                    $fail('The selected :attribute is invalid.');
                }
            },
        ];
    }

    public function normalize(mixed $value, CustomField $field): mixed
    {
        return array_values(array_unique(array_map(strval(...), (array) $value)));
    }
}
