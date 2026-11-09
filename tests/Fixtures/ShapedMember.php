<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Tests\Fixtures;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use KenPrz\CustomFields\Traits\HasCustomFields;
use LogicException;

/**
 * Uses $hidden, $appends and a set-only mutator, to check that casting and
 * serializing custom fields doesn't trip over the model's own shaping.
 *
 * @property string|null $name
 */
class ShapedMember extends Model
{
    use HasCustomFields;

    public $timestamps = false;

    protected $table = 'members';

    protected $guarded = [];

    protected $hidden = ['email', 'birthday'];

    protected $appends = ['shout'];

    /** @var array<string, string> a cast with no column behind it */
    protected $casts = ['vip_flag' => 'boolean'];

    /** @var list<string> */
    protected $customFields = ['birthday', 'arrival', 'mood', 'plain_note'];

    /** @var array<string, string> */
    protected $customFieldCasts = ['mood' => Mood::class];

    public ?string $mutated = null;

    /**
     * Throws without a name, so a copy of the model that computed appends would fail loudly.
     *
     * @return Attribute<non-falsy-string&uppercase-string, never>
     */
    protected function shout(): Attribute
    {
        return Attribute::get(fn () => $this->name === null ? throw new LogicException('shout() needs a name') : strtoupper($this->name).'!');
    }

    public function setPlainNoteAttribute(string $value): void
    {
        $this->mutated = $value;
    }
}
