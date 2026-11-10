# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.0] - 2026-11-10

First release.

### Added

- `custom_fields` and `custom_field_values` tables, with configurable names and an option to
  turn off the package migration after publishing it.
- `CustomFields` facade for creating, updating, finding, ordering, activating, deactivating,
  deleting and restoring field definitions.
- `HasCustomFields` trait. Custom fields act like attributes (`$model->key`, `fill()`,
  `create()`, `update()`, `toArray()`), saved and validated with the model in one transaction.
  Optional `$customFields` allow-list, `$customFieldCasts` and `$loadCustomFields` properties.
- `customFieldRules()` for form requests, plus `listCustomFields`, `getCustomField`,
  `hasCustomField`, `setCustomField`, `syncCustomFields` and `forgetCustomField`.
- Field keys that would shadow a model column, cast, accessor, relation or method are rejected.
- 17 built-in field types and `registerType()` for your own.
- Validation through Laravel's validator, with per-field rules, rule classes and strict mode.
- Global and scoped fields with a `ScopeResolver` contract and per-record `customFieldScope()`.
- Query scopes (`#[Scope]`): `whereCustomField`, `whereCustomFieldIn`, `whereCustomFieldContains`,
  `whereCustomFieldNull`, `whereCustomFieldNotNull`, `withCustomFields` and
  `withoutCustomFields`, on MySQL, PostgreSQL and SQLite.
- Events for field and value changes, dispatched after commit, queue-safe.
- Optional field definition cache.
- `php artisan make:custom-field`: a step-by-step form, or options for scripts. Writes a
  migration (default) or creates the field in the database with `--mode=db`.
- `CustomFields::check()`, `keyError()` and `dropIfExists()`.

[Unreleased]: https://github.com/KenPrz/laravel-custom-fields/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/KenPrz/laravel-custom-fields/releases/tag/v1.0.0
