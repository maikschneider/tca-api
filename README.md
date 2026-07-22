<div align="center">

# `TCA_API` — REST API for TYPO3

![Extension icon](Resources/Public/Icons/Extension.svg)

[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![TYPO3](https://img.shields.io/badge/TYPO3-13.4%20%7C%2014-orange.svg)](https://typo3.org/)
[![PHP](https://img.shields.io/badge/PHP-%5E8.2-777BB4.svg)](https://php.net/)
[![codecov](https://codecov.io/gh/maikschneider/tca-api/graph/badge.svg?token=J2CNGVXEX1)](https://codecov.io/gh/maikschneider/tca-api)

`TCA_API` is a TYPO3 extension that exposes database tables as **Hydra JSON-LD** resources through a configuration-driven REST API. Define which tables, columns, and operations to expose — the extension handles routing, serialization, validation, pagination, and access control.

</div>

> **State:** Beta (0.5.0) — feedback and contributions very welcome. See [GitHub Discussions](https://github.com/maikschneider/tca-api/discussions) to help validate the architecture, security model, and design decisions before they stabilize.

## Motivation

TYPO3 offers several existing approaches for serving content as structured data. TCA_API was built to fill a gap where other API extensions fall short: exposing multiple resources uniformly, with minimal boilerplate and strong query efficiency.

See the [Motivation chapter](https://docs.typo3.org/p/maikschneider/tca-api/main/en-us/Motivation/Index.html) in the documentation for the full comparison.

## Features

- **Full CRUD** — List, show, create, update and delete operations
- **Hydra JSON-LD** — Responses follow the [Hydra](https://www.hydra-cg.com/) specification (`application/ld+json`)
- **Configuration-driven** — Expose tables by registering a PHP configuration array; no custom controllers needed
- **Serialization groups** — Use `groups` to control which columns appear per operation
- **Filtering** — Exact, partial, word-start, range, full-text search, and many-to-many filter strategies via query parameters; relation-path filters (`categories.title`) filter by fields of related records across one or more hops; configurable defaults and private (non-overrideable) filters; extensible via `FilterInterface`
- **Sorting** — Configurable allowed sort columns with defaults
- **Pagination** — Offset-based pagination with Hydra `PartialCollectionView` links
- **Validation** — Built-in `required`, length, range, item-count, and regex validators with structured 422 error responses; auto-derived from TCA and extensible with custom rules via `ValidatorInterface`
- **File uploads** — `multipart/form-data` file uploads on write endpoints with per-column FAL storage, size limits, and filename masks
- **Access control** — Per-operation roles: `PUBLIC`, `FE_USER`, `FE_GROUP`, `BE_USER`, `BE_ADMIN`, `OWNER` (record-level ownership), or custom callables
- **Write privilege model** — Actor-aware write context with configurable execution strategy, per-table access control, system-table deny list, and structured audit logging
- **Relation handling** — Plain IRI strings or fully embedded related records (configurable depth); create new related records inline on POST/PUT/PATCH
- **Userinfo endpoint** — Expose the authenticated FE user's own record at a configurable URL
- **OpenAPI + Swagger UI** — Auto-generated OpenAPI 3.1.0 spec and interactive Swagger UI served directly from the API prefix
- **API Platform compatible** — Responses follow the Hydra JSON-LD spec and work with [API Platform](https://api-platform.com/) and its ecosystem
- **PSR-14 events** — Hook into the request lifecycle with Before/AfterOperation and Before/AfterWrite events
- **TYPO3 DataHandler** — Write operations use TYPO3's DataHandler for safe, consistent data manipulation
- **Response caching** — Tag-based HTTP response caching for `list` and `show` operations with automatic invalidation via the TYPO3 DataHandler hook; configurable TTL and per-request bypass
- **Multi-language** — URL base segments (`/de/api/...`) and an optional `X-Locale` header resolve the TYPO3 `SiteLanguage`; queries constrain on `sys_language_uid`, translations overlay default-language rows honouring the site's `fallbackType`, `sys_language_uid = -1` records stay visible, and cache keys are language-scoped
- **Extensible handler pipeline** — Register custom operation handlers or override built-in ones from any extension
- **TCA-style overrides** — Override or extend any resource config shipped by a third-party package via `Configuration/TcaApi/Overrides/` — mirrors TYPO3's `$GLOBALS['TCA']` + `TCA/Overrides/` pattern

## Requirements

| Dependency | Version          |
|------------|------------------|
| PHP        | ^8.2             |
| TYPO3      | ^13.4 \|\| ^14.3 |

## Demo

A demo project showcasing various TCA_API configurations is available at [maikschneider/typo3-petstore](https://github.com/maikschneider/typo3-petstore). It serves as the reference for different resource setups (currently in early stages).

## Installation

```bash
composer require maikschneider/tca-api
```

## Site set

The extension ships a TYPO3 **site set** (`maikschneider/tca-api`). Add it to your site's `config/sites/<site>/config.yaml`:

```yaml
dependencies:
  - maikschneider/tca-api
```

This exposes the following site settings, configurable per site in the TYPO3 backend under **Site Management → Sites → Settings**:

| Setting | Default | Description |
|---|---|---|
| `tca_api.enabled` | `true` | Enable or disable the API for this site |
| `tca_api.apiPrefix` | `/_api/` | URL prefix for all API endpoints |
| `tca_api.defaultItemsPerPage` | `20` | Default page size for collection responses |
| `tca_api.allowedResources` | *(empty — all)* | Comma-separated list of resource names to expose; empty allows all |
| `tca_api.debugMode` | `false` | Return verbose error details in responses |
| `tca_api.openApiExposed` | `PUBLIC` | Who may access the OpenAPI spec (`PUBLIC`, `FE_USER`, `BE_USER`, `BE_ADMIN`, `NONE`) |
| `tca_api.apiSpecTitle` | `TCA_API` | Title shown in the OpenAPI spec and Swagger UI |
| `tca_api.apiSpecDescription` | *(empty)* | Description shown in the OpenAPI spec and Swagger UI |
| `tca_api.apiSpecVersion` | `1.0.0` | Version string in the OpenAPI spec info block |
| `tca_api.swaggerUiEnabled` | `PUBLIC` | Who may access the Swagger UI (`PUBLIC`, `FE_USER`, `BE_USER`, `BE_ADMIN`, `NONE`) |
| `tca_api.corsEnabled` | `false` | Enable CORS support — adds headers to all API responses and handles `OPTIONS` preflight requests with a `204` response |
| `tca_api.corsOrigin` | `*` | Value for `Access-Control-Allow-Origin` |
| `tca_api.corsAllowCredentials` | `false` | Include `Access-Control-Allow-Credentials: true` header (required when the client sends cookies or `Authorization` headers cross-origin) |

## Quick start

### 1. Create the resource configuration

Place a PHP file in `Configuration/TcaApi/` inside any active TYPO3 extension. **No manual registration is needed** — the extension auto-discovers all `*.php` files from every active package's `Configuration/TcaApi/` directory at boot time and caches the result.

**Zero-config (read-only):** the three required keys are enough. `operations` defaults to `['list', 'show']` and both are `PUBLIC` by default — no `security` block needed:

```php
<?php

return [
    'general' => [
        'table'        => 'tx_myext_domain_model_article',
        'resourceName' => 'articles',
        'resourceType' => 'Article',
    ],
];
```

To enable write operations, add them explicitly:

```php
<?php

use MaikSchneider\TcaApi\Enum\AccessRole;

return [
    'general' => [
        'table'        => 'tx_myext_domain_model_article',
        'resourceName' => 'articles',
        'resourceType' => 'Article',
        'operations'   => ['list', 'show', 'create', 'update', 'delete'],
        'storagePid'   => 1,
    ],
    'security' => [
        'create' => AccessRole::FE_USER,
        'update' => AccessRole::FE_USER,
        'delete' => AccessRole::BE_ADMIN,
    ],
];
```

**Explicit mode** (opt in by adding `groups` to any column — only columns with `groups` are then exposed):

```php
'columns' => [
    'title' => [
        'groups'     => ['list', 'show', 'create', 'update'],
        'required'   => true,
        'validators' => [
            ['type' => 'maxLength', 'max' => 255],
        ],
    ],
    'color_id' => ['groups' => ['list', 'show']],
],
```

### 2. Use the API

All resources are served under the `/_api/` prefix:

```
GET    /_api/articles              → List collection
GET    /_api/articles/1            → Show item
POST   /_api/articles              → Create item
PUT    /_api/articles/1            → Full update
PATCH  /_api/articles/1            → Partial update
DELETE /_api/articles/1            → Delete item
```

## Overriding third-party resource configs

Any active extension can modify a resource configuration defined by another extension — without forking it. This mirrors TYPO3's `$GLOBALS['TCA']` + `TCA/Overrides/` pattern.

Place a PHP file in `Configuration/TcaApi/Overrides/` of any active extension. The file receives `$GLOBALS['TCA_API']` populated with all base configs and can manipulate it freely:

```php
<?php
// EXT:my_site/Configuration/TcaApi/Overrides/Events.php

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Filter\ExactFilter;

// Add a column
$GLOBALS['TCA_API']['events']['columns']['location'] = ['groups' => ['list', 'show']];

// Remove a column
unset($GLOBALS['TCA_API']['events']['columns']['internal_notes']);

// Add a filter
$GLOBALS['TCA_API']['events']['filters']['location'] = ExactFilter::class;

// Restrict operations to read-only
$GLOBALS['TCA_API']['events']['general']['operations'] = ['list', 'show'];

// Change a security role
$GLOBALS['TCA_API']['events']['security']['delete'] = AccessRole::FE_USER;
```

Override files within a package run in alphabetical filename order. Across packages they run in TYPO3 package load order. Last write wins.

## OpenAPI spec & Swagger UI

The extension generates a live **OpenAPI 3.1.0 JSON spec** from the registered resources and exposes two additional endpoints:

| Endpoint | Description |
|---|---|
| `{apiPrefix}openapi.json` | Machine-readable OpenAPI spec (e.g. `/_api/openapi.json`) |
| `{apiPrefix}swagger-ui` | Interactive Swagger UI (e.g. `/_api/swagger-ui`) |

Access to both endpoints is controlled by the `tca_api.openApiExposed` and `tca_api.swaggerUiEnabled` site settings respectively. Both default to `PUBLIC`.

### Endpoint grouping

By default each resource's operations appear under its own section in Swagger UI, named after the resource's `resourceType`. Use `general.group` to place several resources under a shared, named section (an OpenAPI tag):

```php
// Configuration/TcaApi/Articles.php
'general' => [
    'table'        => 'tx_myext_domain_model_article',
    'resourceName' => 'articles',
    'resourceType' => 'Article',
    'group'        => 'Editorial',
],
```

To add a description shown above the group in Swagger UI, use the array form:

```php
'group' => [
    'name'        => 'Editorial',
    'description' => 'Editorial content endpoints',
],
```

Point several resources at the same `group` name to merge them into one section. Sections are listed in the order the resources are registered; the first configured `description` for a given group wins. When no `group` is set, the resource falls back to its `resourceType`, so grouping is entirely opt-in and changes nothing for existing configs.

### Backend module (TYPO3 v14+)

On TYPO3 v14 the extension registers a **TCA API** module under the *Integrations* main module (alongside *Reactions* and *Webhooks*) that renders the Swagger UI directly in the backend. It is available to administrators and is **always enabled** — independent of the `tca_api.enabled`, `tca_api.openApiExposed`, and `tca_api.swaggerUiEnabled` site settings that gate the public frontend endpoints. The specification is built per site; when more than one site is configured, a site selector is shown in the module's doc header.

## API Platform

TCA_API responses are compatible with [API Platform](https://api-platform.com/) and its ecosystem. A bundled React-based admin UI is available via the `maikschneider/api-platform-admin` site set — add it to your site's dependencies to activate it. This UI is intended for testing during extension development and may be removed in a future version.

## Configuration reference

### General

| Key               | Description                                                                                                               |
|-------------------|---------------------------------------------------------------------------------------------------------------------------|
| `table`           | TYPO3 database table name                                                                                                 |
| `resourceName`    | URL slug used in `/_api/{resourceName}`                                                                                   |
| `resourceType`    | JSON-LD `@type` value                                                                                                     |
| `type`            | Set to `'userinfo'` to create a [userinfo endpoint](#userinfo-endpoint)                                                   |
| `operations`      | Array of enabled operations: `list`, `show`, `create`, `update`, `delete`; defaults to `list` and `show` if not set       |
| `itemsPerPage`    | Default page size for list operations                                                                                     |
| `maxItemsPerPage` | Upper limit for `itemsPerPage`; when set, the requested page size is clamped to this value. No limit when omitted         |
| `storagePid`      | Page ID for newly created records                                                                                         |
| `writeMode`       | Write execution strategy: `acting_user` (default) or `system_admin` — see [Write privilege model](#write-privilege-model) |
| `group`           | OpenAPI tag used to group this resource's operations in Swagger UI — a string, or `['name' => …, 'description' => …]`; defaults to `resourceType` — see [Endpoint grouping](#endpoint-grouping) |

### Column visibility

TCA_API has two visibility modes. The mode is **auto-detected** per resource:

**Default mode** — active when no column has `groups` set. All non-system TCA columns (i.e. not `hidden`, `deleted`, `tstamp`, `crdate`, language/workspace fields) are automatically exposed for read and write.

**Explicit mode** — active as soon as any column declares `groups`. Only columns with a matching `groups` entry are exposed; all others are hidden.

#### Serialization groups

Use `groups` instead of `readable`/`writable` to control visibility per operation:

```php
'columns' => [
    'title'  => ['groups' => ['list', 'show', 'create', 'update']],  // everywhere
    'teaser' => ['groups' => ['list']],                              // list only
    'body'   => ['groups' => ['show']],                              // detail view only
    'secret' => ['groups' => []],                                    // never exposed
],
```

Valid group names: `list`, `show`, `create`, `update`.

#### Columns reference

Each entry in `columns` maps to a database column. All keys are optional:

| Key            | Description                                         |
|----------------|-----------------------------------------------------|
| `type`         | Data type hint for OpenAPI schema (e.g. `string`, `integer`) |
| `readable`     | `true` — include in responses (legacy; use `groups` instead) |
| `writable`     | `true` — accept in create/update requests (legacy; use `groups` instead) |
| `groups`       | Array of operations where this column is active — triggers explicit mode (`list`, `show`, `create`, `update`) |
| `required`     | Require on POST/PUT (skipped on PATCH if absent)    |
| `embed`        | `true` or `['depth' => N]` — inline related records instead of IRI strings |
| `resourceName` | Override related resource name for relation columns |
| `processor`    | Column processor class (does **not** trigger explicit mode) |
| `callback`     | `[ClassName::class, 'method']` invoked after all columns and relations are resolved; its return value replaces the column's value. See [Column callbacks](#column-callbacks) |
| `validators`   | Array of validation rules (see [Validation](#validation)) |
| `upload`       | Enable file uploads for this column via `multipart/form-data`; must include at least `folder` (FAL storage ref, e.g. `1:/uploads/`). See [File uploads](#file-uploads) |
| `image`        | Image processing options for `ImageProcessor` columns — controls dimensions, crop variant selection, format conversion, and URL mode. See [Image processor config](#image-processor-config) |

#### Field type support

The serializer automatically handles all TYPO3 TCA field types. Relational types are resolved via dedicated serializers; scalar types that store encoded data are decoded before output; sensitive types are excluded.

| TCA type | Handling | Output |
|---|---|---|
| `file` | `FileFieldSerializer` — auto-selects `ImageProcessor` or `FileProcessor` | Processed file/image object(s) |
| `category` | `RelationSerializer` | IRI string or embedded record |
| `select` (relational) | `RelationSerializer` | IRI string or embedded record |
| `inline` | `RelationSerializer` | IRI string or embedded record |
| `group` | `GroupFieldSerializer` | IRI string or embedded record |
| `json` | Auto-decoded via `json_decode` | Decoded array/object |
| `imageManipulation` | Auto-decoded via `json_decode` | Decoded crop config object |
| `flex` | Auto-decoded via `GeneralUtility::xml2array` | Decoded associative array |
| `datetime` | Auto-formatted to ISO 8601 (`DateTimeInterface::ATOM`) | `"2024-01-01T00:00:00+00:00"` or `null` |
| `link` | Auto-applies `TypoLinkProcessor` | Resolved public URL string |
| `password` | **Excluded** — never appears in API responses | *(column omitted)* |
| `input`, `text`, `number`, `email`, `color`, `country`, `slug`, `radio`, `select` (static) | Raw DB value | String, integer, or appropriate scalar |
| `check` | Raw DB value | Bitmask integer |
| `language` | Excluded by `TcaColumnDiscovery` via `ctrl.languageField` | *(column omitted)* |
| `folder`, `none`, `passthrough`, `user` | Raw DB value | Implementation-defined |

An explicit `processor` on a column definition always overrides the automatic handling described above.

#### Column callbacks

A `callback` is a lightweight alternative to a processor for post-processing a single column. Unlike a processor — which receives only the raw column value — a callback runs **after** every column and relation has been resolved, receives the fully serialized row plus the raw DB row, and its return value **replaces** the column's value:

```php
'columns' => [
    'title'    => ['groups' => ['list', 'show']],
    'color_id' => ['groups' => ['list', 'show'], 'embed' => true],
    'label'    => [
        'groups'   => ['list', 'show'],
        'callback' => [ArticleCallbacks::class, 'buildLabel'],
    ],
],
```

```php
final class ArticleCallbacks
{
    public function buildLabel(array $serializedRow, array $rawRow): string
    {
        // $serializedRow already contains the resolved 'color_id' relation.
        $color = $serializedRow['color_id']['name'] ?? 'n/a';

        return sprintf('%s (%s)', $serializedRow['title'] ?? '', $color);
    }
}
```

The signature is `(array $serializedRow, array $rawRow): mixed`. The class is built via `GeneralUtility::makeInstance()`, so constructor DI works. Because callbacks run after relation resolving — and **before** virtual properties — they can read embedded relations, processor output, and sibling columns from `$serializedRow`, and a virtual property can in turn build on a column's callback result. Callbacks honour the column's visibility (`groups`) and sparse-fieldset (`?fields[]=…`) gates. The same `callback` key is also available on [virtual properties](#virtual-properties).

### Filters

Each filterable column maps to a filter class. Use the **shorthand** (class name only) or the **options form** (two-element array with class + config):

```php
use MaikSchneider\TcaApi\Filter\ExactFilter;
use MaikSchneider\TcaApi\Filter\MmFilter;
use MaikSchneider\TcaApi\Filter\PartialFilter;
use MaikSchneider\TcaApi\Filter\RangeFilter;
use MaikSchneider\TcaApi\Filter\SearchFilter;
use MaikSchneider\TcaApi\Filter\WordStartFilter;

'filters' => [
    'title'  => ExactFilter::class,            // ?title=Foo  (or legacy ?filters[title]=Foo)
    'name'   => PartialFilter::class,          // ?name=oo  → LIKE %oo%
    'slug'   => WordStartFilter::class,        // ?slug=Fo  → LIKE Fo%
    'year'   => RangeFilter::class,            // ?filters[year][gte]=2020&filters[year][lte]=2024
    'q'      => [                              // Full-text search — options form
        SearchFilter::class,
        [
            'columns' => ['title', 'teaser', 'body'],
            'match'   => 'partial',            // 'partial' (default) or 'word_start'
        ],
    ],
    // Shorthand: derive MM config from TCA automatically
    'categories' => MmFilter::class,

    // Options form: supply MM table config explicitly
    'tags' => [
        MmFilter::class,
        [
            'mm_table'       => 'tx_myext_article_tag_mm',
            'mm_local_key'   => 'uid_local',
            'mm_foreign_key' => 'uid_foreign',
        ],
    ],
],
```

#### Built-in filter classes

| Class | Description | Options |
|-------|-------------|---------|
| `ExactFilter` | `WHERE column = value` | — |
| `PartialFilter` | `WHERE column LIKE %value%` | — |
| `WordStartFilter` | `WHERE column LIKE value%` | — |
| `RangeFilter` | Comparison operators on a column (numeric, string or date) | `value` must be `['gte'=>…, 'lte'=>…, 'gt'=>…, 'lt'=>…]`. The DBAL parameter type is inferred from the column's TCA (`number`, `datetime`, …); the optional `type` (`int`\|`float`\|`string`\|`date`\|`datetime`) overrides it |
| `SearchFilter` | `OR` (LIKE) across own columns **and relation-path columns** | `columns` (required; entries may be dotted, e.g. `categories.title`), `match` (`partial`\|`word_start`, default `partial`) |
| `MmFilter` | Subquery via MM intermediate table | `mm_table`, `mm_local_key`, `mm_foreign_key`, `mm_constraints` (derived from TCA when omitted) |

For `MmFilter`, if the options array is omitted the extension derives the MM config from TCA automatically (requires a valid `MM` key on the field).

#### Filtering related records: which filter?

Several tools touch related records; the right one depends on *what* you filter by and *where* the reference is stored:

| Goal | Filter | Example |
|------|--------|---------|
| Single-value FK's UID (stored on the row) | comparison filter on the FK column | `'color_id' => ExactFilter::class` → `?filters[color_id]=2` |
| MM membership (the related UID) | `MmFilter` | `'categories' => MmFilter::class` → `?filters[categories]=5` |
| A *column* of a related record (FK, MM or inline; one or more hops) | relation-path (dotted key) | `'categories.title' => ExactFilter::class` → `?filters[categories.title]=News` |
| An inline child's UID (no column on the parent) | relation-path with `.uid` | `'related_items.uid' => ExactFilter::class` |

`relationField.uid` also works for FK and MM relations, but prefer the FK-column filter or `MmFilter` there — they avoid the extra join to the target table (the path form additionally requires the related record to be visible). Relation-path (dotted) filters must use the bracket form `?filters[...]` — PHP rewrites dots in top-level parameter names to underscores.

#### Relation-path filters

A **dotted filter key** filters the resource by a column reached through one or more relations. The last segment is a scalar column on the deepest related table; the segments before it are relations to traverse. The declared filter performs the comparison on that column — the dot is detected automatically, so there is no extra filter class to name:

```php
use MaikSchneider\TcaApi\Filter\ExactFilter;

'filters' => [
    'color_id.name'           => ExactFilter::class,  // one FK hop     → the colour's name
    'categories.title'        => ExactFilter::class,  // one MM hop     → a category's title
    'related_items.name'      => ExactFilter::class,  // one inline hop → an inline child's name
    'categories.parent.title' => ExactFilter::class,  // two hops       → a category's parent's title
],
```

```
?filters[color_id.name]=Red
?filters[categories.title]=News
?filters[categories.parent.title]=Root
```

Any comparison filter can be the leaf (`ExactFilter` is the default; `RangeFilter`, `WordStartFilter`, `PartialFilter` compare the leaf column directly). Each hop wraps the previous result in an `IN (subquery)`, built inside-out and de-duplicating, so pagination and counts stay correct. Every hop is built through the native query builder, so each intermediate table's enable-field restrictions (`deleted`, `hidden`, start/end time, `fe_group`) apply — a hidden or deleted intermediate never leaks a match.

| Aspect | Behaviour |
|--------|-----------|
| Supported relations | Single-value `select` foreign-key relations; MM relations (incl. `type=category` and `type=group` with `MM`); `type=inline` (`foreign_field`, incl. `foreign_table_field` / `foreign_match_fields` discriminators) |
| Not supported | Non-MM group relations (comma-separated storage), and MM/group relations allowing more than one table (ambiguous target) |
| Max depth | 3 relation hops per path |
| Query syntax | **Bracket form only** — `?filters[categories.title]=News`. PHP rewrites dots in top-level parameter names to underscores, so `?categories.title=…` would not match |
| Invalid paths | Rejected at boot with `InvalidApiDefinitionException` (unknown column, unsupported/ambiguous relation, too many hops) — surfaced immediately, not on first request |

#### Default values and private filters

Two options available on any filter definition control server-side defaults and enforcement:

| Option | Type | Description |
|--------|------|-------------|
| `default` | `string` | Value applied when the filter is absent from the request URL params |
| `private` | `bool` | When `true`, the default always applies — user-supplied values are ignored; the filter is also excluded from the OpenAPI spec |

```php
'filters' => [
    // Overrideable default — applied when ?color_id is absent
    'color_id' => [ExactFilter::class, ['default' => '1']],

    // Private filter — default always applies, cannot be overridden via URL,
    // and does not appear in the OpenAPI spec
    'deleted' => [ExactFilter::class, ['default' => '0', 'private' => true]],
],
```

A private filter without a `default` has no effect (no value to enforce).

#### Filter query syntax

Plain top-level parameters are the primary style and are advertised by the `hydra:search` block:

```
?title=Hello
?color_id=1
?categories=5
```

The bracket notation is also accepted (required for `RangeFilter` sub-keys and legacy clients):

```
?filters[year][gte]=2020&filters[year][lte]=2024
?filters[price][gt]=10&filters[price][lt]=100
```

When both styles are present for the same column, the bracket form wins. Only columns declared as filters in the resource configuration are matched; other top-level parameters are ignored.

#### Search filter example

```
?q=typo3   → WHERE (title LIKE '%typo3%' OR teaser LIKE '%typo3%' OR body LIKE '%typo3%')
```

### Sorting

```php
'order' => [
    'allowed' => ['title', 'uid'],       // Columns allowed in ?order[column]=asc|desc
    'default' => ['uid' => 'asc'],       // Fallback when no order is requested
],
```

### Security

Assign an access role per operation. `list` and `show` default to `PUBLIC`; write operations default to `DISABLED` and must be explicitly configured:

```php
use MaikSchneider\TcaApi\Enum\AccessRole;

'security' => [
    // list and show are PUBLIC by default — only specify to restrict them
    'create' => AccessRole::FE_USER,    // Requires a logged-in frontend user
    'update' => AccessRole::OWNER,      // Only the record owner may update
    'delete' => AccessRole::OWNER,
],
```

#### Defaults

Write operations are **disabled by default**. You must explicitly configure security for `create`, `update`, and `delete`:

| Operation | Default |
|---|---|
| `list` | `PUBLIC` — accessible without authentication |
| `show` | `PUBLIC` — accessible without authentication |
| `create` | `DISABLED` — returns 403 until explicitly configured |
| `update` | `DISABLED` — returns 403 until explicitly configured |
| `delete` | `DISABLED` — returns 403 until explicitly configured |

#### Available roles

| Role | Description |
|---|---|
| `PUBLIC` | No authentication required |
| `DISABLED` | Always denied — used as the default for write operations |
| `FE_USER` | Any authenticated frontend user |
| `FE_GROUP` | Any FE user with at least one group; use `[AccessRole::FE_GROUP, [1,2]]` to restrict to specific group IDs |
| `BE_USER` | Any authenticated backend user |
| `BE_ADMIN` | Backend admin only |
| `OWNER` | Authenticated FE user whose UID matches the record's ownership column (see [Ownership](#ownership)) |

You can also use a callable for fully custom logic:

```php
'update' => [MyAccessChecker::class, 'checkUpdatePermission'],
// Callable receives (ServerRequestInterface $request, array $existingRecord): bool
```

### Ownership

The `ownership` section enables declarative record-level security for write operations. It pairs with `AccessRole::OWNER` in the `security` config.

```php
use MaikSchneider\TcaApi\Enum\AccessRole;

'security' => [
    'create' => AccessRole::FE_USER,
    'update' => AccessRole::OWNER,
    'delete' => AccessRole::OWNER,
],
'ownership' => [
    'column' => 'fe_user_id',   // DB column holding the owner's FE user UID
],
```

**What this does:**
- **On create** — the `fe_user_id` column is automatically set to the authenticated FE user's UID server-side. The client cannot supply this value; it is stripped from the request body regardless of the column's `groups` config.
- **On update/delete** — the record's `fe_user_id` is compared to the current FE user's UID. If they don't match the request returns **403**.

#### Separate tracking vs. auth columns

Use `setOnCreate` when you want an additional tracking column alongside the auth column:

```php
'ownership' => [
    'column'      => 'fe_user_id',      // column checked on update/delete (also written on create)
    'setOnCreate' => 'fe_creator_id',   // additional column written on create only
],
```

On create, **both** `column` and `setOnCreate` receive the FE user UID. `column` must be populated for `OWNER` auth to work on subsequent update/delete; `setOnCreate` provides an immutable "created by" audit trail in a separate DB column.

#### BE_ADMIN bypass

Backend admins bypass ownership checks by default. Set `beAdminBypass: false` to enforce ownership for admins too:

```php
'ownership' => [
    'column'        => 'fe_user_id',
    'beAdminBypass' => false,           // default: true
],
```

#### Ownership config reference

| Key | Required | Default | Description |
|---|---|---|---|
| `column` | when using `OWNER` | — | DB column holding owner UID; compared on update/delete |
| `setOnCreate` | no | same as `column` | Column auto-set on create (if different from `column`) |
| `beAdminBypass` | no | `true` | When `true`, `BE_ADMIN` skips the ownership check |

#### Behaviour notes

- `AccessRole::OWNER` without `ownership.column` configured → **403** (fail-secure)
- Unauthenticated request + `OWNER` → **403** (no FE user found)
- `setOnCreate` without a logged-in FE user → column is not set (no injection if user is null)
- Ownership columns are always stripped from client input regardless of `groups` config
- `OWNER` is only meaningful on `update` and `delete`; using it on `list`/`show` will always deny (no single record to compare against)

### Caching

Enable tag-based HTTP response caching for `list` and `show` operations per resource:

```php
'cache' => [
    'enabled'            => true,
    'lifetime'           => 3600,        // TTL in seconds (default: 86400 — 24 h)
    'parametersToIgnore' => ['preview'], // bypass cache when ?preview=… is present
],
```

#### How it works

- On a **cache miss**: the response body is stored with per-record tags (`{table}_{uid}`). Two headers are added: `X-TCA-API-Cache: MISS` and `X-Cache-Tags: articles_1,articles_2`.
- On a **cache hit**: the stored body is returned immediately with `X-TCA-API-Cache: HIT`. No handler or serializer runs.
- **Bypass**: when any parameter listed in `parametersToIgnore` is present in the request (top-level _or_ nested under `filters[…]`), the cache is skipped entirely for that request.

#### Cache invalidation

Invalidation fires automatically via a DataHandler `clearCachePostProc` hook. When a record is **saved or deleted through the TYPO3 backend**, all cached responses tagged for the affected table are flushed.

> **Note:** Write operations performed through the API itself (`create`, `update`, `delete`) do **not** trigger this hook. Cached `list`/`show` responses will remain valid until the next DataHandler operation touches the same table or the TTL expires. If your application writes records via the API and reads them back immediately, configure a short `lifetime` or disable caching for that resource.

#### Cache backend

By default the `tca_api` cache uses `Typo3DatabaseBackend` (stored in `cf_tca_api` / `cf_tca_api_tags` database tables). For production use, configure a faster backend in `config/system/additional.php`:

```php
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['tca_api'] = [
    'backend'  => \TYPO3\CMS\Core\Cache\Backend\FileBackend::class,
    'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
    'options'  => ['defaultLifetime' => 86400],
];
```

#### Multi-site note

Cache keys are scoped by resource name and query parameters but **not** by TYPO3 site. In multi-site setups where two sites use the same resource name (`articles`) with different `storagePid` or different records, their cache entries will collide. Use distinct `resourceName` values per site or disable caching until this is addressed.

#### Caching config reference

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `enabled` | `bool` | `false` | Enable caching for this resource |
| `lifetime` | `int` | `86400` | Cache TTL in seconds. Must be a positive integer |
| `parametersToIgnore` | `string[]` | `[]` | Query parameters whose presence bypasses the cache entirely |

## Write privilege model

Write operations (create, update, delete) pass through a **write privilege model** that controls execution strategy, enforces table-level access control, and logs every mutation with the actor's identity.

### Write modes

Each resource has a configurable **write mode** that determines how writes are executed:

| Mode | Value | Description |
|------|-------|-------------|
| **Acting user** | `acting_user` | **Default.** Tracks the real authenticated user (FE or BE) for audit purposes. The actor's identity is embedded in the DataHandler username. |
| **System admin** | `system_admin` | Opt-in. Uses a synthetic backend admin (`uid=0, admin=1`) with no user identity. Only for trusted, internal APIs. |

Configure per resource in the `general` section:

```php
return [
    'general' => [
        'table'        => 'tx_myext_domain_model_article',
        'resourceName' => 'articles',
        'resourceType' => 'Article',
        'operations'   => ['list', 'show', 'create', 'update', 'delete'],
        'writeMode'    => 'acting_user',   // default — tracks real user
    ],
];
```

To opt into system admin mode for an internal resource:

```php
'general' => [
    'table'        => 'tx_myext_domain_model_internal',
    'resourceName' => 'internal-records',
    'resourceType' => 'InternalRecord',
    'operations'   => ['list', 'create'],
    'writeMode'    => 'system_admin',      // explicit opt-in
],
```

> **⚠ Warning:** `system_admin` mode bypasses TYPO3 access control. Only enable for internal APIs where the calling application is fully trusted.

### Actor resolution

The write context resolves the acting user automatically from the request:

1. **Frontend user** — if `frontend.user` is present with a valid UID → `actorType = 'fe_user'`
2. **Backend user** — if `$GLOBALS['BE_USER']` is authenticated → `actorType = 'be_user'`
3. **No user** — falls back to `actorType = 'system'` with system admin mode forced

In `acting_user` mode, the DataHandler's internal username encodes the real actor for traceability:

```
_tca_api[fe_user:42:johndoe]
_tca_api[be_user:1:admin]
```

### Table access control

A built-in table access control layer prevents writes to security-sensitive system tables. This applies regardless of write mode.

#### Denied tables (built-in)

The following tables are **always blocked** from API writes:

| Table | Reason |
|-------|--------|
| `be_users` | Backend user credentials |
| `be_groups` | Backend permission groups |
| `be_sessions` | Active backend sessions |
| `fe_sessions` | Active frontend sessions |
| `sys_filemounts` | File system mount points |
| `sys_be_shortcuts` | Backend user shortcuts |
| `sys_action` | System actions |
| `sys_log` | System audit log |

#### Custom allow/deny lists

Extend the table access control via `Services.yaml`:

```yaml
services:
  MaikSchneider\TcaApi\Security\TableAccessControl:
    arguments:
      # Only these tables are writable (empty = all non-denied)
      $allowList:
        - tx_myext_domain_model_article
        - tx_myext_domain_model_comment
      # Additional tables to deny (merged with built-in deny list)
      $denyList:
        - pages
        - tt_content
```

**Precedence:** deny list always wins over allow list. A table in both lists is denied.

### Audit logging

Every write operation is logged via PSR-3 with structured context data:

```
INFO TCA_API write operation
  operation: create
  table: tx_myext_domain_model_article
  uid: NEW_primary
  actor_type: fe_user
  actor_uid: 42
  actor_username: johndoe
  write_mode: acting_user
```

Denied writes are logged at `WARNING` level:

```
WARNING TCA_API write denied
  operation: write
  table: be_users
  actor_type: fe_user
  actor_uid: 42
  actor_username: johndoe
  write_mode: acting_user
  reason: Table blocked by access control policy
```

Logs are written to TYPO3's logging framework and can be routed to any PSR-3 compatible handler.

## Validation

Configure validators per column. Validators are also **auto-derived from TCA**
(e.g. `config.max` → `maxLength`, `config.required` → `required`) as a gap-fill —
explicit validators always win.

| Type        | Parameters         | Description                 |
|-------------|--------------------|-----------------------------|
| `maxLength` | `max` (int)        | Maximum string length       |
| `minLength` | `min` (int)        | Minimum string length       |
| `minValue`  | `min` (int/float)  | Minimum numeric value       |
| `maxValue`  | `max` (int/float)  | Maximum numeric value       |
| `minItems`  | `min` (int)        | Minimum number of items     |
| `maxItems`  | `max` (int)        | Maximum number of items     |
| `regex`     | `pattern` (string) | PCRE pattern to match       |

```php
'rating' => [
    'groups'     => ['list', 'show', 'create', 'update'],
    'validators' => [
        ['type' => 'minValue', 'min' => 1],
        ['type' => 'maxValue', 'max' => 5],
    ],
],
```

### Custom validators

When the built-in types are not enough, reference a class-string `type`
implementing `MaikSchneider\TcaApi\Validation\ValidatorInterface`. Implementations
are auto-discovered via the `tca_api.validator` DI tag — no `Services.yaml` entry
is needed with `autoconfigure`.

```php
'iban' => [
    'groups'     => ['list', 'show', 'create', 'update'],
    'validators' => [
        ['type' => \Acme\Validator\IbanValidator::class, 'options' => ['country' => 'DE']],
    ],
],
```

```php
use MaikSchneider\TcaApi\Validation\ValidationContext;
use MaikSchneider\TcaApi\Validation\ValidatorInterface;
use MaikSchneider\TcaApi\Validation\Violation;

final class IbanValidator implements ValidatorInterface
{
    public function validate(ValidationContext $context): array
    {
        if (is_valid_iban((string)$context->value, $context->option('country'))) {
            return [];
        }

        return [new Violation('Not a valid IBAN.', 'IBAN')];
    }
}
```

The `ValidationContext` exposes the submitted `value`, the `column`/`table`,
per-validator `options` (via `option($key, $default)`), the full request `body`
(for cross-field rules), the `partial` (PATCH) flag, and the resource
`resourceConfig`. Each returned `Violation` carries a `message` and a
machine-readable `code`; its `propertyPath` defaults to the validated column but
can target another field. Built-in and custom validators compose freely on the
same column. See the [Validation documentation](Documentation/Configuration/Validation.rst).

Validation failures return **422 Unprocessable Entity**:

```json
{
    "@context": "http://www.w3.org/ns/hydra/context.jsonld",
    "@type": "hydra:Error",
    "hydra:title": "Validation Failed",
    "hydra:description": "1 validation error(s)",
    "violations": [
        {
            "propertyPath": "title",
            "message": "Field 'title' is required.",
            "code": "REQUIRED"
        }
    ]
}
```

## File uploads

Columns with an `upload` key accept files via `multipart/form-data` on `POST`, `PUT`, and `PATCH` endpoints. Files are stored in a FAL storage folder and attached as `sys_file_reference` records.

### Configuration

Add an `upload` key to any `type=file` TCA column:

```php
'columns' => [
    'profile_photo' => [
        'groups' => ['list', 'show', 'create', 'update'],
        'upload' => [
            'folder'      => '1:/user_upload/',    // required — FAL storage ref
            'maxSize'     => '5M',                 // optional — int bytes or 5M/100K/1G
            'duplication' => 'rename',             // optional — rename|replace|cancel
            'filenameMask' => '{timestamp}_{unique}{ext}',  // optional — see below
        ],
    ],
],
```

Allowed file extensions are **not** set here — they come from the TCA column's `allowed` config (e.g. `'allowed' => 'jpg,jpeg,png'`).

### Upload config reference

| Key | Required | Default | Description |
|-----|----------|---------|-------------|
| `folder` | Yes | — | FAL storage reference, e.g. `1:/uploads/`. Must start with a storage UID followed by `:/` |
| `maxSize` | No | unlimited | Max file size. Accepts an integer (bytes) or a string: `5M`, `100K`, `1G` |
| `duplication` | No | `rename` | Collision handling: `rename` (add suffix), `replace` (overwrite), `cancel` (reject) |
| `filenameMask` | No | *(original filename)* | Filename template with placeholders: `{name}` (base name), `{extension}` (ext without dot), `{ext}` (ext with dot), `{contentHash}` (MD5 of file), `{nameHash}` (MD5 of name), `{timestamp}` (Unix time), `{unique}` (random ID). Example: `{timestamp}_{unique}{ext}` → `1714000000_abc123.jpg` |

### Sending requests

```bash
# Create with a file
curl -X POST https://example.com/_api/articles \
  -H "Cookie: fe_typo_user=..." \
  -F "title=My Article" \
  -F "profile_photo=@/path/to/photo.jpg"

# PATCH — replace file only; title is preserved
curl -X PATCH https://example.com/_api/articles/1 \
  -H "Cookie: fe_typo_user=..." \
  -F "profile_photo=@/path/to/new-photo.jpg"

# PATCH — text only; existing file reference is preserved
curl -X PATCH https://example.com/_api/articles/1 \
  -H "Cookie: fe_typo_user=..." \
  -F "title=Updated Title"

# Multiple files on one field
curl -X POST https://example.com/_api/articles \
  -F "title=My Article" \
  -F "downloads[]=@/path/to/file1.pdf" \
  -F "downloads[]=@/path/to/file2.pdf"
```

### Validation errors

Upload violations return **422 Unprocessable Entity**:

```json
{
    "@context": "http://www.w3.org/ns/hydra/context.jsonld",
    "@type": "hydra:Error",
    "hydra:title": "Validation Failed",
    "hydra:description": "1 validation error(s)",
    "violations": [
        {
            "propertyPath": "profile_photo",
            "message": "The file type is not allowed.",
            "code": "UPLOAD_MIME_TYPE"
        }
    ]
}
```

| Code | Meaning |
|------|---------|
| `UPLOAD_ERROR` | PHP transport error (upload aborted, temp dir issue, etc.) |
| `UPLOAD_MIME_TYPE` | MIME type not permitted for this column |
| `UPLOAD_MAX_SIZE` | File exceeds the configured `maxSize` |
| `UPLOAD_EXTENSION` | File extension not in the TCA `allowed` list |

## Relations

Relations are resolved automatically from the TCA schema. By default, related records are serialized as **plain IRI strings**:

```json
"color_id": "/_api/colors/1",
"categories": ["/_api/categories/5", "/_api/categories/8"]
```

This format is compatible with API Platform Admin and other Hydra clients that resolve IRIs on demand.

### Embedding related records

Add `'embed' => true` to a column to inline the full related record instead of an IRI string:

```php
'columns' => [
    'color_id'   => ['groups' => ['list', 'show'], 'embed' => true],  // explicit mode
    'categories' => ['groups' => ['list', 'show'], 'embed' => true],
],
```

In default mode, `embed` alone is enough — it does not trigger explicit mode:

```php
'columns' => [
    'color_id' => ['embed' => true],  // default mode: all columns exposed, color embedded
],
```

Response with embedded data:

```json
"color": {
    "@id": "/_api/colors/1", "@type": "Color", "uid": 1,
    "name": "Red", "hex": "#ff0000"
},
"categories": [
    { "@id": "/_api/sys-categories/5", "@type": "SysCategory", "uid": 5, "title": "News" }
]
```

Control recursion depth with `'embed' => ['depth' => 2]`. The default depth is 1.

The related resource must be registered in the `ApiRegistry` for embedding to work.

### Supported relation types

| TCA type             | Default output                            | Embedding |
|----------------------|-------------------------------------------|-----------|
| `select` / `group`   | UID list (`1,2,3`) — single table → IRI strings | Yes |
| `select` / `group`   | Prefixed list (`table_uid`) — multi-table → IRI strings | No |
| `inline` / `select`  | `foreign_field` back-reference → IRI strings | Yes  |
| Any + `MM`           | Intermediate MM table → IRI strings       | Yes       |
| `type=group` + `MM`  | Column holds count, relations in MM → IRI strings | Yes |

### Creating related records on write

On POST, PUT, and PATCH you can create new related records inline by passing an assoc array instead of a UID. Three forms are accepted per relation field:

```json
{ "color_id": 1 }                          // existing record — link by UID
{ "color_id": { "name": "Red" } }          // new record — created atomically
{ "categories": [1, { "title": "New" }] }  // mixed — link existing + create new
```

These forms work across the supported TCA relation types, with one important limitation for `group` fields noted below:

| TCA type | Example |
|---|---|
| `select` + single value | `"color_id": { "name": "Red" }` |
| `category` / `select` + MM | `"categories": [1, { "title": "New" }]` |
| `group` (single-table `allowed`) | `"tags": [3, { "name": "New Tag" }]` |
| `inline` + `foreign_field` | `"related_items": [{ "name": "Child" }]` |

For `type=group`, inline creation of new related records is only supported when `allowed` contains exactly one table. Multi-table `group` relations still support linking existing records by UID, but not creating new related records inline.
**Atomicity:** all records (parent + new children) are created in a single DataHandler call, so cross-references resolve correctly and no partial writes occur.

**Ownership:** if the related table has an `ownership` config, the ownership column is automatically injected and cannot be overridden by the client.

**Security gate:** new sub-records can only be created for tables that have their own entry in ApiRegistry. Objects for unregistered foreign tables are silently skipped; existing UID references still work.

#### Inline relations (`type=inline` + `foreign_field`)

Inline children carry a back-pointer column to the parent (`foreign_field`). The extension sets this column automatically via DataHandler's `writeForeignField` mechanism — you do not need to include the parent UID in the child object:

```json
POST /_api/articles
{
  "title": "My Article",
  "images": [
    { "caption": "Photo 1" },
    { "caption": "Photo 2" }
  ]
}
```

On PATCH, new inline children are **appended**; existing children are left untouched.

## Virtual properties

Virtual properties are computed fields appended to the serialized output. They appear after all real columns (and after column callbacks) and can be driven by a **callable**, a **column processor**, or a **file/image column reference**.

A `callback` is not mutually exclusive with a processor or file reference: when both are present the processor/file produces the base value and the callback runs **last** as a final transform. A virtual-property callback always runs — after all columns, column callbacks, and its own base value — so it can read any column or earlier virtual property from `$serializedRow`.

### Callable

```php
'virtualProperties' => [
    'displayName' => [
        'callback' => [DisplayNameCallable::class, 'build'],
        'groups'   => ['list', 'show'],
    ],
],
```

The callable receives `(array $serializedRow, array $rawRow)` and returns any serializable value. `$serializedRow` reflects columns already serialized in this request (including their callbacks); `$rawRow` is the raw DB record. When a `processor` or file `column` is also set, the callback receives that resolved base value under the property's key in `$serializedRow` and may transform it.

### Column processor

```php
'virtualProperties' => [
    'titleUppercase' => [
        'processor' => UppercaseProcessor::class,
        'groups'    => ['list', 'show'],
    ],
],
```

The processor implements `ColumnProcessorInterface::process(mixed $value, array $config, array $context)`. Without a `column` key the value passed is `null`.

### Referencing an existing column

Add a `column` key to source the virtual property's value from an existing DB column:

```php
'virtualProperties' => [
    'titleCopy' => [
        'column'    => 'title',
        'processor' => MyProcessor::class,
        'groups'    => ['list', 'show'],
    ],
],
```

The processor receives the column's raw DB value instead of `null`. For **file/image columns** the file references are fetched automatically and the result of the virtual property's own file processor is returned — this lets you expose the same image at different sizes per operation:

```php
'virtualProperties' => [
    'profile_photo_thumb' => [
        'column'  => 'profile_photo',     // existing type=file column
        'image'   => [
            'maxWidth'    => 200,
            'maxHeight'   => 200,
            'cropVariant' => 'default',   // single variant → flat publicUrl/width/height
        ],
        'groups'  => ['list'],            // small thumb in list only
    ],
    'profile_photo_large' => [
        'column'  => 'profile_photo',
        'image'   => [
            'maxWidth'  => 1600,
            'maxHeight' => 1200,
            // no cropVariant → all variants returned in cropVariants map
        ],
        'groups'  => ['show'],            // full size in show only
    ],
],
```

The virtual property uses its **own** `image` config — the referenced column's original config is ignored.

#### Image processor config

The `image` key is an array with the following options. All are optional:

| Key | Type | Description |
|-----|------|-------------|
| `width` | `string` | Target width. Accepts plain integer (`"400"`), crop-scale (`"400c"`), or scale-down-only (`"400m"`) |
| `height` | `string` | Target height — same notation as `width` |
| `minWidth` | `int` | Minimum width in pixels (positive integer) |
| `minHeight` | `int` | Minimum height in pixels (positive integer) |
| `maxWidth` | `int` | Maximum width in pixels (positive integer) |
| `maxHeight` | `int` | Maximum height in pixels (positive integer) |
| `cropVariant` | `string` | Crop variant identifier. When set, only that variant is processed and the URL is inlined as `publicUrl` (no `cropVariants` key). When omitted, all variants are returned as a `cropVariants` map |
| `fileExtension` | `string` | Target file extension for format conversion (e.g. `'webp'`) |
| `absolute` | `bool` | Force an absolute URL. Default: `false` |

**Output modes:**

Without `cropVariant` (or `cropVariant` omitted) — all variants returned:
```json
{
    "publicUrl": "/fileadmin/hero.jpg",
    "mimeType": "image/jpeg",
    "cropVariants": {
        "default": { "publicUrl": "/fileadmin/_processed_/hero_c.jpg", "width": 1024, "height": 512 },
        "mobile":  { "publicUrl": "/fileadmin/_processed_/hero_m.jpg", "width": 375,  "height": 200 }
    }
}
```

With `cropVariant` set — single variant inlined:
```json
{
    "publicUrl": "/fileadmin/_processed_/hero_c.jpg",
    "width": 1024,
    "height": 512,
    "mimeType": "image/jpeg"
}
```

### Visibility gate

Virtual properties respect the same serialization groups as regular columns. When any column has a `groups` key (explicit mode), virtual properties without `groups` are excluded:

```php
'virtualProperties' => [
    'displayName' => [
        'callback' => [DisplayNameCallable::class, 'build'],
        'groups'   => ['list', 'show'],  // required in explicit mode
    ],
    'adminNote' => [
        'callback' => [AdminNoteCallable::class, 'build'],
        'groups'   => ['show'],          // only in show, not list
    ],
],
```

## Userinfo endpoint

A userinfo endpoint exposes the **currently authenticated FE user's own record** without requiring a UID in the URL. Set `'type' => 'userinfo'` in the `general` section:

```php
ApiRegistry::register('me', [
    'general' => [
        'type'         => 'userinfo',
        'table'        => 'fe_users',
        'resourceName' => 'me',
        'resourceType' => 'FeUser',
    ],
    'columns' => [
        'username'   => ['groups' => ['show']],
        'email'      => ['groups' => ['show']],
        'name'       => ['groups' => ['show']],
        'first_name' => ['groups' => ['show']],
        'last_name'  => ['groups' => ['show']],
    ],
]);
```

```
GET /_api/me   → Returns the record of the logged-in FE user
```

**Behaviour:**

- Only `GET` is allowed — write operations are not supported on userinfo endpoints.
- Returns **403** if no FE user is authenticated.
- All column features work as normal: `embed`, `virtualProperties`, column processors (see [Virtual properties](#virtual-properties)).
- The `security` and `operations` keys are ignored — access is always tied to FE user authentication.

## Events

The extension dispatches PSR-14 events throughout the request lifecycle:

| Event                  | Fired                                     | Use case                                      |
|------------------------|-------------------------------------------|-----------------------------------------------|
| `BeforeOperationEvent` | After access check, before handler        | Abort operation, modify request               |
| `AfterOperationEvent`  | After handler, before response            | Add computed fields, transform response data  |
| `BeforeWriteEvent`     | Before DataHandler create/update/delete   | Validate or modify data before persistence    |
| `AfterWriteEvent`      | After DataHandler operations              | Trigger side effects (cache clear, logging)   |

Example listener registration in `Configuration/Services.yaml`:

```yaml
services:
  My\Extension\EventListener\EnrichArticleListener:
    tags:
      - name: event.listener
          identifier: 'my-extension/enrich-article'
          event: MaikSchneider\TcaApi\Event\AfterOperationEvent
```

## Custom operation handlers

The dispatcher routes each request through a **handler pipeline** — a prioritised list of objects that implement `OperationHandlerInterface`. Built-in handlers cover `list`, `show`, `create`, `update`, `delete`, and `userinfo`. Third-party extensions can add new operation types or replace built-in behaviour by registering their own handlers.

### Interface

```php
<?php

use MaikSchneider\TcaApi\OperationHandler\OperationHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

interface OperationHandlerInterface
{
    public function supports(ServerRequestInterface $request, string $operation, array $config): bool;
    public function handle(ServerRequestInterface $request, array $config): ResponseInterface;
    public function getPriority(): int;
}
```

### Request attributes

Before the handler loop, the dispatcher sets the following attributes on the PSR-7 request:

| Attribute | Type | Description |
|---|---|---|
| `tca_api.uid` | `int\|null` | UID from the URL segment |
| `tca_api.operation` | `string` | Resolved operation name |
| `tca_api.fields` | `array` | `?fields[]=…` sparse-fieldset param |
| `tca_api.page` | `int` | Pagination page (≥ 1) |
| `tca_api.items_per_page` | `int` | Items per page (clamped to `maxItemsPerPage` when configured) |
| `tca_api.filters` | `array` | Merged filter values from `?filters[…]=…` and top-level `?column=…` params |
| `tca_api.order` | `array` | Raw `?order[…]=asc\|desc` params |
| `tca_api.partial` | `bool` | `true` for PATCH (partial update) |

### Writing a custom handler

```php
<?php

use MaikSchneider\TcaApi\OperationHandler\OperationHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure(public: true)]
class PublishHandler implements OperationHandlerInterface
{
    public function supports(ServerRequestInterface $request, string $operation, array $config): bool
    {
        return $operation === 'publish'
            && ($config['general']['table'] ?? '') === 'tx_myext_domain_model_article';
    }

    public function handle(ServerRequestInterface $request, array $config): ResponseInterface
    {
        $uid = (int)$request->getAttribute('tca_api.uid');
        // … publish logic …
    }

    public function getPriority(): int
    {
        return 10;
    }
}
```

### Registering handlers

Register handlers in your extension's `ext_localconf.php`. The dispatcher iterates handlers **highest priority first** and dispatches to the first match, so setting a higher priority than the built-in `10` overrides a built-in handler for a given operation.

```php
use MaikSchneider\TcaApi\Registry\HandlerRegistry;
use My\Extension\OperationHandler\PublishHandler;

// New operation type — priority 10 (default)
HandlerRegistry::register(PublishHandler::class);

// Override a built-in handler — checked before the built-in (priority 20 > 10)
HandlerRegistry::register(MyCustomShowHandler::class, priority: 20);
```

The `HandlerRegistry` uses TYPO3's DI container via `GeneralUtility::makeInstance()`, so constructor dependencies are injected automatically. The `#[Autoconfigure(public: true)]` attribute on the class is required for the container to expose the service.

## Custom filters

Every filter strategy is a class that implements `FilterInterface`. The extension discovers all implementations automatically via Symfony DI — no `Services.yaml` registration is needed.

### Interface

```php
<?php

use MaikSchneider\TcaApi\Filter\FilterContext;
use MaikSchneider\TcaApi\Filter\FilterInterface;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

final class PublishedAfterFilter implements FilterInterface
{
    public function apply(QueryBuilder $qb, FilterContext $context): void
    {
        $qb->andWhere($qb->expr()->gte(
            $context->column,
            $qb->createNamedParameter((int)$context->value),
        ));
    }
}
```

`FilterContext` is a typed readonly value object. Its properties are:

| Property | Type | Description |
|----------|------|-------------|
| `value` | `mixed` | Filter value from the request query string |
| `table` | `string` | Resource table name |
| `column` | `string` | Column name this filter is applied to |
| `options` | `array` | Filter-specific options from the resource config |
| `request` | `ServerRequestInterface\|null` | PSR-7 request — available in HTTP context; `null` in unit tests |
| `resourceConfig` | `ApiDefinition\|null` | Full resource config — available in HTTP context; `null` in unit tests |

Use `$context->option('key', $default)` to read from `options` with a fallback default.

### Using the custom filter

Declare it in the resource config using the class name directly:

```php
use My\Extension\Filter\PublishedAfterFilter;

'filters' => [
    'publish_date' => PublishedAfterFilter::class,
],
```

To pass extra config to the filter, use the two-element array form:

```php
'filters' => [
    'publish_date' => [
        PublishedAfterFilter::class,
        ['threshold' => 30],   // available via $context->option('threshold')
    ],
],
```

That's all. The class is auto-tagged and auto-wired — no `ext_localconf.php` or `Services.yaml` changes required.

## Development

This extension uses DDEV for local development, see [CONTRIBUTING.md](CONTRIBUTING.md) for setup instructions.
