# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

First release.

### Added

- `custom_fields` and `custom_field_values` tables, with configurable names and an option to
  turn off the package migration after publishing it.
- `CustomFields` facade for creating, updating, finding, ordering, activating, deactivating,
  deleting and restoring field definitions.
- `HasCustomFields` trait with `getCustomField`, `getCustomFieldValues`, `hasCustomField`,
  `setCustomField`, `syncCustomFields` and `forgetCustomField`.
- 17 built-in field types and `registerType()` for your own.
- Validation through Laravel's validator, with per-field rules, rule classes and strict mode.
- Global and scoped fields with a `ScopeResolver` contract and per-record `customFieldScope()`.
- Query scopes: `whereCustomField`, `whereCustomFieldIn`, `whereCustomFieldContains`,
  `whereCustomFieldNull`, `whereCustomFieldNotNull` and `withCustomFields`, on MySQL,
  PostgreSQL and SQLite.
- Events for field and value changes, dispatched after commit.
- Optional field definition cache.
