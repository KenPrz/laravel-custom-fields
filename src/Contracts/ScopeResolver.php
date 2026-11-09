<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Tells the package which tenant (or property, organization, account...)
 * the current request belongs to.
 *
 * Set the class in config('custom-fields.scope_resolver'). It is resolved
 * from the container and called whenever no scope was set explicitly.
 */
interface ScopeResolver
{
    /**
     * The current scope, or null to only see global fields.
     */
    public function resolve(): ?Model;
}
