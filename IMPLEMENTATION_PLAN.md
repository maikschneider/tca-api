# TYPO3 REST API Extension — Complete Implementation Plan

**Extension key:** `tca_api` (suggested)
**Namespace:** `Vendor\TcaApi`
**Target:** TYPO3 v13+, PHP 8.2+, PSR-7/PSR-15/PSR-12

---

## 1. Architecture Overview

### 1.1 High-Level Components

```
┌─────────────────────────────────────────────────────────────────────┐
│  HTTP Request                                                       │
└──────────────────────────┬──────────────────────────────────────────┘
                          ▼
┌─────────────────────────────────────────────────────────────────────┐
│  TYPO3 Routing Layer                                               │
│  RouteEnhancer (TcaApiEnhancer) → adds _tcaApiResource param       │
└──────────────────────────┬──────────────────────────────────────────┘
                          ▼
┌─────────────────────────────────────────────────────────────────────┐
│  TcaApiMiddleware  (PSR-15, priority 500)                          │
│  Checks _tcaApiResource presence → dispatches to RequestDispatcher  │
└──────────────────────────┬──────────────────────────────────────────┘
                          ▼
┌─────────────────────────────────────────────────────────────────────┐
│  RequestDispatcher                                                  │
│  Resolves resource config from ApiRegistry → selects OperationHandler│
└──────────┬──────────────────────────────────┬────────────────────── ┘
          ▼                                  ▼
┌─────────────────────┐            ┌──────────────────────────┐
│  AccessController   │            │  OperationHandler        │
│  Evaluates access   │            │  GET / POST / PUT /      │
│  rules before ops   │            │  PATCH / DELETE          │
└─────────────────────┘            └──────────┬───────────────┘
                                              ▼
                          ┌──────────────────────────────────┐
                          │  DataRepository (reads)          │
                          │  ConnectionPool → QueryBuilder   │
                          └──────────┬───────────────────────┘
                                      │
                          ┌──────────▼───────────────────────┐
                          │  DataHandler (writes)            │
                          │  TYPO3 Core DataHandler          │
                          └──────────┬───────────────────────┘
                                      ▼
                          ┌──────────────────────────────────┐
                          │  ResourceSerializer              │
                          │  DB row → Hydra JSON-LD array    │
                          └──────────┬───────────────────────┘
                                      ▼
                          ┌──────────────────────────────────┐
                          │  ResponseFactory                 │
                          │  PSR-7 JsonResponse              │
                          └──────────────────────────────────┘
```

### 1.2 Key Design Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Routing | PSR-15 Middleware + RouteEnhancer | No Extbase bootstrap overhead; idiomatic TYPO3 v13 |
| Read data access | `ConnectionPool` → `QueryBuilder` | No ORM coupling, direct SQL control, no Extbase dependency |
| Write data access | `DataHandler` | Respects TCA hooks, timestamps, workspaces, reference index, audit log |
| Configuration format | PHP arrays via `ApiRegistry` | TCA-style familiarity; ext_localconf.php registration; cacheable via PhpFrontend |
| Serialization | Native PHP `ResourceSerializer` | Zero external dependencies; JMS Serializer is unnecessary overhead |
| OpenAPI generation | Runtime from `ApiRegistry` | Single source of truth; no stale spec; served at `/api/openapi.json` |
| Access control | Role constants + PHP callable | Security-safe defaults (no eval surface); performant; fully testable |
| Hydra @context | Inline `@type` / `@id` only | Eliminates extra HTTP round-trip; no information disclosure via context docs |

### 1.3 Request Flow (Complete Lifecycle)

```
1. HTTP GET /api/products?page=2&filter[name]=widget
2. TYPO3 Router → TcaApiEnhancer decodes URL → adds _tcaApiResource=products, _tcaApiOperation=list
3. TcaApiMiddleware::process() checks _tcaApiResource param
4. RequestDispatcher::dispatch($request)
  a. ApiRegistry::getResourceByName('products') → $config array
  b. AccessController::check($config, 'list', $request) → 403 or continue
  c. PSR-14: BeforeOperationEvent dispatched
  d. OperationHandlerRegistry::resolve($method, $operation) → GetCollectionHandler
5. GetCollectionHandler::handle($request, $config)
  a. FilterParser::parse($request->getQueryParams(), $config['filters'])
  b. PaginationParser::parse($request->getQueryParams(), $config['general'])
  c. SortParser::parse($request->getQueryParams(), $config['sorting'])
  d. DataRepository::findCollection($table, $where, $pagination, $sort)
  e. ResourceSerializer::serializeCollection($rows, $config, $context)
  f. PaginationLinkBuilder::build($pagination, $totalCount, $request)
6. PSR-14: AfterOperationEvent dispatched (serialized data can be mutated)
7. HydraResponseBuilder::collection($serializedItems, $pagination, $meta)
8. PSR-7 JsonResponse → 200 application/ld+json
```

---

## 2. Extension Structure

### 2.1 Directory Layout

