<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Types;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use KenPrz\CustomFields\Models\CustomField;

/**
 * A calendar date. Accepts anything Laravel's `date` rule accepts.
 * Stored as a "Y-m-d" string.
 */
class DateType extends Type
{
    public function rules(CustomField $field): array
    {
        return ['date'];
    }

    public function normalize(mixed $value, CustomField $field): mixed
    {
        return $this->parse($value)->format('Y-m-d');
    }

    protected function parse(mixed $value): Carbon
    {
        return $value instanceof DateTimeInterface ? Carbon::instance($value) : Carbon::parse($value);
    }
}
