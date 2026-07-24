# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.6.1] - 2026-07-24

### Added

- **Download button in the backend Integrations docs module.** The backend Swagger UI module (TYPO3 v14+) gained a docheader button that downloads the generated OpenAPI specification as JSON for the currently shown site. The spec is built server-side via the same `OpenApiBuilder` that backs the public `openapi.json` endpoint, so the download is available to admins regardless of the public access gates. ([#164](https://github.com/maikschneider/tca-api/issues/164))

### Fixed

- **Backend docs module now respects the site base path and enabled state.** The Integrations documentation module (TYPO3 v14+) previously listed every configured site and anchored the OpenAPI `servers` URL at the origin only, dropping any sub-path — so a site served under a base path (e.g. `https://example.com/bootstrap`) pointed its docs at the root. The module now only lists sites where the API is actually active (site imports the `tca_api` set and does not set `tca_api.enabled = false`, mirroring `TcaApiMiddleware`), and resolves the full site base URL (origin **and** path) for both the Swagger UI server and the download. Sites without the API are hidden; requesting one via `downloadAction` yields 404. ([#168](https://github.com/maikschneider/tca-api/issues/168))
- **Empty collection responses are now cache-invalidated.** The base `{table}` cache tag is now attached at cache activation in `RequestDispatcher` rather than only per-record in `ResourceSerializer`, so a cached empty `list` response (which serialises no records and therefore emitted no table tag) is flushed when a record is later added to the table. ([#169](https://github.com/maikschneider/tca-api/issues/169))
- Documentation: correct the cache invalidation section — API write operations (`create`/`update`/`delete`) do trigger cache invalidation via `AfterWriteEvent`/`WriteCacheInvalidationListener`; the old "do not trigger" note was stale (#165)

## [0.6.0] - 2026-07-22

### Added

- **Configurable endpoint grouping (OpenAPI tags).** A new optional `general.group` key sets the OpenAPI tag under which a resource's operations are grouped in Swagger UI. Accepts a plain string (`'group' => 'Editorial'`) or an array with a name and optional description (`'group' => ['name' => 'Editorial', 'description' => '…']`). Point several resources at the same name to merge them into one section; the description becomes a top-level tag description. When omitted, a resource falls back to its `resourceType` as the tag, so every resource gets its own section out of the box — fully backward compatible, no single "default" bucket. See [OpenAPI spec & Swagger UI documentation](README.md#endpoint-grouping). ([#159](https://github.com/maikschneider/tca-api/issues/159))
- **"Integrations" backend module (TYPO3 v14+).** A backend module registered under the v14 "Integrations" main module (sibling to Reactions and Webhooks) renders the interactive OpenAPI documentation (Swagger UI) directly in the backend. The specification is built server-side per site — reusing the same builder that backs the public `openapi.json` endpoint — and is always available to administrators, independent of the `tca_api.enabled` / `tca_api.swaggerUiEnabled` / `tca_api.openApiExposed` site settings that gate the public frontend. A site switcher (shown when more than one site is configured) rebuilds the spec per site, an "Open in new tab" button links to the frontend Swagger UI, and the module honours the backend colour scheme (light/dark). No module is registered on TYPO3 v13. ([#156](https://github.com/maikschneider/tca-api/issues/156))

## [0.5.0] - 2026-07-21

### Added

- **Relation-path filters.** A filter key containing a dot now filters the resource by a column reached through one or more relation hops — `'color_id.name'` (one FK hop), `'categories.title'` (one MM hop), or `'categories.parent.title'` (chained hops). The dotted key is auto-detected: no new filter class to name in config — the declared filter (e.g. `ExactFilter`) becomes the leaf comparison and the new `RelationPathFilter` resolves each hop from TCA and builds the nested `IN` subqueries (de-duplicating, so pagination stays correct). Supports single-value `select`/`type=category` FK relations, MM relations, and `type=inline` (`foreign_field`, honouring `foreign_table_field` / `foreign_match_fields` discriminators); each hop honours the related table's soft-delete/enable-field restrictions. Non-MM group relations and multi-table (ambiguous) relations are rejected at boot. Path filters are supplied via the bracket form (`?filters[categories.title]=News`). ([#150](https://github.com/maikschneider/tca-api/issues/150))
- **Cross-entity search in `SearchFilter`.** `SearchFilter`'s `columns` list may now include relation-path (dotted) entries — `['title', 'categories.title', 'color_id.name']` — so a single `?filters[q]=…` LIKE-searches the resource's own columns **and** columns of related records, OR-ed together. Related columns are matched through a `t.uid IN (subquery)` (with per-hop enable-field restrictions), and dotted columns are resolved and validated at boot. The hop-resolution/subquery logic is shared with relation-path filters via the new `RelationSubqueryBuilder`. ([#154](https://github.com/maikschneider/tca-api/issues/154))

### Changed

- **Restrict TYPO3 core version.** Exclude TYPO3 13.4.33 and 14.3.5 compatibility because of a regression that breaks all write operations, see [TYPO3 issue #110242](https://forge.typo3.org/issues/110242). ([#157](https://github.com/maikschneider/tca-api/issues/157))

## [0.4.0] - 2026-06-24

### Added

- **Decoupled read/write storage pages.** New optional `general.readStoragePids` key widens the read-side pid constraint independently of `storagePid` (which stays the single write target). Accepts an array or comma-separated list of pids (reads use `pid IN (...)`), or the sentinel `'*'` to read from all pages regardless of the write target. When omitted, reads fall back to `storagePid` exactly as before — fully backward compatible. This enables "read from many places, write into one". See [Resource Definition documentation](Documentation/Configuration/ResourceDefinition.rst).
- **Column callbacks.** The `callback` meta-key (`[ClassName::class, 'method']`) now works on normal columns, not just virtual properties. The callback runs after all columns and relations are resolved, but before virtual properties — so virtual properties can build on callback-transformed column values. It receives the serialized row and the raw DB row `(array $serializedRow, array $rawRow): mixed`, and its return value replaces the column's value. Callbacks honour the column's visibility (`groups`) and sparse-fieldset (`?fields[]=…`) gates. See [Columns documentation](Documentation/Configuration/Columns.rst).
- **Custom validators.** Column `validators` now accept a class-string `type` referencing a `MaikSchneider\TcaApi\Validation\ValidatorInterface` implementation, alongside the built-in types. Validators are auto-discovered via the `tca_api.validator` DI tag (no `Services.yaml` entry needed with `autoconfigure`), receive a typed `ValidationContext` (value, column, table, per-validator `options`, full request `body` for cross-field rules, and the `partial`/PATCH flag), and return a list of `Violation` objects that merge into the existing 422 Hydra error response. Class-strings are verified at config-load time (class exists + implements the interface). See [Validation documentation](Documentation/Configuration/Validation.rst). ([#147](https://github.com/maikschneider/tca-api/issues/147))

### Changed

- **Virtual-property callbacks now always run.** Previously a virtual property's `callback` was only invoked when no file column or processor was defined. It now composes with them: the file/processor produces the base value and the callback runs last as a final transform, able to read every column, column callback, and earlier virtual property from `$serializedRow`. See [Virtual Properties documentation](Documentation/Configuration/VirtualProperties.rst).
- **Asset URLs are now root-absolute.** `FileProcessor` and `ImageProcessor` previously emitted FAL `publicUrl` values exactly as TYPO3 returns them — site-root-relative **without** a leading slash (e.g. `fileadmin/_processed_/foo.jpg`). In a JSON API consumed by JavaScript that is a footgun: the value resolves against the current document path, so on `/events/123/slug` an `<img src>` or `fetch()` wrongly targets `/events/123/fileadmin/…`. Both processors now prepend a leading slash to relative URLs (`/fileadmin/_processed_/foo.jpg`), applied uniformly to `publicUrl` and every `cropVariants[*].publicUrl`. Already-absolute URLs are left untouched: full URLs with a scheme (`http://`, `https://`), scheme-relative URLs (`//host/…`), and values that already start with `/`. The image `absolute => true` option is unchanged (still emits a full scheme+host URL). Clients that previously prepended `/` themselves should remove that workaround. ([#145](https://github.com/maikschneider/tca-api/issues/145))

### Fixed

- **Multipart `PUT`/`PATCH` uploads.** Form fields and uploaded files sent as `multipart/form-data` on `PUT`/`PATCH` update requests were silently dropped, because PHP's SAPI only populates `$_POST`/`$_FILES` (and therefore `getParsedBody()`/`getUploadedFiles()`) for `POST`. `TcaApiMiddleware` now parses the raw multipart body itself for these methods and re-injects the result, so update operations receive form data and files exactly like create. Adds a runtime dependency on `riverline/multipart-parser`. ([#143](https://github.com/maikschneider/tca-api/issues/143))

## [0.3.0] - 2026-06-04

### Added

- **`RouteEnhancerProcessor`.** Generates speaking frontend URLs per record without writing routing code:
  - New column processor `MaikSchneider\TcaApi\Serializer\Processing\RouteEnhancerProcessor`. Most often used on a virtual property to expose a record's URL alongside its data.
  - New typed config sub-DTO `RouteDefinition` under the column key `route`. Keys: `pid` (literal int or placeholder), `extension`, `plugin`, `controller`, `action`, `arguments`, `parameters`, `absolute` (default `true`), `fragment`. All errors raised at boot time with key-specific messages.
  - Placeholder grammar in `pid`, `arguments`, and `parameters`: `{column_name}` resolves from the raw DB row, `{$site.setting.key}` resolves from `SiteSettings`. Single-placeholder strings preserve their underlying type so `'{uid}'` stays an int.
  - URL construction is delegated to `Site::getRouter()->generateUri()` — any `routeEnhancer` configured on the target page (e.g. an Extbase plugin) applies transparently.
  - Multi-language aware: the current `SiteLanguage` is passed to the router as `_language`, so URLs are anchored to the matching language base (`/de/...`, `/fr/...`, …).
  - New documentation page: [RouteEnhancer](Documentation/Configuration/RouteEnhancer.rst).
- New helper `MaikSchneider\TcaApi\Serializer\Processing\PlaceholderResolver` — public service used by `RouteEnhancerProcessor`. Available for reuse by custom processors that need the same `{column}` / `{$setting.key}` grammar.
- **Reverse MM relations.** `group` and `inline` columns can now resolve the foreign side of a MM relation. The relation direction is auto-detected from TCA `MM_opposite_field`; no extra config needed.

### Changed

- `TcaApiMiddleware` now sets `$GLOBALS['TYPO3_REQUEST']` before dispatching. The API middleware short-circuits the frontend `RequestHandler` (which is what normally populates this back-compat global), so processors and downstream code that rely on it had no access to the current request/language. This mirrors the same one-liner that `cms-frontend/Http/RequestHandler` performs.
- All queries in `DataRepository` now run the main resource table under the alias `t` (`FROM {table} AS t`, `SELECT t.*`). Custom filters that join additional tables must qualify main-table column references with `t.` — see [Filters documentation](Documentation/Configuration/Filters.rst).

### Fixed

- Limit `X-Cache-Tags` response header to prevent Apache's 8 190-byte `LimitRequestFieldSize` overflow (FastCGI "Premature end of script headers" on responses with many cached records).

## [0.2.0] - 2026-06-02

### Added

- **Auto-derived validators from TCA.** Write operations now enforce constraints declared in TCA with zero configuration:
  - `input`/`text` columns with `config.max` → `maxLength` validator auto-injected.
  - `number` columns with `config.range.lower`/`upper` → `minValue`/`maxValue` validators auto-injected.
  - `group`, `inline`, `file`, and `category` columns with `config.maxitems`/`minitems` → `maxItems`/`minItems` validators auto-injected.
  - Columns with `config.required: true` → `required` flag auto-injected.
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

## [0.1.1] - 2026-05-29

### Fixed

- Add `packaging_exclude.php` for tailor releases to TER
- Respect `foreign_table_field` and `foreign_match_fields` when resolving related records for `group` and `inline` columns.

## [0.1.0] - 2026-05-26

### Added

- Initial release.
