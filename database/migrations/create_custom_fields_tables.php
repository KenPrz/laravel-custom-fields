<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $fields = config('custom-fields.tables.fields');
        $values = config('custom-fields.tables.values');

        Schema::create($fields, function (Blueprint $table) {
            $table->id();
            $table->string('model_type');
            // The scope id is a string so any tenant key works: integers, UUIDs, ULIDs, slugs.
            // Lookups go through scope_key; scope_id only loads the scope relation.
            $table->string('scope_type')->nullable();
            $table->string('scope_id')->nullable();
            $table->index(['scope_type', 'scope_id']);
            // '' for global fields, "{scope_type}:{scope_id}" otherwise. NULLs never collide
            // in a unique index on MySQL or Postgres, so the scope columns can't be used directly.
            $table->string('scope_key')->default('');
            $table->string('key');
            $table->string('label');
            $table->text('description')->nullable();
            $table->string('type', 64);
            // Text, not JSON: MySQL's JSON type reorders object keys, which would scramble
            // the order of select options. Settings are never queried in SQL.
            $table->text('settings')->nullable();
            $table->json('validation_rules')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_searchable')->default(false);
            $table->boolean('is_filterable')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['model_type', 'scope_key', 'key']);
        });

        Schema::create($values, function (Blueprint $table) use ($fields) {
            $table->id();
            $table->foreignId('custom_field_id')->constrained($fields)->cascadeOnDelete();
            $table->morphs('entity');
            // jsonb on Postgres (plain json has no equality operator), json on MySQL, text on SQLite.
            $table->jsonb('value')->nullable();
            $table->timestamps();

            $table->unique(['custom_field_id', 'entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('custom-fields.tables.values'));
        Schema::dropIfExists(config('custom-fields.tables.fields'));
    }
};
