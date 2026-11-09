<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class Property extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