```
tca_api/
├── composer.json
├── ext_emconf.php
├── ext_localconf.php                 ← middleware + registry boot
├── ext_tables.php                    ← (empty or backend module if needed)
│
├── Configuration/
│   ├── RequestMiddlewares.php        ← TcaApiMiddleware registration
│   ├── Routing/
│   │   └── Enhancers.php            ← TcaApiEnhancer RouteEnhancer config
│   └── Services.yaml                ← DI container, tag-based processor/handler registration
│
├── Classes/
│   ├── Api/
│   │   ├── ApiRegistry.php          ← Singleton registry; register()/getAll()/getByName()
│   │   ├── ResourceConfig.php       ← Value object wrapping a resource config array
│   │   └── ResourceConfigMerger.php ← Merges defaults into user config
│   │
│   ├── Middleware/
│   │   └── TcaApiMiddleware.php     ← PSR-15 entry point
│   │
│   ├── Routing/
│   │   └── TcaApiEnhancer.php       ← Custom RouteEnhancer for clean URLs
│   │
│   ├── Dispatcher/
│   │   └── RequestDispatcher.php    ← Resolves resource + delegates to handler
│   │
│   ├── OperationHandler/
│   │   ├── OperationHandlerInterface.php
│   │   ├── GetItemHandler.php
│   │   ├── GetCollectionHandler.php
│   │   ├── PostHandler.php
│   │   ├── PutHandler.php
│   │   ├── PatchHandler.php
│   │   └── DeleteHandler.php
│   │
│   ├── DataAccess/
│   │   ├── DataRepositoryInterface.php
│   │   ├── DataRepository.php       ← QueryBuilder-based read operations
│   │   └── DataWriteService.php     ← DataHandler-based write operations
│   │
│   ├── Filter/
│   │   ├── FilterInterface.php
│   │   ├── FilterParser.php         ← Parses ?filter[field]=value from query
│   │   ├── SearchFilter.php
│   │   ├── RangeFilter.php
│   │   ├── BooleanFilter.php
│   │   ├── OrderFilter.php
│   │   └── ExactFilter.php
│   │
│   ├── Pagination/
│   │   ├── PaginationParser.php
│   │   ├── PaginationResult.php     ← Value object: page, itemsPerPage, totalCount, offset
│   │   └── PaginationLinkBuilder.php
│   │
│   ├── Serializer/
│   │   ├── ResourceSerializerInterface.php
│   │   ├── ResourceSerializer.php   ← Core: row → Hydra array
│   │   ├── SerializationContext.php ← Depth counter, visited IDs set, operation type
│   │   ├── FieldTypeHandler/
│   │   │   ├── FieldTypeHandlerInterface.php
│   │   │   ├── DateTimeHandler.php
│   │   │   ├── FileReferenceHandler.php
│   │   │   ├── RichTextHandler.php
│   │   │   └── SelectHandler.php
│   │   └── HydraResponseBuilder.php ← Assembles final JSON-LD structure
│   │
│   ├── Validation/
│   │   ├── ValidatorInterface.php
│   │   ├── ValidationResult.php
│   │   ├── CompositeValidator.php
│   │   ├── RequiredValidator.php
│   │   ├── MaxLengthValidator.php
│   │   ├── RegexValidator.php
│   │   └── TcaEvalValidator.php     ← Reuses TCA eval rules
│   │
│   ├── Security/
│   │   ├── AccessControllerInterface.php
│   │   ├── AccessController.php     ← Evaluates role constants + callable access rules
│   │   ├── AccessRole.php           ← Role constant enum: PUBLIC, FE_USER, BE_USER, BE_ADMIN
│   │   └── AccessContext.php        ← Current FE/BE user, request, record
│   │
│   ├── Processor/
│   │   ├── PreOperationProcessorInterface.php
│   │   ├── PostOperationProcessorInterface.php
│   │   └── ProcessorRegistry.php    ← Collects DI-tagged processors
│   │
│   ├── OpenApi/
│   │   ├── OpenApiGenerator.php     ← Traverses ApiRegistry → OpenAPI 3.1 array
│   │   └── OpenApiController.php    ← Serves /api/openapi.json
│   │
│   └── Event/
│       ├── BeforeOperationEvent.php
│       ├── AfterOperationEvent.php
│       ├── BeforeSerializationEvent.php
│       ├── AfterSerializationEvent.php
│       ├── BeforeWriteEvent.php
│       └── AfterWriteEvent.php
│
└── Tests/
    ├── Unit/
    └── Functional/
```

### 2.2 Key Interfaces

```php
// OperationHandlerInterface.php
interface OperationHandlerInterface
{
    public function supports(string $httpMethod, string $operation): bool;
    public function handle(ServerRequestInterface $request, ResourceConfig $config): ResponseInterface;
    public function getPriority(): int;
}

// DataRepositoryInterface.php
interface DataRepositoryInterface
{
    public function findById(string $table, int $uid, array $config): ?array;
    public function findCollection(string $table, array $constraints, PaginationResult $pagination, array $order, array $config): array;
    public function count(string $table, array $constraints, array $config): int;
}

// ResourceSerializerInterface.php
interface ResourceSerializerInterface
{
    public function serialize(array $row, ResourceConfig $config, SerializationContext $context): array;
    public function serializeCollection(array $rows, ResourceConfig $config, SerializationContext $context): array;
}

// FilterInterface.php
interface FilterInterface
{
    public function apply(QueryBuilder $qb, string $property, mixed $value, array $filterConfig): void;
}

// ValidatorInterface.php
interface ValidatorInterface
{
    public function validate(mixed $value, array $fieldConfig, string $fieldName): ValidationResult;
}

// PreOperationProcessorInterface.php
interface PreOperationProcessorInterface
{
    public function process(array $data, ResourceConfig $config, string $operation, ServerRequestInterface $request): array;
    public function getPriority(): int;
}

// PostOperationProcessorInterface.php
interface PostOperationProcessorInterface
{
    public function process(array $result, ResourceConfig $config, string $operation, ServerRequestInterface $request): array;
    public function getPriority(): int;
}

// FieldTypeHandlerInterface.php
interface FieldTypeHandlerInterface
{
    public function supports(string $tcaType, array $fieldConfig): bool;
    public function serialize(mixed $value, array $fieldConfig, SerializationContext $context): mixed;
    public function deserialize(mixed $value, array $fieldConfig): mixed;
}
```

---

## 3. Configuration Design

### 3.1 Registration

Resources are registered in `ext_localconf.php` of any TYPO3 extension:

