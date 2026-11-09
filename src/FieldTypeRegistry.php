<?php

declare(strict_types=1);

namespace KenPrz\CustomFields;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use KenPrz\CustomFields\Contracts\FieldType;
use KenPrz\CustomFields\Exceptions\CustomFieldException;

/**
 * Maps type names such as "select" to FieldType implementations.
 */
class FieldTypeRegistry
{
    /** @var array<string, FieldType|class-string<FieldType>> */
    private array $types = [];

    /** @var array<string, FieldType> */
    private array $resolved = [];

    public function __construct(private readonly Container $container) {}

    /**
     * Register a type, replacing any type already registered under the name.
     *
     * @param  FieldType|class-string<FieldType>  $type
     */
    public function register(string $name, FieldType|string $type): void
    {
        if (! preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $name)) {
            throw new InvalidArgumentException("Invalid custom field type name [{$name}].");
        }

        if (is_string($type) && ! is_subclass_of($type, FieldType::class)) {
            throw new InvalidArgumentException("[{$type}] must implement ".FieldType::class.'.');
        }

        $this->types[$name] = $type;
        unset($this->resolved[$name]);
    }

    public function has(string $name): bool
    {
        return isset($this->types[$name]);
    }

    /**
     * @throws CustomFieldException when the type isn't registered
     */
    public function get(string $name): FieldType
    {
        if (! $this->has($name)) {
            throw CustomFieldException::unknownType($name);
        }

        $type = $this->types[$name];

        return $this->resolved[$name] ??= is_string($type) ? $this->container->make($type) : $type;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->types);
    }

    public function registerBuiltIns(): void
    {
        foreach ([
            'text' => Types\TextType::class,
            'textarea' => Types\TextareaType::class,
            'number' => Types\NumberType::class,
            'integer' => Types\IntegerType::class,
            'decimal' => Types\DecimalType::class,
            'boolean' => Types\BooleanType::class,
            'date' => Types\DateType::class,
            'datetime' => Types\DatetimeType::class,
            'time' => Types\TimeType::class,
            'select' => Types\SelectType::class,
            'radio' => Types\SelectType::class,
            'multiselect' => Types\MultiSelectType::class,
            'checkbox' => Types\MultiSelectType::class,
            'email' => Types\EmailType::class,
            'url' => Types\UrlType::class,
            'phone' => Types\PhoneType::class,
            'json' => Types\JsonType::class,
        ] as $name => $class) {
            $this->register($name, $class);
        }
    }
}
