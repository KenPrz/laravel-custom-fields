# Artisan command

Run the command with no arguments for a step-by-step form:

```bash
php artisan make:custom-field
```

The command asks for each part of the field in order. It checks every answer as soon as you
give it:

| Step | Checked right away | Can skip |
|---|---|---|
| Where to create it (migration or database) | | defaults to migration |
| Model | the class exists | |
| Scope (database mode only) | the record exists | yes, for a global field |
| Key | format, existing fields, a migration that hasn't run yet, the model's columns and methods | |
| Label | length | defaults to the key as a headline |
| Type | registered | defaults to `text` |
| Options (select types) | at least one, no duplicates | an empty value ends the list |
| Required | | defaults to no |
| Description | | yes |
| Extra validation rules | every rule exists | yes |

Then the command shows a summary and asks before it creates anything.

## Options

Each option skips its question. With `--no-interaction`, the key, `--model` and `--type` are
required:

```bash
php artisan make:custom-field tax_bracket \
    --model=User --type=select --options="a:Bracket A,b:Bracket B" \
    --required --rules="string" --description="Used for invoices" --no-interaction
```

| Option | Meaning |
|---|---|
| `key` (argument) | The field key |
| `--model` | A model class, a short name such as `User` (found in `App\Models`), or a morph alias |
| `--type` | A registered field type |
| `--label` | Defaults to the key as a headline |
| `--options` | Choices for select types: `value:Label,value:Label`. A value without a label uses itself as the label. |
| `--rules` | Extra Laravel rules, separated by `\|` |
| `--description` | Help text |
| `--required` | Make the field required |
| `--mode` | `migration` (default) or `db` |
| `--scope` | `Model:id`, for example `Property:5`. Database mode only. |
| `--path` | Where to write the migration, relative to the base path |
| `--force` | Skip the production confirmation in database mode |

A rule that contains `|`, such as some regexes, can't go in `--rules`. Add it to the generated
migration by hand.

## Migration mode (default)

The command writes
`database/migrations/{timestamp}_add_tax_bracket_custom_field_to_users.php`:

```php
public function up(): void
{
    CustomFields::global()->create([
        'model' => \App\Models\User::class,
        'key' => 'tax_bracket',
        'label' => 'Tax Bracket',
        'type' => 'select',
        'settings' => [
            'options' => [
                'a' => 'Bracket A',
                'b' => 'Bracket B',
            ],
        ],
        'is_required' => true,
    ]);
}

public function down(): void
{
    CustomFields::global()->dropIfExists(\App\Models\User::class, 'tax_bracket');
}
```

Use migration mode for fields that your code depends on:

- `php artisan migrate` creates the field in every environment.
- The command doesn't touch the database, so it never asks for confirmation.
- Migration mode doesn't allow a scope, because record ids differ between environments.

::: warning
Rolling back the migration deletes the field **with all its values**.
:::

## Database mode

`--mode=db` creates the field on the current database right away and prints its id. Use it for
one-off fields on a live system, or for scoped fields:

```bash
php artisan make:custom-field vip_reference --mode=db --scope=Property:5
```

In production the command asks for confirmation first, like `php artisan migrate`. `--force`
skips the question.
