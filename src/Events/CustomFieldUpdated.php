<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Queue\SerializesModels;
use KenPrz\CustomFields\Models\CustomField;

class CustomFieldUpdated implements ShouldDispatchAfterCommit
{
    use SerializesModels;

    public function __construct(public readonly CustomField $field) {}
}
