<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Exceptions;

use RuntimeException;

/**
 * Thrown when the package is used in a way it can't safely handle.
 * Bad user input raises Laravel's ValidationException instead.
 */
class CustomFieldException extends RuntimeException
{
    public static function unknownType(string $type): self
    {
        return new self("Custom field type [{$type}] is not registered.");
    }

    public static function unknownField(string $model, string $key): self
    {
        return new self("Custom field [{$key}] does not exist for [{$model}].");
    }

    public static function outOfScope(string $key): self
    {
        return new self("Custom field [{$key}] belongs to another scope.");
    }

    public static function invalidModel(string $model): self
    {
        return new self("[{$model}] is not an Eloquent model class or morph map alias.");
    }
}
