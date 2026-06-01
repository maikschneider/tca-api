# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Auto-derived validators from TCA.** Write operations now enforce constraints declared in TCA with zero configuration:
  - `input`/`text` columns with `config.max` → `maxLength` validator auto-injected.
  - `number` columns with `config.range.lower`/`upper` → `minValue`/`maxValue` validators auto-injected.
  - `group`, `inline`, `file`, and `category` columns with `config.maxitems`/`minitems` → `maxItems`/`minItems` validators auto-injected.
  - Column-level `required: true` (TYPO3 v13+) → `required` flag auto-injected.
  - Derivation is **gap-fill only** — explicit validators always win over auto-derived ones.
  - Per-column opt-out: set `'tcaValidation' => false` in the column config.
  - New validator types `minValue`, `maxValue`, `minItems`, `maxItems` are also available for explicit configuration.
  - New documentation page: [Validation](Documentation/Configuration/Validation.rst).
- **Language-aware API.** TYPO3 multi-language sites are now first-class:
  - URL base segments (e.g. `/de/api/...`) resolve to the matching `SiteLanguage` via TYPO3's own site middleware.
  - Optional `X-Locale: <languageId>` HTTP header overrides the URL-derived locale; invalid/unknown/disabled values return `400 Bad Request` with a `hydra:Error` body listing the available enabled language ids.
  - Queries against translatable tables (TCA `ctrl.languageField`) constrain to `sys_language_uid IN (0, -1)` and overlay translations on default-language rows. The site's `fallbackType` controls behaviour for untranslated rows (`strict` drops, `fallback`/`free` keeps the default).
  - Records flagged `sys_language_uid = -1` ("all languages") are always returned, regardless of `fallbackType`.
  - Hydra `@id` always uses the default-language UID even when the payload is translated — IRIs are stable across locales.
  - Per-resource opt-out via `general.language.mode = 'ignore'` returns every language variant as a distinct member.
  - Response cache key is now language-scoped — locales never share an entry.
  - Response headers: `Content-Language` echoes the resolved locale; `Vary: X-Locale` (concatenated with `Origin` when CORS is enabled).
  - CORS preflight advertises `X-Locale` in `Access-Control-Allow-Headers`.
  - Invalid-`X-Locale` 400 responses carry CORS headers when CORS is enabled, so cross-origin browser clients see the `hydra:Error` payload instead of a CORS failure.
- New request attributes: `tca_api.language` (resolved `SiteLanguage`) and `tca_api.request_prefix` (matched URL prefix including the language base segment).
- New configuration page: [Languages](Documentation/Configuration/Languages.rst).

### Changed

- CORS preflight `Access-Control-Allow-Headers` now includes `X-Locale`.
- `Vary` response header now includes `X-Locale` on all API responses.

## [0.1.0] - 2026-05-26

### Added

- Initial release.

[Unreleased]: https://github.com/maikschneider/tca-api/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/maikschneider/tca-api/releases/tag/v0.1.0
