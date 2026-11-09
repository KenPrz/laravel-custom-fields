<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use KenPrz\CustomFields\Traits\HasCustomFields;

/**
 * Overrides save() itself, so the trait's save() never runs and custom
 * fields have to go through the model events instead.
 */
class SelfSavingMember extends Model
{
    use HasCustomFields;

    public $timestamps = false;

    protected $table = 'members';

    protected $guarded = [];

    /** @var list<string> */
    protected $customFields = ['nickname'];

    public int $saves = 0;

    /**
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        $this->saves++;

        return parent::save($options);
    }
}