```php
// EXT:my_extension/ext_localconf.php
use Vendor\TcaApi\Api\ApiRegistry;

ApiRegistry::getInstance()->register(
    table: 'tx_myextension_domain_model_product',
    config: require __DIR__ . '/Configuration/Api/Products.php'
);
```

### 3.2 Full Configuration Array

```php
// EXT:my_extension/Configuration/Api/Products.php
return [

    // ── General resource settings ──────────────────────────────────
    'general' => [
        'resourceName'          => 'products',          // URL slug: /api/products
        'resourceType'          => 'Product',           // Hydra @type value
        'operations'            => ['list', 'show', 'create', 'update', 'delete'],
        'itemsPerPage'          => 20,
        'maxItemsPerPage'       => 100,
        'defaultOrder'          => [['field' => 'name', 'direction' => 'ASC']],
        'pidList'               => [42, 87],            // Restrict to specific pages
        'additionalConstraints' => ['hidden' => 0],     // Extra WHERE conditions
        'language'              => 'current',           // 'current', 'all', int
        'workspace'             => 'live',              // 'live', 'current'
    ],

    // ── Field-level configuration ──────────────────────────────────
    // Inspired by TCA columns + external_import field mappings
    'columns' => [
        'title' => [
            'type'       => 'string',       // string, integer, float, boolean, datetime, relation
            'readable'   => true,
            'writable'   => true,
            'required'   => true,
            'validators' => [
                ['type' => 'maxLength', 'max' => 255],
                ['type' => 'notEmpty'],
            ],
            'transformations' => [          // Applied on serialize (read)
                ['class' => TrimTransformer::class],
            ],
        ],
        'price' => [
            'type'       => 'float',
            'readable'   => true,
            'writable'   => true,
            'validators' => [['type' => 'range', 'min' => 0]],
        ],
        'created_at' => [
            'type'     => 'datetime',
            'readable' => true,
            'writable' => false,           // Read-only: auto-set by TCA crdate
            'format'   => 'c',             // PHP date format for output
        ],
        'hidden' => [
            'readable'  => false,          // Never exposed in API
            'writable'  => false,
        ],
        'password_hash' => [
            'readable'  => false,          // Sensitive — never returned
            'writable'  => true,
        ],

        // ── HasOne relation ─────────────────────────────────────────
        'category' => [
            'type'         => 'relation',
            'relationType' => 'hasOne',
            'foreignTable' => 'tx_myextension_domain_model_category',
            'foreignField' => 'uid',       // FK stored in this table
            'resource'     => 'categories',// Linked ApiRegistry resource name
            'readable'     => true,
            'writable'     => true,
            'embed'        => 'shallow',   // 'none', 'shallow' (uid+@id only), 'full'
        ],

        // ── HasMany (1:n, foreign key in child table) ───────────────
        'images' => [
            'type'         => 'relation',
            'relationType' => 'hasMany',
            'foreignTable' => 'tx_myextension_domain_model_image',
            'foreignField' => 'product',   // FK column in foreign table
            'resource'     => 'images',
            'readable'     => true,
            'writable'     => false,
            'embed'        => 'shallow',
            'sortBy'       => 'sorting',
        ],

        // ── ManyToMany (MM table) ───────────────────────────────────
        'tags' => [
            'type'          => 'relation',
            'relationType'  => 'manyToMany',
            'foreignTable'  => 'tx_myextension_domain_model_tag',
            'mmTable'       => 'tx_myextension_product_tag_mm',
            'mmLocalField'  => 'uid_local',
            'mmForeignField'=> 'uid_foreign',
            'resource'      => 'tags',
            'readable'      => true,
            'writable'      => true,
            'embed'         => 'shallow',
        ],
    ],

    // ── Filters available on this resource ─────────────────────────
    'filters' => [
        [
            'property'    => 'title',
            'filterClass' => SearchFilter::class,
            'strategy'    => 'partial',    // partial, word_start, exact
        ],
        [
            'property'    => 'price',
            'filterClass' => RangeFilter::class,
        ],
        [
            'property'    => 'category',
            'filterClass' => ExactFilter::class,
        ],
    ],

    // ── Sorting configuration ───────────────────────────────────────
    'sorting' => [
        'allowedFields' => ['title', 'price', 'created_at'],
        'paramName'     => 'order',        // ?order[title]=desc
    ],

    // ── Per-operation access control ────────────────────────────────
    // Tier 1: Built-in role constant (enum)
    // Tier 2: PHP callable [ClassName::class, 'methodName']
    'security' => [
        'list'   => AccessRole::PUBLIC,
        'show'   => AccessRole::PUBLIC,
        'create' => AccessRole::FE_USER,
        'update' => [ProductAccessChecker::class, 'canUpdate'],
        'delete' => AccessRole::BE_ADMIN,
    ],

    // ── Processors ──────────────────────────────────────────────────
    'processors' => [
        'pre'  => [
            ['class' => SlugGeneratorProcessor::class, 'operations' => ['create']],
        ],
        'post' => [
            ['class' => CacheInvalidationProcessor::class, 'operations' => ['create', 'update', 'delete']],
        ],
    ],

    // ── Custom serializer (optional override) ───────────────────────
    'serializer' => ProductSerializer::class,  // implements ResourceSerializerInterface

    // ── Pagination ──────────────────────────────────────────────────
    'pagination' => [
        'enabled'           => true,
        'pageParam'         => 'page',
        'itemsPerPageParam' => 'itemsPerPage',
    ],
];
```

### 3.3 TCA Analogy Mapping

