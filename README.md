# TCA API — REST API for TYPO3 based on TCA

[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![TYPO3](https://img.shields.io/badge/TYPO3-13.4%20%7C%2014-orange.svg)](https://typo3.org/)
[![PHP](https://img.shields.io/badge/PHP-%5E8.2-777BB4.svg)](https://php.net/)

TCA API is a TYPO3 extension that exposes database tables as **Hydra JSON-LD** resources through a configuration-driven REST API. Define which tables, columns, and operations to expose — the extension handles routing, serialization, validation, pagination, and access control.

> **State:** Alpha (0.1.0)

## Features

- **Full CRUD** — List, show, create, update (PUT & PATCH), and delete operations
- **Hydra JSON-LD** — Responses follow the [Hydra](https://www.hydra-cg.com/) specification (`application/ld+json`)
- **Configuration-driven** — Expose tables by registering a PHP configuration array; no custom controllers needed
- **Filtering** — Exact, partial, word-start, and many-to-many filter strategies via query parameters
- **Sorting** — Configurable allowed sort columns with defaults
- **Pagination** — Offset-based pagination with Hydra `PartialCollectionView` links
- **Validation** — Required, maxLength, minLength, and regex validators with structured 422 error responses
- **Access control** — Per-operation roles: `PUBLIC`, `FE_USER`, `BE_USER`, `BE_ADMIN`, or custom callables
- **Relation handling** — Automatic shallow embedding of hasOne and manyToMany relations
- **PSR-14 events** — Hook into the request lifecycle with Before/AfterOperation and Before/AfterWrite events
- **TYPO3 DataHandler** — Write operations use TYPO3's DataHandler for safe, consistent data manipulation

## Requirements

| Dependency | Version         |
|------------|-----------------|
| PHP        | ^8.2            |
| TYPO3      | ^13.4 \|\| ^14.0 |

## Installation

```bash
composer require maikschneider/tca-api
```

## Quick start

### 1. Register a resource

In your extension's `ext_localconf.php`:

```php
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

ApiRegistry::register(
    'articles',
    require ExtensionManagementUtility::extPath('my_extension') . 'Configuration/TcaApi/Articles.php',
);
```

### 2. Create the resource configuration

Create `Configuration/TcaApi/Articles.php` in your extension:

```php
use MaikSchneider\TcaApi\Enum\AccessRole;

return [
    'general' => [
        'table' => 'tx_myext_domain_model_article',
        'resourceName' => 'articles',
        'resourceType' => 'Article',
        'operations' => ['list', 'show', 'create', 'update', 'delete'],
        'itemsPerPage' => 20,
        'defaultPid' => 1,
    ],
    'columns' => [
        'title' => [
            'type' => 'string',
            'readable' => true,
            'writable' => true,
            'required' => true,
            'validators' => [
                ['type' => 'maxLength', 'max' => 255],
                ['type' => 'minLength', 'min' => 3],
            ],
        ],
        'color_id' => [
            'readable' => true,
            'writable' => true,
        ],
    ],
    'filters' => [
        'title' => ['strategy' => 'exact'],
    ],
    'order' => [
        'allowed' => ['title', 'uid'],
        'default' => ['uid' => 'asc'],
    ],
    'security' => [
        'list' => AccessRole::PUBLIC,
        'show' => AccessRole::PUBLIC,
        'create' => AccessRole::FE_USER,
        'update' => AccessRole::FE_USER,
        'delete' => AccessRole::BE_ADMIN,
    ],
];
```

### 3. Use the API

All resources are served under the `/_api/` prefix:

```
GET    /_api/articles              → List collection
GET    /_api/articles/1            → Show item
POST   /_api/articles              → Create item
PUT    /_api/articles/1            → Full update
PATCH  /_api/articles/1            → Partial update
DELETE /_api/articles/1            → Delete item
```

## Configuration reference

### General

| Key             | Description                                      |
|-----------------|--------------------------------------------------|
| `table`         | TYPO3 database table name                        |
| `resourceName`  | URL slug used in `/_api/{resourceName}`           |
| `resourceType`  | JSON-LD `@type` value                            |
| `operations`    | Array of enabled operations: `list`, `show`, `create`, `update`, `delete` |
| `itemsPerPage`  | Default page size for list operations            |
| `defaultPid`    | Page ID for newly created records                |

### Columns

Each column key maps to a database column:

| Key            | Description                                         |
|----------------|-----------------------------------------------------|
| `type`         | Data type hint (e.g. `string`)                      |
| `readable`     | Include in API responses                            |
| `writable`     | Accept in create/update requests                    |
| `required`     | Require on POST/PUT (skipped on PATCH if absent)    |
| `resourceName` | Override related resource name for relation columns |
| `validators`   | Array of validation rules (see [Validation](#validation)) |

### Filters

Define filterable columns with a strategy:

```php
'filters' => [
    'title'  => ['strategy' => 'exact'],       // ?filters[title]=Foo
    'name'   => ['strategy' => 'partial'],     // ?filters[name]=oo  → LIKE %oo%
    'search' => ['strategy' => 'word_start'],  // ?filters[search]=Fo → LIKE Fo%
    'categories' => [                          // Many-to-many filter
        'strategy' => 'mm',
        'mm_table' => 'sys_category_record_mm',
        'mm_local_key' => 'uid_local',
        'mm_foreign_key' => 'uid_foreign',
        'mm_constraints' => [
            'tablenames' => 'tx_myext_domain_model_article',
            'fieldname' => 'categories',
        ],
    ],
],
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
    'update' => AccessRole::BE_USER,    // Requires any backend user
    'delete' => AccessRole::BE_ADMIN,   // Requires an admin backend user
],
```

You can also use a callable for custom logic:

```php
'create' => [MyAccessChecker::class, 'checkCreatePermission'],
```

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

Relations are resolved automatically from the TCA schema:

- **hasOne** — A column like `color_id` is embedded as a shallow object (the `_id` suffix is stripped in the response):
  ```json
  "color": { "@id": "/_api/colors/1", "@type": "Color", "uid": 1 }
  ```
- **manyToMany** — Resolved via MM tables and embedded as arrays:
  ```json
  "categories": [
      { "@id": "/_api/sys-categories/1", "@type": "SysCategory", "uid": 1 }
  ]
  ```

Related objects are **shallow**: they contain only `@id`, `@type`, and `uid`. Follow the `@id` link to fetch full details.

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

## API response examples

### Collection (GET `/_api/articles?page=1&itemsPerPage=2`)

```json
{
    "@context": "http://www.w3.org/ns/hydra/context.jsonld",
    "@type": "hydra:Collection",
    "@id": "/_api/articles",
    "hydra:totalItems": 5,
    "hydra:member": [
        {
            "@id": "/_api/articles/1",
            "@type": "Article",
            "uid": 1,
            "title": "First Article"
        }
    ],
    "hydra:view": {
        "@type": "hydra:PartialCollectionView",
        "hydra:first": "/_api/articles?page=1&itemsPerPage=2",
        "hydra:next": "/_api/articles?page=2&itemsPerPage=2",
        "hydra:last": "/_api/articles?page=3&itemsPerPage=2"
    }
}
```

### Single item (GET `/_api/articles/1`)

```json
{
    "@id": "/_api/articles/1",
    "@type": "Article",
    "uid": 1,
    "title": "First Article",
    "color": {
        "@id": "/_api/colors/1",
        "@type": "Color",
        "uid": 1
    }
}
```

### Access denied (403)

```json
{
    "@context": "http://www.w3.org/ns/hydra/context.jsonld",
    "@type": "hydra:Error",
    "hydra:title": "Access Denied",
    "hydra:description": "Insufficient permissions for operation: delete"
}
```

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
