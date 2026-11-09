<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Tests\Fixtures;

use Illuminate\Contracts\Validation\Rule;

/**
 * A rule written against Laravel's older Rule contract.
 */
class LegacyUppercase implements Rule
{
    public function passes($attribute, $value): bool
    {
        return strtoupper((string) $value) === $value;
    }

    public function message(): string
    {
        return 'The :attribute must be uppercase (legacy).';
    }
}