| TCA Concept | API Config Equivalent | Notes |
|---|---|---|
| `$TCA[table]['ctrl']` | `'general'` section | Table-level settings |
| `$TCA[table]['columns'][field]` | `'columns'[field]` | Field definitions |
| `type` in TCA column | `type` in API column | Semantic type hint for serializer |
| `eval = required,trim` | `validators`, `transformations` | Split into discrete validators |
| `foreign_table` | `foreignTable` + `relationType` | Relation config |
| `MM` | `mmTable`, `mmLocalField`, `mmForeignField` | MM table config |
| `readOnly` | `writable: false` | Write protection |
| TCA `exclude` | `readable: false` | Exposure control |

### 3.4 Config Loading and Caching

Config is registered during the TYPO3 `ext_localconf.php` boot phase — which is cached by TYPO3's PhpFrontend caching layer. After first boot, all `ext_localconf.php` files are compiled and cached; `ApiRegistry::register()` calls are effectively free on warm cache.

```php
// ApiRegistry.php
final class ApiRegistry
{
    private static ?self $instance = null;
    private array $resources = [];

    public static function getInstance(): self { /* singleton */ }

    public function register(string $table, array $config): void
    {
        $merged = ResourceConfigMerger::merge($config, $this->getDefaults());
        $this->resources[$table] = new ResourceConfig($table, $merged);
    }

    public function getByResourceName(string $name): ?ResourceConfig
    {
        foreach ($this->resources as $config) {
            if ($config->getResourceName() === $name) return $config;
        }
        return null;
    }
}
```

### 3.5 Default Merging

`ResourceConfigMerger::merge()` deep-merges user config with secure defaults:

```php
private static array $defaults = [
    'general' => [
        'operations'      => ['list', 'show'],   // Read-only by default
        'itemsPerPage'    => 20,
        'maxItemsPerPage' => 100,
        'language'        => 'current',
        'workspace'       => 'live',
    ],
    'security' => [
        'list'   => AccessRole::PUBLIC,
        'show'   => AccessRole::PUBLIC,
        'create' => AccessRole::BE_ADMIN,  // Restrictive defaults for writes
        'update' => AccessRole::BE_ADMIN,
        'delete' => AccessRole::BE_ADMIN,
    ],
    'pagination' => ['enabled' => true],
];
```

**Safe default:** no fields are readable without explicit `'readable' => true` (allowlist model).

---

## 4. API Design

### 4.1 URL Structure

```
Base prefix (configurable):  /api/

Collection:   GET    /api/{resourceName}
              POST   /api/{resourceName}

Item:         GET    /api/{resourceName}/{uid}
              PUT    /api/{resourceName}/{uid}
              PATCH  /api/{resourceName}/{uid}
              DELETE /api/{resourceName}/{uid}

Relations:    GET    /api/{resourceName}/{uid}/{relationName}

OpenAPI spec: GET    /api/openapi.json
```

### 4.2 Route Enhancer Configuration

```yaml
# config/sites/my-site/config.yaml
routeEnhancers:
  TcaApi:
    type: TcaApiEnhancer
    prefix: 'api'
```

The `TcaApiEnhancer` reads registered resource names from `ApiRegistry` at site config warmup and generates route patterns accordingly. It sets `_tcaApiResource` and `_tcaApiOperation` routing arguments.

### 4.3 Example Responses

**GET /api/products — Hydra Collection**

```json
{
  "@context": "https://www.w3.org/ns/hydra/core#",
  "@id": "/api/products",
  "@type": "hydra:Collection",
  "hydra:member": [
    {
      "@id": "/api/products/42",
      "@type": "Product",
      "uid": 42,
      "title": "Widget Pro",
      "price": 29.99,
      "created_at": "2026-01-15T10:30:00+01:00",
      "category": {
        "@id": "/api/categories/5",
        "@type": "Category",
        "uid": 5
      },
      "tags": [
        { "@id": "/api/tags/1", "@type": "Tag", "uid": 1 },
        { "@id": "/api/tags/3", "@type": "Tag", "uid": 3 }
      ]
    }
  ],
  "hydra:totalItems": 145,
  "hydra:view": {
    "@id": "/api/products?page=2",
    "@type": "hydra:PartialCollectionView",
    "hydra:first": "/api/products?page=1",
    "hydra:last": "/api/products?page=8",
    "hydra:previous": "/api/products?page=1",
    "hydra:next": "/api/products?page=3"
  },
  "hydra:search": {
    "@type": "hydra:IriTemplate",
    "hydra:template": "/api/products{?filter[title],filter[price][gte],filter[price][lte],order[title],order[price],page,itemsPerPage}",
    "hydra:mapping": [
      { "@type": "hydra:IriTemplateMapping", "hydra:variable": "filter[title]", "hydra:property": "title" },
      { "@type": "hydra:IriTemplateMapping", "hydra:variable": "order[title]", "hydra:property": "title" }
    ]
  }
}
```

**GET /api/products/42 — Single Item**

```json
{
  "@id": "/api/products/42",
  "@type": "Product",
  "uid": 42,
  "title": "Widget Pro",
  "price": 29.99,
  "created_at": "2026-01-15T10:30:00+01:00",
  "category": {
    "@id": "/api/categories/5",
    "@type": "Category",
    "uid": 5,
    "name": "Electronics"
  }
}
```

**POST /api/products — Create**

```
Request:
POST /api/products
Content-Type: application/json

{ "title": "New Widget", "price": 19.99, "category": 5 }

Response: 201 Created
Location: /api/products/99

{ "@id": "/api/products/99", "@type": "Product", "uid": 99, "title": "New Widget", "price": 19.99 }
```

**Error — HTTP 422 Validation Failure**

```json
{
  "@type": "hydra:Error",
  "hydra:title": "Validation Failed",
  "hydra:description": "2 validation errors",
  "violations": [
    { "propertyPath": "title", "message": "This value should not be blank", "code": "NOT_BLANK" },
    { "propertyPath": "price", "message": "Value must be greater than 0", "code": "RANGE" }
  ]
}
```

