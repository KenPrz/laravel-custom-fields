# Roadmap

These features are planned. Nothing here is released yet. The order shows the priority.

To ask for a feature or vote for one, open an issue on
[GitHub](https://github.com/KenPrz/laravel-custom-fields/issues).

## Planned

### 1. Form schema

A `toFormSchema()` method on the model. It returns the fields that a form for that record must
show, with labels, types, options and limits. Today you write this yourself. See
[Building forms](/integrations/forms#_1-schema-endpoint).

### 2. Field groups

A `group` attribute on fields, such as "Guest details" or "Billing". Forms can then show fields
in sections, and `reorder()` can sort the fields inside one group.

### 3. Export and import of field definitions

`php artisan custom-fields:export` and `custom-fields:import`. Use them to copy the fields of
one tenant to another tenant, or from staging to production. Migrations can't hold scoped
fields, because record ids differ between environments.

### 4. Translations

A language file for the package messages, such as the errors for unknown and inactive fields.
Optional labels per locale.

### 5. Faster bulk writes

An opt-in bulk path for imports that writes values with one `upsert` per batch. This path
doesn't fire value events or apply value-model casts.

## Under consideration

These features wait for a real need:

- **Sort by a custom field** (`orderByCustomField()`). The sort can't use an index, so it is
  slow on large tables.
- **Relation type.** A field that points to another model, such as "Assigned staff" → `User`,
  with an `exists` check and eager loading.
- **File type.** A field that stores a path on a Laravel disk, with checks for mime type and
  size.
- **Conditional fields.** For example, show "Company name" only when "Guest type" is
  "Corporate". The rule must apply on the frontend and in validation.
- **Filament plugin.** A page to manage fields and a form component. This would be a separate
  package.

## Not planned

- **MariaDB.** Its JSON functions differ from MySQL's, so the query scopes would need their own
  SQL.
- **Value history.** Use the value events instead. See [Audit log](/integrations/audit-log).
  A built-in history would force one table design on every app.
