<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One stored value for one field on one entity.
 *
 * The value is JSON encoded, so strings, numbers, booleans, arrays and null
 * keep their PHP type on the way back out.
 *
 * @property int $id
 * @property int $custom_field_id
 * @property string $entity_type
 * @property string|int $entity_id
 * @property mixed $value
 */
class CustomFieldValue extends Model
{
    protected $fillable = ['value'];

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }

    public function getTable(): string
    {
        return config('custom-fields.tables.values');
    }

    /**
     * @return BelongsTo<CustomField, $this>
     */
    public function field(): BelongsTo
    {
        /** @var class-string<CustomField> $model */
        $model = config('custom-fields.models.field');

        // Values outlive a soft-deleted field, so the relation still resolves it.
        return $this->belongsTo($model, 'custom_field_id')->withTrashed();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function entity(): MorphTo
    {
        return $this->morphTo();
    }
}
