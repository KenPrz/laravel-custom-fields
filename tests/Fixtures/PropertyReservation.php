<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reservation that takes its custom field scope from its property.
 */
class PropertyReservation extends Reservation
{
    protected $table = 'reservations';

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function customFieldScope(): ?Model
    {
        return $this->property;
    }
}
