<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use KenPrz\CustomFields\Contracts\ScopeResolver;

class FixedScopeResolver implements ScopeResolver
{
    public static ?Model $scope = null;

    public function resolve(): ?Model
    {
        return static::$scope;
    }
}