**Error — HTTP 403 Forbidden**

```json
{
  "@type": "hydra:Error",
  "hydra:title": "Access Denied",
  "hydra:description": "Insufficient permissions for operation: create"
}
```

### 4.4 OpenAPI Generation Strategy

`OpenApiGenerator` traverses `ApiRegistry::getAll()` at runtime and produces an OpenAPI 3.1 spec:

```php
final class OpenApiGenerator
{
    public function generate(): array
    {
        $spec = [
            'openapi' => '3.1.0',
            'info'    => ['title' => 'TYPO3 REST API', 'version' => '1.0.0'],
            'paths'   => [],
            'components' => ['schemas' => []],
        ];

        foreach ($this->registry->getAll() as $resourceConfig) {
            $this->addPaths($spec, $resourceConfig);
            $this->addSchemas($spec, $resourceConfig);
        }

        return $spec;
    }
}
```

Field type mapping: `string` → `string`, `integer` → `integer`, `float` → `number`, `datetime` → `string` (format: `date-time`), `relation` → `$ref` or `integer` (UID).

Served at `GET /api/openapi.json` with `Cache-Control: public, max-age=3600`. Can be dumped as a static file via CLI command for CI pipelines.

### 4.5 Content-Type and CORS

- **Request**: accepts `application/json` and `application/ld+json`
- **Response**: `Content-Type: application/ld+json` for resources, `application/json` for OpenAPI spec
- **CORS**: `CorsProcessor` runs before access control; configured globally:

```php
ApiRegistry::getInstance()->setCorsConfig([
    'allowedOrigins'  => ['https://my-spa.example.com'],
    'allowedMethods'  => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowCredentials'=> true,
    'maxAge'          => 3600,
]);
```

---

## 5. Core Components

### 5.1 Middleware

```php
// TcaApiMiddleware.php — registered at priority 500
final class TcaApiMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RequestDispatcher $dispatcher,
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $params = $request->getAttribute('routing')?->getArguments() ?? [];

        if (!isset($params['_tcaApiResource'])) {
            return $handler->handle($request);
        }

        return $this->dispatcher->dispatch($request, $params);
    }
}
```

```php
// Configuration/RequestMiddlewares.php
return [
    'frontend' => [
        'vendor/tca-api/request-resolver' => [
            'target' => TcaApiMiddleware::class,
            'after'  => ['typo3/cms-frontend/tsfe-initialization'],
            'before' => ['typo3/cms-frontend/page-resolver'],
        ],
    ],
];
```

### 5.2 DataRepository (Reads Only)

```php
final class DataRepository implements DataRepositoryInterface
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function findCollection(
        string $table,
        array $constraints,
        PaginationResult $pagination,
        array $order,
        array $config
    ): array {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);

        $this->applyTcaCtrlConstraints($qb, $table);
        $this->applyLanguageConstraint($qb, $table, $config);

        foreach ($constraints as $field => $value) {
            $qb->andWhere($qb->expr()->eq($field, $qb->createNamedParameter($value)));
        }

        $qb->setFirstResult($pagination->getOffset())
          ->setMaxResults($pagination->getItemsPerPage());

        foreach ($order as $orderItem) {
            $qb->addOrderBy($orderItem['field'], $orderItem['direction']);
        }

        return $qb->executeQuery()->fetchAllAssociative();
    }

    private function applyTcaCtrlConstraints(QueryBuilder $qb, string $table): void
    {
        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
        if (isset($ctrl['delete'])) {
            $qb->andWhere($qb->expr()->eq($ctrl['delete'], 0));
        }
    }
}
```

**Language overlays**: Fetches default language record, applies `PageRepository::getRecordOverlay()`.
**Workspace overlays**: Applies workspace preview records via `WorkspaceAspect` where available.

### 5.3 DataWriteService (Writes via DataHandler)

```php
final class DataWriteService
{
    public function create(string $table, array $data): int
    {
        $dataMap = [$table => ['NEW_1' => $data]];
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($dataMap, []);
        $dataHandler->process_datamap();

        if ($dataHandler->errorLog) {
            throw new DataHandlerException(implode(', ', $dataHandler->errorLog));
        }

        return (int)($dataHandler->substNEWwithIDs['NEW_1'] ?? 0);
    }

    public function update(string $table, int $uid, array $data): void
    {
        $dataMap = [$table => [$uid => $data]];
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($dataMap, []);
        $dataHandler->process_datamap();
    }

    public function delete(string $table, int $uid): void
    {
        $cmdMap = [$table => [$uid => ['delete' => 1]]];
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], $cmdMap);
        $dataHandler->process_cmdmap();
    }
}
```

DataHandler ensures: `crdate`/`tstamp`/`cruser_id` auto-set, soft-delete respected, workspace drafts handled, reference index updated, cache cleared, all `processDatamap` hooks from third-party extensions fire correctly.

### 5.4 ResourceSerializer

```php
final class ResourceSerializer implements ResourceSerializerInterface
{
    private const MAX_DEPTH = 5;

    public function serialize(
        array $row,
        ResourceConfig $config,
        SerializationContext $context
    ): array {
        $key = $config->getTable() . ':' . $row['uid'];

        // Circular reference guard
        if ($context->hasVisited($key)) {
            return ['@id' => $this->buildIri($config, $row['uid']), 'uid' => $row['uid']];
        }
        $context->markVisited($key);

        $result = [
            '@id'   => $this->buildIri($config, $row['uid']),
            '@type' => $config->getResourceType(),
        ];

        foreach ($config->getReadableColumns() as $field => $fieldConfig) {
            $value = $row[$field] ?? null;
            $result[$field] = $this->serializeField($value, $fieldConfig, $context);
        }

        return $result;
    }
}
```

