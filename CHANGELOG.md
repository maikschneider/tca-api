# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- **BREAKING:** A nested object on a relation column whose table is not registered as an API resource is now rejected with `422` / `UNRESOLVABLE_RELATION` instead of being dropped from the write ([#185](https://github.com/maikschneider/tca-api/issues/185)).

## [1.0.0] - 2026-08-25

First stable release.

### Fixed

- **`fields[]` now also applies to virtual properties.** The sparse-fieldset filter was applied to plain columns and to column callbacks, but the `virtualProperties` loop in `ResourceSerializer` only checked group visibility — so a request narrowed to a single column still returned every virtual property in the group, and still paid for it. Virtual properties are typically the most expensive part of a response (image processing, route generation), and a client had no way to opt out. A virtual property that is not listed in `fields[]` is now neither serialized nor computed: its processor and callback never run. ([#175](https://github.com/maikschneider/tca-api/issues/175))

  **Behaviour change:** a client that passes `fields[]` and relies on virtual properties being returned regardless must now name them explicitly. A virtual-property callback reading other keys off the serialized row sees only the fields requested alongside it.

- **File columns no longer cost a handful of queries per record.** `EmbedPreloader` skipped `type=file` columns, so `FileFieldSerializer` fell back to `FileRepository::findByRelation()` once per record — and each of those calls pulled the reference, the `sys_file` row and its metadata individually. A collection therefore issued four queries per record per file column before a single file was opened, and a virtual property sourcing the same column paid for the reference lookup a second time. File references are now resolved for the whole page in three queries via `FileReferencePreloader`, shared between a file column and every virtual property derived from it; only the processing (crop, scale) stays per property. Records the preload does not cover — an embedded record of another table, or a non-frontend context — still resolve through `FileRepository` unchanged. ([#174](https://github.com/maikschneider/tca-api/issues/174))
- **A malformed `crop` value no longer fails the whole API response.** `ImageProcessor` derived its crop-variant ids by decoding the stored crop JSON itself and casting the result to an array. A payload that decodes to a scalar — most commonly JSON that was `json_encode()`d twice by an import or a third-party extension — became `[0 => '…']`, so the variant id passed to `CropVariantCollection::getCropArea(string $id)` was the integer `0` and the request died with a `TypeError`. The decoded shape is now validated and non-string keys are skipped, matching what `CropVariantCollection::create()` already did with the same input: the image serialises normally with an empty `cropVariants` map, exactly as the TYPO3 frontend renders it. ([#172](https://github.com/maikschneider/tca-api/issues/172))

### Changed

- **The TYPO3 13.4.33 / 14.3.5 incompatibility is now declared in `conflict` instead of the core version constraint.** The two broken patch releases were excluded inline as `^13.4 !=13.4.33 || ^14.3 !=14.3.5`, which made the supported range look narrower than it is wherever the requirement is rendered — Packagist, TER, the documentation. `require` now reads `^13.4 || ^14.3` and the two versions are blocklisted in a dedicated `conflict` block, which is the mechanism meant for a known-broken upstream release and produces an explicit conflict message during resolution. No change in which versions install. ([#157](https://github.com/maikschneider/tca-api/issues/157))
- **The README is now an entry point rather than a second manual.** It had grown to 1400 lines duplicating the rendered documentation, so the two drifted — the state banner still said 0.5.0 and the OpenAPI version still said 3.0. It now carries the pitch, a minimal working config, a linked feature overview, installation and setup, and points at the manual for everything else.

### Added

- **Error containment around column and file processors.** A processor operates on one cell of one record, but a throwing processor previously propagated out of the serializer and failed the entire response — so one corrupt row took down a whole collection endpoint. All processor invocations in `ResourceSerializer` and `FileFieldSerializer` now run through a new `ProcessorGuard`, which scopes a failure to that column: the value degrades to `null` and the failure is logged at `error` level with table, uid, column, processor class and the original exception, so the offending record stays findable. In a multi-file field a reference that fails is dropped rather than left as a `null` hole. Setting `tca_api.debugMode` re-throws the original throwable instead, so development and CI fail loudly while production degrades. ([#172](https://github.com/maikschneider/tca-api/issues/172))
- **Write privilege model documentation** ([Configuration/WritePrivileges](Documentation/Configuration/WritePrivileges.rst)). Write modes, actor resolution and the `_tca_api[…]` DataHandler identity, the table access-control policy including how to narrow it with an allow list, and the audit-log format existed only in the README. `Configuration/Security` claimed the table deny list "cannot be overridden by configuration", which understated it — the list cannot be *weakened*, but it can be tightened.
- **Processor error containment documentation** (`Configuration/Columns`). The containment behaviour above shipped undocumented: what a failing processor degrades to, what gets logged, and how `tca_api.debugMode` turns it back into a hard failure.
- The bundled `maikschneider/api-platform-admin` site set and the [typo3-petstore](https://github.com/maikschneider/typo3-petstore) demo project are now documented; both were mentioned only in the README.

## [0.6.2] - 2026-07-29

### Fixed

- **Datetime writes no longer shift ISO 8601 instants by the server UTC offset.** The read path always emits a genuine UTC instant, but the write path handed values to `DataHandler` unchanged, which reinterpreted them — differently per core version. On TYPO3 v13, `type: datetime` columns *without* `dbType` were mangled by core's backend-JS convention (`$value -= (int)date('Z', $value)`), shifting a correct instant by the server offset **at the event's own date**, so the error followed DST and no constant client-side correction was possible. A new `DateTimeInputNormalizer`, applied at the single write choke point in `DataWriteService::processDataMap()`, now converts incoming datetimes to an int Unix timestamp for timestamp columns (which `DataHandler` stores verbatim, bypassing the mangling entirely) and to an explicit-offset UTC ISO 8601 string for native `dbType` columns. It covers the main record and every related record in the same datamap, and a value carrying no timezone designator is read as UTC to match the response contract. ([#170](https://github.com/maikschneider/tca-api/issues/170))
- **Native `dbType: datetime` columns are now read back in the timezone core stores them in.** TYPO3 changed this convention between major versions: v13 writes native columns through `gmdate()` (UTC wall clock), while v14 converts to server localtime (`QueryHelper::transformDateTimeToDatabaseValue()`, and `DateTimeFactory`: *"The database always contains server localtime in native fields"*). `DateTimeValueFormatter` hardcoded UTC, so on v14 with a non-UTC server every `dbType: datetime` value was served off by the server offset in both directions. It now follows the core convention and normalises the result to UTC. `dbType: date` and `dbType: time` are unaffected — v14 does not convert those. ([#170](https://github.com/maikschneider/tca-api/issues/170))

  **Behaviour change:** on TYPO3 v14 with a non-UTC server timezone, `dbType: datetime` values in API responses shift by the server offset relative to 0.6.1. The new value is the correct instant; the old one was the stored wall clock mislabelled as UTC.

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
