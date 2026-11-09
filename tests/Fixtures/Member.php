<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Tests\Fixtures;

use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use KenPrz\CustomFields\Traits\HasCustomFields;

/**
 * @property int $id
 * @property string $name
 * @property string|null $email
 *
 * A model configured the way the README shows: a normal $fillable for its
 * columns, plus an allow-list and casts for custom fields.
 */
class Member extends Model
{
    use HasCustomFields;

    public $timestamps = false;

    protected $fillable = ['name', 'email'];

    protected $hidden = ['secret_note'];

    /** @var list<string> */
    protected $customFields = ['tier', 'birthday', 'credit_limit', 'preferences', 'nickname', 'secret_note', 'checked_in_at'];

    /** @var array<string, string> */
    protected $customFieldCasts = [
        'tier' => Tier::class,
        'credit_limit' => 'decimal:2',
        'preferences' => AsCollection::class,
    ];

    /**
     * @return Attribute<uppercase-string, never>
     */
    protected function displayName(): Attribute
    {
        return Attribute::get(fn () => strtoupper($this->name));
    }
}
