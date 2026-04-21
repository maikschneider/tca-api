<div align="center">

# `TCA API` — REST API for TYPO3

![Extension icon](Resources/Public/Icons/Extension.svg)

[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![TYPO3](https://img.shields.io/badge/TYPO3-13.4%20%7C%2014-orange.svg)](https://typo3.org/)
[![PHP](https://img.shields.io/badge/PHP-%5E8.2-777BB4.svg)](https://php.net/)
[![codecov](https://codecov.io/gh/maikschneider/tca-api/graph/badge.svg?token=J2CNGVXEX1)](https://codecov.io/gh/maikschneider/tca-api)

`TCA API` is a TYPO3 extension that exposes database tables as **Hydra JSON-LD** resources through a configuration-driven REST API. Define which tables, columns, and operations to expose — the extension handles routing, serialization, validation, pagination, and access control.

</div>

> **State:** Alpha (0.1.0)

## Features

- **Full CRUD** — List, show, create, update (PUT & PATCH), and delete operations
- **Hydra JSON-LD** — Responses follow the [Hydra](https://www.hydra-cg.com/) specification (`application/ld+json`)
- **Configuration-driven** — Expose tables by registering a PHP configuration array; no custom controllers needed
- **Serialization groups** — Use `groups` to control which columns appear per operation (`list`, `show`, `create`, `update`)
- **Filtering** — Exact, partial, word-start, range, full-text search, and many-to-many filter strategies via query parameters; configurable defaults and private (non-overrideable) filters; extensible via `FilterInterface`
- **Sorting** — Configurable allowed sort columns with defaults
- **Pagination** — Offset-based pagination with Hydra `PartialCollectionView` links
- **Validation** — Required, maxLength, minLength, and regex validators with structured 422 error responses
- **Access control** — Per-operation roles: `PUBLIC`, `FE_USER`, `FE_GROUP`, `BE_USER`, `BE_ADMIN`, `OWNER` (record-level ownership), or custom callables
- **Relation handling** — Shallow stubs or fully embedded related records (configurable depth); create new related records inline on POST/PUT/PATCH
- **Userinfo endpoint** — Expose the authenticated FE user's own record at a configurable URL
- **OpenAPI + Swagger UI** — Auto-generated OpenAPI 3.0 spec and interactive Swagger UI served directly from the API prefix
- **PSR-14 events** — Hook into the request lifecycle with Before/AfterOperation and Before/AfterWrite events
- **TYPO3 DataHandler** — Write operations use TYPO3's DataHandler for safe, consistent data manipulation
- **Extensible handler pipeline** — Register custom operation handlers or override built-in ones from any extension

## Requirements

| Dependency | Version         |
|------------|-----------------|
| PHP        | ^8.2            |
| TYPO3      | ^13.4 \|\| ^14.0 |

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
| `tca_api.apiSpecTitle` | `TCA API` | Title shown in the OpenAPI spec and Swagger UI |
| `tca_api.apiSpecDescription` | *(empty)* | Description shown in the OpenAPI spec and Swagger UI |
| `tca_api.apiSpecVersion` | `1.0.0` | Version string in the OpenAPI spec info block |
| `tca_api.swaggerUiEnabled` | `PUBLIC` | Who may access the Swagger UI (`PUBLIC`, `FE_USER`, `BE_USER`, `BE_ADMIN`, `NONE`) |
| `tca_api.corsEnabled` | `false` | Enable CORS support — adds headers to all API responses and handles `OPTIONS` preflight requests with a `204` response |
| `tca_api.corsOrigin` | `*` | Value for `Access-Control-Allow-Origin` |
| `tca_api.corsAllowCredentials` | `false` | Include `Access-Control-Allow-Credentials: true` header (required when the client sends cookies or `Authorization` headers cross-origin) |

## Quick start

### 1. Create the resource configuration

Place a PHP file in `Configuration/TcaApi/` inside any active TYPO3 extension. **No manual registration is needed** — the extension auto-discovers all `*.php` files from every active package's `Configuration/TcaApi/` directory at boot time and caches the result.

**Zero-config (sane defaults):** omit `columns` entirely and all non-system TCA columns are auto-exposed for read and write:

```php
use MaikSchneider\TcaApi\Enum\AccessRole;

return [
    'general' => [
        'table'        => 'tx_myext_domain_model_article',
        'resourceName' => 'articles',
        'resourceType' => 'Article',
        'operations'   => ['list', 'show', 'create', 'update', 'delete'],
        'itemsPerPage' => 20,
        'storagePid'   => 1,
    ],
    'security' => [
        'list'   => AccessRole::PUBLIC,
        'show'   => AccessRole::PUBLIC,
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

## OpenAPI spec & Swagger UI

The extension generates a live **OpenAPI 3.0 JSON spec** from the registered resources and exposes two additional endpoints:

| Endpoint | Description |
|---|---|
| `{apiPrefix}openapi.json` | Machine-readable OpenAPI spec (e.g. `/_api/openapi.json`) |
| `{apiPrefix}swagger-ui` | Interactive Swagger UI (e.g. `/_api/swagger-ui`) |

Access to both endpoints is controlled by the `tca_api.openApiExposed` and `tca_api.swaggerUiEnabled` site settings respectively. Both default to `PUBLIC`.

## Configuration reference

### General

| Key               | Description                                      |
|-------------------|--------------------------------------------------|
| `table`           | TYPO3 database table name                        |
| `resourceName`    | URL slug used in `/_api/{resourceName}`           |
| `resourceType`    | JSON-LD `@type` value                            |
| `type`            | Set to `'userinfo'` to create a [userinfo endpoint](#userinfo-endpoint) |
| `operations`      | Array of enabled operations: `list`, `show`, `create`, `update`, `delete` |
| `itemsPerPage`    | Default page size for list operations            |
| `maxItemsPerPage` | Upper limit for `itemsPerPage`; when set, the requested page size is clamped to this value. No limit when omitted |
| `storagePid`      | Page ID for newly created records                |

### Column visibility

TCA API has two visibility modes. The mode is **auto-detected** per resource:

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
| `embed`        | `true` or `['depth' => N]` — inline related records instead of shallow stubs |
| `resourceName` | Override related resource name for relation columns |
| `processor`    | Column processor class (does **not** trigger explicit mode) |
| `validators`   | Array of validation rules (see [Validation](#validation)) |

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
    'title'  => ExactFilter::class,            // ?filters[title]=Foo
    'name'   => PartialFilter::class,          // ?filters[name]=oo  → LIKE %oo%
    'slug'   => WordStartFilter::class,        // ?filters[slug]=Fo  → LIKE Fo%
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
| `RangeFilter` | Numeric operators on a column | `value` must be `['gte'=>…, 'lte'=>…, 'gt'=>…, 'lt'=>…]` |
| `SearchFilter` | `OR` across multiple columns (LIKE) | `columns` (required), `match` (`partial`\|`word_start`, default `partial`) |
| `MmFilter` | Subquery via MM intermediate table | `mm_table`, `mm_local_key`, `mm_foreign_key`, `mm_constraints` (derived from TCA when omitted) |

For `MmFilter`, if the options array is omitted the extension derives the MM config from TCA automatically (requires a valid `MM` key on the field).

#### Default values and private filters

Two options available on any filter definition control server-side defaults and enforcement:

| Option | Type | Description |
|--------|------|-------------|
| `default` | `string` | Value applied when the filter is absent from the request URL params |
| `private` | `bool` | When `true`, the default always applies — user-supplied values are ignored; the filter is also excluded from the OpenAPI spec |

```php
'filters' => [
    // Overrideable default — applied when ?filters[color_id] is absent
    'color_id' => [ExactFilter::class, ['default' => '1']],

    // Private filter — default always applies, cannot be overridden via URL,
    // and does not appear in the OpenAPI spec
    'deleted' => [ExactFilter::class, ['default' => '0', 'private' => true]],
],
```

A private filter without a `default` has no effect (no value to enforce).

#### Range filter example

```
?filters[year][gte]=2020&filters[year][lte]=2024
?filters[price][gt]=10&filters[price][lt]=100
```

#### Search filter example

```
?filters[q]=typo3   → WHERE (title LIKE '%typo3%' OR teaser LIKE '%typo3%' OR body LIKE '%typo3%')
```

### Sorting

```php
'order' => [
    'allowed' => ['title', 'uid'],       // Columns allowed in ?order[column]=asc|desc
    'default' => ['uid' => 'asc'],       // Fallback when no order is requested
],
```

### Security

Assign an access role per operation:

```php
use MaikSchneider\TcaApi\Enum\AccessRole;

'security' => [
    'list'   => AccessRole::PUBLIC,     // No authentication required
    'show'   => AccessRole::PUBLIC,
    'create' => AccessRole::FE_USER,    // Requires a logged-in frontend user
    'update' => AccessRole::OWNER,      // Only the record owner may update
    'delete' => AccessRole::OWNER,
],
```

#### Available roles

| Role | Description |
|---|---|
| `PUBLIC` | No authentication required |
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

## Validation

Configure validators per column:

| Type        | Parameters     | Description              |
|-------------|----------------|--------------------------|
| `maxLength` | `max` (int)    | Maximum string length    |
| `minLength` | `min` (int)    | Minimum string length    |
| `regex`     | `pattern` (string) | PCRE pattern to match |

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

## Relations

Relations are resolved automatically from the TCA schema. The default is a **shallow stub** containing only `@id`, `@type`, and `uid`:

```json
"color": { "@id": "/_api/colors/1", "@type": "Color", "uid": 1 }
```

### Embedding related records

Add `'embed' => true` to a column to inline the full related record instead of a stub:

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

| TCA type             | Storage format                        | Embedding |
|----------------------|---------------------------------------|-----------|
| `select` / `group`   | UID list (`1,2,3`) — single table     | Yes       |
| `select` / `group`   | Prefixed list (`table_uid`) — multi-table | Stubs only |
| `inline` / `select`  | `foreign_field` back-reference        | Yes       |
| Any + `MM`           | Intermediate MM table                 | Yes       |
| `type=group` + `MM`  | Column holds count, relations in MM   | Yes       |

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

Virtual properties are computed fields appended to the serialized output. They appear after all real columns and can be driven by a **callable** or a **column processor**.

### Callable

```php
'virtualProperties' => [
    'displayName' => [
        'callback' => [DisplayNameCallable::class, 'build'],
        'groups'   => ['list', 'show'],
    ],
],
```

The callable receives `(array $serializedRow, array $rawRow)` and returns any serializable value. `$serializedRow` reflects columns already serialized in this request; `$rawRow` is the raw DB record.

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
        'column'    => 'profile_photo',   // existing type=file column
        'processor' => FileProcessor::class,
        'maxWidth'  => 200,
        'maxHeight' => 200,
        'groups'    => ['list'],          // small thumb in list only
    ],
    'profile_photo_large' => [
        'column'    => 'profile_photo',
        // no processor → ImageProcessor with cropVariants (default)
        'maxWidth'  => 1600,
        'maxHeight' => 1200,
        'groups'    => ['show'],          // full size in show only
    ],
],
```

The virtual property uses its **own** processor and config keys (`maxWidth`, `maxHeight`, etc.) — the referenced column's original config is ignored.

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
| `tca_api.filters` | `array` | Raw `?filters[…]=…` params |
| `tca_api.order` | `array` | Raw `?order[…]=asc\|desc` params |
| `tca_api.partial` | `bool` | `true` for PATCH (partial update) |

### Writing a custom handler

```php
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
use MaikSchneider\TcaApi\Filter\FilterInterface;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

final class PublishedAfterFilter implements FilterInterface
{
    public function getStrategy(): string
    {
        return 'published_after';
    }

    public function apply(QueryBuilder $qb, string $column, array $filterConfig): void
    {
        $qb->andWhere($qb->expr()->gte(
            $column,
            $qb->createNamedParameter((int)$filterConfig['value']),
        ));
    }
}
```

The `$filterConfig` array always contains:

| Key | Description |
|-----|-------------|
| `value` | Filter value from the request query string |
| `strategy` | Strategy name as declared in the resource config |
| `_table` | Resource table name |
| `_column` | Column name (same as the `$column` parameter) |
| `_request` | `ServerRequestInterface` — access query params, auth context, headers |
| `_resourceConfig` | Full resource config (general, columns, filters, order, …) |

Plus any additional keys declared in the resource's filter config entry.

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
        ['threshold' => 30],   // available in $filterConfig['threshold']
    ],
],
```

That's all. The class is auto-tagged and auto-wired — no `ext_localconf.php` or `Services.yaml` changes required.

## Development

### Static analysis & linting

```bash
# Run all checks
composer sca

# Individual checks
composer php:lint          # PHP syntax
composer php:fixer         # Code style (php-cs-fixer)
composer php:stan          # PHPStan static analysis
```

### Testing

Tests use the TYPO3 Testing Framework with functional test cases:

```bash
# Run all tests
vendor/bin/phpunit -c phpunit.xml
```

## License

GPL-2.0-or-later — see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).
