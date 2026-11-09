<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A scope with a string primary key.
 */
class Team extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'slug';

    protected $keyType = 'string';

    protected $guarded = [];
}
