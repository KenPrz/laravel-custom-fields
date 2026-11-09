<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Tests\Fixtures;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class Uppercase implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (strtoupper((string) $value) !== $value) {
            $fail('The :attribute must be uppercase.');
        }
    }
}
