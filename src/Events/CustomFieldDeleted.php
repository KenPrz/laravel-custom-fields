<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use KenPrz\CustomFields\Models\CustomField;

class CustomFieldDeleted implements ShouldDispatchAfterCommit
{
    /**
     * @param  bool  $forced  true when the field and its values were removed for good
     */
    public function __construct(
        public readonly CustomField $field,
        public readonly bool $forced,
    ) {}
}
