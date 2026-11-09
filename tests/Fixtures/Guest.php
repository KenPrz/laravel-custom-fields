<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use KenPrz\CustomFields\Traits\HasCustomFields;

class Guest extends Model
{
    use HasCustomFields;
    use SoftDeletes;

    public $timestamps = false;

    protected $guarded = [];
}
