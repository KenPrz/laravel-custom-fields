<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Types;

use KenPrz\CustomFields\Models\CustomField;

/**
 * A point in time. Converted to UTC and stored as an ISO 8601 string,
 * for example "2026-11-09T14:30:00Z". Values without a timezone are read
 * in the app timezone.
 */
class DatetimeType extends DateType
{
    public function normalize(mixed $value, CustomField $field): mixed
    {
        return $this->parse($value)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
