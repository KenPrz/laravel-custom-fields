<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;
use KenPrz\CustomFields\Models\CustomField;
use KenPrz\CustomFields\Models\CustomFieldValue;

class CustomFieldValueCreated implements ShouldDispatchAfterCommit
{
    use SerializesModels;

    public function __construct(
        public readonly CustomFieldValue $value,
        public readonly CustomField $field,
        public readonly Model $entity,
    ) {}
}