`SerializationContext` carries: `visitedIds[]`, `depth` counter, `operation` string, optional `fields[]` for sparse fieldsets.

### 5.5 Validation Layer

```php
final class CompositeValidator
{
    public function validateRecord(array $data, ResourceConfig $config): ValidationResult
    {
        $result = new ValidationResult();

        foreach ($config->getWritableColumns() as $field => $fieldConfig) {
            $value = $data[$field] ?? null;
            foreach ($fieldConfig['validators'] ?? [] as $validatorConfig) {
                $validator = $this->resolveValidator($validatorConfig['type']);
                $result->merge($validator->validate($value, $validatorConfig, $field));
            }
        }

        return $result;
    }
}
```

`TcaEvalValidator` reuses TCA `eval` rules: `required`, `trim`, `int`, `double2`, `alphanum`, `email`, `date`, `datetime`, `slug`, `uniqueInPid`.

---

## 6. Feature Implementation Strategy

### 6.1 CRUD Operation Flows

#### GET Collection (`list`)
```
1. Middleware → AccessController::check($config, 'list')
2. BeforeOperationEvent dispatched
3. FilterParser::parse($queryParams, $config['filters']) → constraints
4. PaginationParser::parse() → PaginationResult
5. SortParser::parse() → order specs (validated against allowedFields)
6. DataRepository::count() → $total
7. DataRepository::findCollection() → $rows
8. Pre-processors run on $rows
9. ResourceSerializer::serializeCollection() → $serialized
10. Post-processors run on $serialized
11. AfterOperationEvent dispatched
12. HydraResponseBuilder::collection($serialized, $pagination, $total) → 200
```

#### POST Create (`create`)
```
1. AccessController::check($config, 'create')
2. json_decode($request->getBody()) → $input
3. DeserializationFilter::filter($input, $config) → strip non-writable fields
4. BeforeWriteEvent dispatched
5. CompositeValidator::validateRecord($data, $config) → 422 if errors
6. Pre-processors run on $data
7. DataWriteService::create($table, $data) → $newUid
8. DataRepository::findById($table, $newUid) → $row
9. ResourceSerializer::serialize() → $serialized
10. AfterWriteEvent dispatched
11. JsonResponse(201, Location: /api/products/$newUid)
```

#### PUT Full Update (`update`)
```
1. AccessController::check($config, 'update')
2. findById → 404 if not found
3. Parse body → all writable fields required
4. DeserializationFilter → strip non-writable
5. BeforeWriteEvent dispatched
6. CompositeValidator::validateRecord() → 422 if errors
7. DataWriteService::update($table, $uid, $data)
8. Fetch + serialize → 200
```

#### PATCH Partial Update
Same as PUT but only provided fields are validated/updated. Missing fields left unchanged.

#### DELETE
```
1. AccessController::check($config, 'delete')
2. findById → 404 if not found
3. BeforeWriteEvent dispatched
4. DataWriteService::delete($table, $uid) → sets deleted=1
5. AfterWriteEvent dispatched
6. JsonResponse(204, no body)
```

### 6.2 Filtering

Filter parameters: `?filter[field]=value` or `?filter[field][operator]=value`

```
?filter[title]=widget          → SearchFilter, partial match
?filter[price][gte]=10         → RangeFilter, ≥10
?filter[price][lte]=50         → RangeFilter, ≤50
?filter[category]=5            → ExactFilter
?filter[hidden]=false          → BooleanFilter
```

`SearchFilter` example:

```php
final class SearchFilter implements FilterInterface
{
    public function apply(QueryBuilder $qb, string $property, mixed $value, array $config): void
    {
        $strategy = $config['strategy'] ?? 'partial';
        $param = $qb->createNamedParameter(match ($strategy) {
            'partial'    => '%' . $qb->escapeLikeWildcards((string)$value) . '%',
            'word_start' => $qb->escapeLikeWildcards((string)$value) . '%',
            'exact'      => (string)$value,
        });
        $qb->andWhere($qb->expr()->like($property, $param));
    }
}
```

### 6.3 Pagination

```php
final class PaginationParser
{
    public function parse(array $queryParams, array $paginationConfig): PaginationResult
    {
        $page         = max(1, (int)($queryParams[$paginationConfig['pageParam']] ?? 1));
        $itemsPerPage = min(
            (int)($queryParams[$paginationConfig['itemsPerPageParam']] ?? $paginationConfig['itemsPerPage']),
            $paginationConfig['maxItemsPerPage']
        );
        return new PaginationResult(
            page: $page,
            itemsPerPage: $itemsPerPage,
            offset: ($page - 1) * $itemsPerPage
        );
    }
}
```

`PaginationLinkBuilder` generates `hydra:view` with `hydra:first`, `hydra:last`, `hydra:previous`, `hydra:next`.

### 6.4 Sorting

`?order[title]=asc&order[price]=desc`

`SortParser` validates fields against `config['sorting']['allowedFields']` — rejects unknown fields with `400 Bad Request` to prevent SQL injection via untrusted `ORDER BY` values.

### 6.5 Relation Handling

**HasOne (`embed: shallow`):** Extract FK value from parent row → single `findById()` on related table → serialize with `@id` + `@type` + `uid` only.

**HasOne (`embed: full`):** Full recursive `ResourceSerializer::serialize()` with incremented depth counter.

**HasMany:** Single query: `WHERE foreign_field = :parentUid ORDER BY sorting`.

**ManyToMany:**
```php
// Step 1: fetch UIDs from MM table
$mmUids = $qb->select('uid_foreign')
    ->from($fieldConfig['mmTable'])
    ->where($qb->expr()->eq('uid_local', $row['uid']))
    ->orderBy('sorting')
    ->executeQuery()->fetchFirstColumn();

// Step 2: fetch related records
$relatedRows = $qb->select('*')
    ->from($fieldConfig['foreignTable'])
    ->where($qb->expr()->in('uid', $mmUids))
    ->executeQuery()->fetchAllAssociative();
```

