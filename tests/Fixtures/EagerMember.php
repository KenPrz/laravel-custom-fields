<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Tests\Fixtures;

class EagerMember extends Member
{
    protected $table = 'members';

    protected bool $loadCustomFields = true;
}
