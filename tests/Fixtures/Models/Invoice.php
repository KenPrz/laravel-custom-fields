<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use KenPrz\CustomFields\Traits\HasCustomFields;

/**
 * Lives in a Models namespace, to check short names resolve like `php artisan make:*` does.
 */
class Invoice extends Model
{
    use HasCustomFields;

    protected $table = 'reservations';
}