**Circular relation prevention:** `SerializationContext::$visitedIds` keyed by `table:uid`. Already-visited records return minimal identity stub `{'@id': '...', 'uid': N}`. `MAX_DEPTH = 5` as hard stop.

---

## 7. Extensibility Model

### 7.1 PSR-14 Event System

| Event | When | Mutable |
|---|---|---|
| `BeforeOperationEvent` | Before any operation | `$request` |
| `AfterOperationEvent` | After operation, before response | `$data` |
| `BeforeWriteEvent` | Before DataHandler call | `$data` (input) |
| `AfterWriteEvent` | After DataHandler | `$uid`, `$table` |
| `BeforeSerializationEvent` | Before row serialized | `$row` |
| `AfterSerializationEvent` | After row serialized | `$serialized` |
| `BeforeFilterEvent` | Before filters applied | `QueryBuilder` |
| `AccessDeniedEvent` | On access check failure | Custom response |
| `ApiAuthEvent` | Before access control | `FrontendUserAspect` |

All events implement `StoppableEventInterface`.

### 7.2 Processor Interfaces

```php
interface PreOperationProcessorInterface
{
    public function process(array $data, ResourceConfig $config, string $operation, ServerRequestInterface $request): array;
    public function getPriority(): int;
}

interface PostOperationProcessorInterface
{
    public function process(array $result, ResourceConfig $config, string $operation, ServerRequestInterface $request): array;
    public function getPriority(): int;
}
```

DI tag registration in `Services.yaml`:

```yaml
services:
  App\Api\Processor\SlugGeneratorProcessor:
    tags:
      - name: 'tca_api.pre_processor'
        priority: 100
```

### 7.3 Custom Field Type Handlers

```php
interface FieldTypeHandlerInterface
{
    public function supports(string $tcaType, array $fieldConfig): bool;
    public function serialize(mixed $value, array $fieldConfig, SerializationContext $context): mixed;
    public function deserialize(mixed $value, array $fieldConfig): mixed;
}
```

Register via DI tag `tca_api.field_type_handler`. Example for a geo-point field type:

```php
final class GeoPointHandler implements FieldTypeHandlerInterface
{
    public function supports(string $tcaType, array $fieldConfig): bool
    {
        return $fieldConfig['type'] === 'geopoint';
    }

    public function serialize(mixed $value, array $fieldConfig, SerializationContext $context): mixed
    {
        [$lat, $lng] = explode(',', (string)$value);
        return ['lat' => (float)$lat, 'lng' => (float)$lng];
    }

    public function deserialize(mixed $value, array $fieldConfig): mixed
    {
        return $value['lat'] . ',' . $value['lng'];
    }
}
```

### 7.4 Third-Party Extension Registration

```php
// EXT:vendor_news/ext_localconf.php
\Vendor\TcaApi\Api\ApiRegistry::getInstance()->register(
    table: 'tx_news_domain_model_news',
    config: require __DIR__ . '/Configuration/TcaApi/News.php'
);
```

### 7.5 Custom Serializer Override

```php
// In resource config:
'serializer' => NewsSerializer::class,  // implements ResourceSerializerInterface

final class NewsSerializer implements ResourceSerializerInterface
{
    public function __construct(
        private readonly ResourceSerializer $defaultSerializer,
    ) {}

    public function serialize(array $row, ResourceConfig $config, SerializationContext $context): array
    {
        $data = $this->defaultSerializer->serialize($row, $config, $context);
        $data['readingTime'] = $this->calculateReadingTime($row['bodytext']);
        return $data;
    }
}
```

---

## 8. Security Considerations

### 8.1 Two-Tier Access Control

**Tier 1 — `AccessRole` enum (built-in constants)**

```php
enum AccessRole: string
{
    case PUBLIC   = 'public';      // No auth required
    case FE_USER  = 'fe_user';     // Any logged-in frontend user
    case BE_USER  = 'be_user';     // Any logged-in backend user
    case BE_ADMIN = 'be_admin';    // Backend admin only
}
```

Evaluated via `match` — zero string parsing, zero AST evaluation, no eval surface.

**Tier 2 — PHP Callable (complex rules)**

```php
final class ProductAccessChecker
{
    public function canUpdate(AccessContext $context): bool
    {
        $record = $context->getRecord();
        $feUser = $context->getFrontendUser();

        return $feUser->isLoggedIn()
            && ($record['cruser_id'] === $feUser->getUserId()
                || $context->getBackendUser()->isAdmin());
    }
}
```

`AccessContext` provides: `getFrontendUser()`, `getBackendUser()`, `getRecord()`, `getRequest()`, `getOperation()`.

### 8.2 Field Allowlist Model

**Default: no field is exposed.** Every field needs explicit `'readable' => true`. Fields not in `'columns'` are never returned. This ensures GDPR-safe defaults and prevents accidental exposure of internal TCA fields (`deleted`, `l10n_parent`, `pid`, etc.).

### 8.3 Mass Assignment Prevention

`DeserializationFilter` strips all fields without `'writable' => true` from POST/PUT/PATCH bodies before validation:

```php
final class DeserializationFilter
{
    public function filter(array $input, ResourceConfig $config): array
    {
        $writableFields = array_keys(array_filter(
            $config->getColumns(),
            fn(array $col) => ($col['writable'] ?? false) === true
        ));
        return array_intersect_key($input, array_flip($writableFields));
    }
}
```

Prevents overwriting `uid`, `pid`, `crdate`, `deleted`, and any other internal fields.

