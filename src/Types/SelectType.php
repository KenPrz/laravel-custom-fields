<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Types;

use Illuminate\Validation\Rule;
use KenPrz\CustomFields\Models\CustomField;

/**
 * One choice from a fixed list. Also registered as `radio`.
 *
 * Settings: `options`, a map of stored value => label, for example
 * ['vip' => 'VIP', 'walk_in' => 'Walk-in']. The stored value is the key,
 * always as a string.
 */
class SelectType extends Type
{
    public function rules(CustomField $field): array
    {
        return [Rule::in($this->keys($field))];
    }

    public function normalize(mixed $value, CustomField $field): mixed
    {
        return (string) $value;
    }

    public function defaultSettings(): array
    {
        return ['options' => []];
    }

    public function settingsRules(): array
    {
        return ['options' => ['required', 'array', 'min:1']];
    }

    /**
     * @return list<string>
     */
    protected function keys(CustomField $field): array
    {
        return array_map(strval(...), array_keys($field->settings['options']));
    }
}
