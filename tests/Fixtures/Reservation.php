<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use KenPrz\CustomFields\Traits\HasCustomFields;

class Reservation extends Model
{
    use HasCustomFields;

    public $timestamps = false;

    protected $guarded = [];
}