### 8.4 Authentication Integration

**Frontend users:** Standard `fe_session` cookie auth. Works with any FE auth extension.
**Backend users:** `$GLOBALS['BE_USER']` check. Useful for headless CMS builds.
**API Key / JWT (extension point):** Via `ApiAuthEvent` — third-party packages validate tokens and inject a synthetic user aspect before access control runs.

### 8.5 Additional Security Measures

- **SQL injection:** All filter values use `createNamedParameter()`. `LIKE` values use `escapeLikeWildcards()`. Sort fields validated against explicit allowlist.
- **Rate limiting:** `RateLimitInterface` with no-op default. Implement via PSR-15 wrapper middleware.
- **Error responses:** No SQL errors, no stack traces, no internal details. Exception handler returns generic `500` body.
- **OpenAPI spec access:** Can be gated behind `AccessRole::BE_USER` via config.
- **Context documents omitted:** No `/api/contexts/{resource}` endpoints to avoid schema reconnaissance without authentication.

---

## 9. Comparison Notes

### 9.1 vs. `external_import`

**Similarities:**

| Aspect | external_import | tca_api |
|---|---|---|
| Config format | PHP arrays in TCA `['external']` sub-key | PHP arrays via `ApiRegistry::register()` |
| Field transformations | Per-field transformation chain | Per-field `transformations` array |
| Step/processor pipeline | `AbstractStep` pipeline | Pre/post `Processor` pipeline |
| Event system | PSR-14 events at lifecycle points | PSR-14 events at lifecycle points |
| TCA-adjacent config | Lives inside TCA `['columns']` | Mirrors TCA `columns` key structure |

**Key Differences:**

| Aspect | external_import | tca_api |
|---|---|---|
| Direction | **Inbound** — imports data *into* TYPO3 | **Bidirectional** — exposes TYPO3 data via REST |
| Config location | Embedded in TCA | Separate `ApiRegistry` (no TCA pollution) |
| Connector model | Pluggable connector services (JSON, CSV, XML) | HTTP protocol only (REST verbs) |
| Processing steps | Step objects with abort flag | Processor interfaces per operation |

**Design inheritance:** The `general`/`columns` config structure, per-field transformation chain, and processor pipeline model are directly inspired by `external_import`. Key improvement: config lives in a dedicated registry rather than inside TCA to keep rendering and access concerns separate.

### 9.2 vs. `t3api`

**Feature parity:**

| Feature | t3api | tca_api |
|---|---|---|
| Hydra JSON-LD responses | ✅ | ✅ |
| Filtering (Search, Range, Order, Boolean) | ✅ | ✅ |
| Pagination with `hydra:view` | ✅ | ✅ |
| PSR-15 middleware routing | ✅ | ✅ |
| Per-operation access control | ExpressionLanguage | Role enum + callable |
| PSR-14 events | Partial | Full lifecycle |
| OpenAPI schema | Partial | Runtime generation |
| File reference handling | JMS handler | `FieldTypeHandlerInterface` |
| Relation handling | Extbase ObjectStorage | QueryBuilder + config |

**Key Architectural Differences:**

| Aspect | t3api | tca_api |
|---|---|---|
| **Resource definition** | PHP `#[ApiResource]` attributes on Extbase model class | PHP arrays in config files — **no model classes** |
| **Extbase dependency** | **Hard** — requires domain models + repositories | **None** |
| **Serialization** | **JMS Serializer** (heavy, requires YAML metadata) | **Native PHP** (zero external dependencies) |
| **Access control** | Symfony ExpressionLanguage strings | `AccessRole` enum + PHP callable |
| **Write operations** | Extbase repo + DataHandler | DataHandler only |
| **Config location** | Co-located with model class | Separate config files |
| **Applicable tables** | Extensions with Extbase models only | **Any table** — raw, legacy, third-party |

**Choose `tca_api` when:**
- Tables don't have Extbase domain models
- You want TCA-style array config (not annotations)
- You need to avoid JMS Serializer as a dependency
- You're exposing third-party, legacy, or system tables (`sys_file`, custom log tables, etc.)

**`t3api` may still suit when:**
- Codebase already has Extbase models with rich domain logic
- You prefer attribute-based resource definition
- You need Symfony ExpressionLanguage for complex inline access rules

---

## Appendix: Quick-Start Example

```php
// EXT:my_extension/ext_localconf.php
\Vendor\TcaApi\Api\ApiRegistry::getInstance()->register(
    table: 'tx_myextension_domain_model_article',
    config: [
        'general' => [
            'resourceName' => 'articles',
            'resourceType' => 'Article',
            'operations'   => ['list', 'show'],
            'itemsPerPage' => 25,
        ],
        'columns' => [
            'title'        => ['type' => 'string',   'readable' => true],
            'bodytext'     => ['type' => 'string',   'readable' => true],
            'publish_date' => ['type' => 'datetime', 'readable' => true],
            'author'       => [
                'type'         => 'relation',
                'relationType' => 'hasOne',
                'foreignTable' => 'fe_users',
                'resource'     => 'authors',
                'readable'     => true,
                'embed'        => 'shallow',
            ],
        ],
        'filters' => [
            ['property' => 'title', 'filterClass' => \Vendor\TcaApi\Filter\SearchFilter::class, 'strategy' => 'partial'],
        ],
        'security' => [
            'list' => \Vendor\TcaApi\Security\AccessRole::PUBLIC,
            'show' => \Vendor\TcaApi\Security\AccessRole::PUBLIC,
        ],
    ]
);
```

```bash
# List articles
curl /api/articles

# Search + paginate
curl "/api/articles?filter[title]=TYPO3&page=1&itemsPerPage=10"

# Single item
curl /api/articles/42

# OpenAPI spec
curl /api/openapi.json
```
