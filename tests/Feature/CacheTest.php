<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use KenPrz\CustomFields\Facades\CustomFields;
use KenPrz\CustomFields\Models\CustomField;
use KenPrz\CustomFields\Tests\Fixtures\Reservation;
use KenPrz\CustomFields\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CacheTest extends TestCase
{
    use DatabaseMigrations;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('custom-fields.cache.enabled', true);
    }

    #[Test]
    public function definitions_are_served_from_the_cache_in_a_new_request(): void
    {
        $this->field('notes');
        CustomFields::fieldsFor(Reservation::class);

        $this->newRequest();
        DB::enableQueryLog();
        $fields = CustomFields::fieldsFor(Reservation::class);

        $this->assertSame([], DB::getQueryLog());
        $this->assertSame(['max_length' => 255], $fields['notes']->settings);
        $this->assertTrue($fields['notes']->exists);
    }

    #[Test]
    public function saving_through_the_manager_clears_it(): void
    {
        $field = $this->field('notes');
        CustomFields::fieldsFor(Reservation::class);

        CustomFields::update($field, ['label' => 'Changed']);
        $this->newRequest();

        $this->assertSame('Changed', CustomFields::findByKey(Reservation::class, 'notes')->label);
    }

    #[Test]
    public function saving_through_eloquent_clears_it(): void
    {
        $this->field('notes');
        CustomFields::fieldsFor(Reservation::class);

        CustomField::first()->update(['label' => 'Changed']);
        $this->newRequest();

        $this->assertSame('Changed', CustomFields::findByKey(Reservation::class, 'notes')->label);
    }

    #[Test]
    public function deleting_and_restoring_clear_it(): void
    {
        $field = $this->field('notes');
        CustomFields::fieldsFor(Reservation::class);

        $field->delete();
        $this->newRequest();
        $this->assertNull(CustomFields::findByKey(Reservation::class, 'notes'));

        $field->restore();
        $this->newRequest();
        $this->assertNotNull(CustomFields::findByKey(Reservation::class, 'notes'));
    }

    #[Test]
    public function values_are_never_cached(): void
    {
        $this->field('notes');
        $reservation = Reservation::create(['code' => 'R1'])->setCustomField('notes', 'one');

        DB::table('custom_field_values')->update(['value' => json_encode('two')]);
        $this->newRequest();

        $this->assertSame('two', Reservation::find($reservation->id)->getCustomField('notes'));
    }

    #[Test]
    public function writes_outside_eloquent_need_a_manual_flush(): void
    {
        $this->field('notes');
        CustomFields::fieldsFor(Reservation::class);

        DB::table('custom_fields')->update(['label' => 'Raw']);
        $this->newRequest();
        $this->assertSame('Notes', CustomFields::findByKey(Reservation::class, 'notes')->label);

        CustomFields::flush();
        $this->assertSame('Raw', CustomFields::findByKey(Reservation::class, 'notes')->label);
    }

    private function newRequest(): void
    {
        $this->app->forgetScopedInstances();
        CustomFields::clearResolvedInstances();
    }

    private function field(string $key): CustomField
    {
        return CustomFields::create(['model' => Reservation::class, 'key' => $key, 'label' => ucfirst($key), 'type' => 'text']);
    }
}
