<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use KenPrz\CustomFields\Models\CustomField;
use KenPrz\CustomFields\Models\CustomFieldValue;

/**
 * Carries the deleted models as they were, without SerializesModels: a queued
 * listener couldn't fetch them back from the database.
 */
class CustomFieldValueDeleted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly CustomFieldValue $value,
        public readonly CustomField $field,
        public readonly Model $entity,
    ) {}
}
