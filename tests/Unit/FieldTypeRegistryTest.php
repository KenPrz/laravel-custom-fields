<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Tests\Unit;

use InvalidArgumentException;
use KenPrz\CustomFields\Exceptions\CustomFieldException;
use KenPrz\CustomFields\FieldTypeRegistry;
use KenPrz\CustomFields\Tests\TestCase;
use KenPrz\CustomFields\Types\SelectType;
use KenPrz\CustomFields\Types\TextType;
use PHPUnit\Framework\Attributes\Test;
use stdClass;

class FieldTypeRegistryTest extends TestCase
{
    #[Test]
    public function it_registers_the_built_in_types(): void
    {
        $this->assertSame([
            'text', 'textarea', 'number', 'integer', 'decimal', 'boolean', 'date', 'datetime', 'time',
            'select', 'radio', 'multiselect', 'checkbox', 'email', 'url', 'phone', 'json',
        ], app(FieldTypeRegistry::class)->names());
    }

    #[Test]
    public function it_resolves_a_type_once(): void
    {
        $registry = app(FieldTypeRegistry::class);

        $this->assertInstanceOf(SelectType::class, $registry->get('radio'));
        $this->assertSame($registry->get('radio'), $registry->get('radio'));
    }

    #[Test]
    public function it_accepts_classes_and_instances(): void
    {
        $registry = app(FieldTypeRegistry::class);
        $instance = new TextType;

        $registry->register('code', TextType::class);
        $registry->register('note', $instance);

        $this->assertInstanceOf(TextType::class, $registry->get('code'));
        $this->assertSame($instance, $registry->get('note'));
    }

    #[Test]
    public function registering_again_replaces_the_type(): void
    {
        $registry = app(FieldTypeRegistry::class);
        $registry->get('text');

        $replacement = new TextType;
        $registry->register('text', $replacement);

        $this->assertSame($replacement, $registry->get('text'));
    }

    #[Test]
    public function it_rejects_classes_that_are_not_field_types(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('[stdClass] must implement KenPrz\\CustomFields\\Contracts\\FieldType.');

        app(FieldTypeRegistry::class)->register('thing', stdClass::class);
    }

    #[Test]
    public function it_rejects_bad_names(): void
    {
        foreach (['Bad Name', 'ok!', '1abc', 'a'.str_repeat('b', 64)] as $name) {
            try {
                app(FieldTypeRegistry::class)->register($name, TextType::class);
                $this->fail("Expected [{$name}] to be rejected.");
            } catch (InvalidArgumentException $e) {
                $this->assertSame("Invalid custom field type name [{$name}].", $e->getMessage());
            }
        }

        app(FieldTypeRegistry::class)->register('a'.str_repeat('b', 63), TextType::class);
        $this->assertTrue(app(FieldTypeRegistry::class)->has('a'.str_repeat('b', 63)));
    }

    #[Test]
    public function it_throws_for_unknown_types(): void
    {
        $this->expectException(CustomFieldException::class);

        app(FieldTypeRegistry::class)->get('nope');
    }
}
