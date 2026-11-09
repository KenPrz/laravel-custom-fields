<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use KenPrz\CustomFields\Models\CustomField;

/**
 * Carries the deleted models as they were, without SerializesModels: a queued
 * listener couldn't fetch them back from the database.
 */
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
