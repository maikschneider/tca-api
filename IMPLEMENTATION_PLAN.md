# TCA API — Implementation Plan

**Extension key:** `tca_api`
**Namespace:** `MaikSchneider\TcaApi`
**Target:** TYPO3 v13.4+ / v14+, PHP 8.2+, PSR-7/PSR-15

> **Last updated:** 2026-04-08
> **State:** Alpha (0.1.0) — core CRUD, filtering, pagination, sorting, events, validation, access control all working with 17 functional tests.

---

## 1. Current Architecture

### 1.1 Implemented Components

```
┌─────────────────────────────────────────────────────────────────────┐
│  HTTP Request                                                       │
└──────────────────────────┬──────────────────────────────────────────┘
                          ▼
┌─────────────────────────────────────────────────────────────────────┐
│  TcaApiMiddleware  (PSR-15)                                         │
│  Matches /_api/* path → dispatches to RequestDispatcher             │
└──────────────────────────┬──────────────────────────────────────────┘
                          ▼
┌─────────────────────────────────────────────────────────────────────┐
│  RequestDispatcher                                                  │
│  Resolves resource from ApiRegistry → routes to OperationHandler    │
│  Enforces operations whitelist + AccessController                   │
│  Dispatches BeforeOperationEvent                                    │
└──────────┬──────────────────────────────────┬────────────────────── ┘
          ▼                                  ▼
┌─────────────────────┐            ┌──────────────────────────┐
│  AccessController   │            │  OperationHandlers       │
│  AccessRole enum +  │            │  GetCollectionHandler    │
│  callable voters    │            │  GetItemHandler          │
└─────────────────────┘            │  CreateHandler           │
                                  │  UpdateHandler (PUT/PATCH)│
                                  │  DeleteHandler           │
                                  └──────────┬───────────────┘
                                              ▼
                          ┌──────────────────────────────────┐
                          │  DataRepository (reads)          │
                          │  ConnectionPool → QueryBuilder   │
                          │  Filters: exact, partial,        │
                          │  word_start, mm (TCA-derived)    │
                          └──────────┬───────────────────────┘
                                      │
                          ┌──────────▼───────────────────────┐
                          │  DataWriteService (writes)       │
                          │  TYPO3 Core DataHandler          │
                          └──────────┬───────────────────────┘
                                      ▼
                          ┌──────────────────────────────────┐
                          │  ResourceSerializer              │
                          │  RecordFactory + TcaSchemaFactory│
                          │  DB row → Hydra JSON-LD array    │
                          │  Shallow relation embeds only    │
                          └──────────┬───────────────────────┘
                                      ▼
                          ┌──────────────────────────────────┐
                          │  HydraResponseBuilder            │
                          │  Hydra Collection / Item / Error │
                          └──────────────────────────────────┘
```

### 1.2 Actual File Structure

```
tca_api/
├── composer.json
├── ext_emconf.php
├── ext_localconf.php
├── phpunit.xml / phpstan.neon / php-cs-fixer.php
│
├── Configuration/
│   ├── RequestMiddlewares.php
│   ├── Services.yaml
│   ├── TCA/                          ← Test table definitions
│   └── TcaApi/
│       ├── Articles.php              ← Example resource config
│       ├── Colors.php
│       └── SysCategories.php
│
├── Classes/
│   ├── Registry/
│   │   └── ApiRegistry.php           ← Static registry: register(), get(), getByTable(), reset()
│   ├── Middleware/
│   │   └── TcaApiMiddleware.php      ← PSR-15 entry point
│   ├── Dispatcher/
│   │   └── RequestDispatcher.php     ← Route parsing, access check, handler dispatch
│   ├── OperationHandler/
│   │   ├── ColumnFilterTrait.php     ← Normalizes writable columns for DataHandler
│   │   ├── CreateHandler.php
│   │   ├── DeleteHandler.php
│   │   ├── GetCollectionHandler.php
│   │   ├── GetItemHandler.php
│   │   └── UpdateHandler.php
│   ├── DataAccess/
│   │   ├── DataRepository.php        ← QueryBuilder reads + filter strategies
│   │   └── DataWriteService.php      ← DataHandler wrapper
│   ├── Serializer/
│   │   ├── ResourceSerializer.php    ← RecordFactory-based serialization
│   │   └── HydraResponseBuilder.php  ← JSON-LD response assembly
│   ├── Security/
│   │   └── AccessController.php      ← Role enum + callable evaluation
│   ├── Enum/
│   │   └── AccessRole.php            ← PUBLIC, FE_USER, BE_USER, BE_ADMIN
│   ├── Validation/
│   │   └── FieldValidator.php        ← required, maxLength, minLength, regex
│   ├── Event/
│   │   ├── BeforeOperationEvent.php
│   │   ├── AfterOperationEvent.php
│   │   ├── BeforeWriteEvent.php
│   │   └── AfterWriteEvent.php
│   └── Testing/
│       └── EventCollector.php        ← Test helper
│
└── Tests/
    ├── Fixtures/
    │   ├── TestCallableChecker.php
    │   ├── *.sql                      ← Database fixtures
    │   └── Files/
    └── Functional/
        ├── ApiFunctionalTestCase.php
        └── Api/
            ├── AccessControlTest.php
            ├── ArticleApiTest.php
            ├── CallableAccessTest.php
            ├── CollectionFilteringTest.php
            ├── CollectionPaginationTest.php
            ├── CollectionSortingTest.php
            ├── EventsTest.php
            ├── FilterByRelationTest.php
            ├── FilterMmTcaDerivedTest.php
            ├── FrontendGroupAccessTest.php
            ├── GetItemApiTest.php
            ├── OperationsEnforcementTest.php
            ├── RelationsTest.php
            ├── SparseFieldsetsTest.php
            ├── ValidationTest.php
            ├── WriteOperationsTest.php
            └── WriteRelationsTest.php
```

### 1.3 Design Decisions (Actual)

| Decision | Choice | Rationale |
|---|---|---|
| Routing | PSR-15 Middleware, path-based `/_api/*` parsing | No RouteEnhancer needed — direct path segment parsing in RequestDispatcher |
| Read data access | `ConnectionPool` → `QueryBuilder` | No ORM coupling, direct SQL control |
| Write data access | `DataHandler` | Respects TCA hooks, timestamps, reference index |
| Relation resolution | `RecordFactory` + `TcaSchemaFactory` (v13 Schema API) | Automatic relation introspection from TCA, lazy resolution |
| Configuration format | Static PHP arrays via `ApiRegistry::register()` | TCA-style familiarity; registered in ext_localconf.php |
| Serialization | Native PHP `ResourceSerializer` | Zero external dependencies |
| Access control | `AccessRole` enum + PHP callable | Security-safe defaults; fully testable |

### 1.4 Request Flow (Actual)

```
1. HTTP GET /_api/articles?page=1&filters[title]=foo&order[title]=asc
2. TcaApiMiddleware::process() matches /_api/ prefix
3. RequestDispatcher::dispatch($request)
  a. Parses path segments → resourceName + optional uid
  b. ApiRegistry::get($resourceName) → $config
  c. Determines operation from HTTP method + uid presence
  d. Enforces operations whitelist from config
  e. AccessController::isAllowed($requiredRole, $request)
  f. Dispatches BeforeOperationEvent
  g. Parses ?fields[] for sparse fieldsets
4. GetCollectionHandler::handle(...)
  a. resolveFilters() → validates against declared filters
  b. resolveOrder() → validates against allowed sort columns
  c. DataRepository::count() → $total
  d. DataRepository::findCollection() → $rows (with filter + pagination + order)
  e. ResourceSerializer::serializeCollection() → JSON-LD arrays
  f. Dispatches AfterOperationEvent (data can be mutated)
5. HydraResponseBuilder::buildCollection() → Hydra JSON-LD response
```

---

## 2. Implementation Status

### ✅ Fully Implemented & Tested

| Feature | Test Coverage | Key Files |
|---|---|---|
| **GET list** (collection endpoint) | `ArticleApiTest`, `CollectionPaginationTest` | `GetCollectionHandler` |
| **GET item** (single record) | `GetItemApiTest` | `GetItemHandler` |
| **POST create** | `WriteOperationsTest` | `CreateHandler` |
| **PUT/PATCH update** | `WriteOperationsTest` | `UpdateHandler` |
| **DELETE** (soft-delete) | `WriteOperationsTest` | `DeleteHandler` |
| **Exact filter** (`?filters[col]=val`) | `CollectionFilteringTest` | `DataRepository::applyFilterConstraint` |
| **Partial filter** (`LIKE %val%`) | `CollectionFilteringTest` | `DataRepository::applyFilterConstraint` |
| **Word-start filter** (`LIKE val%`) | `CollectionFilteringTest` | `DataRepository::applyFilterConstraint` |
| **MM relation filter** (explicit config) | `FilterByRelationTest` | `DataRepository::applyMmFilterConstraint` |
| **MM filter (TCA-derived)** | `FilterMmTcaDerivedTest` | `DataRepository::deriveMmConfigFromTca` |
| **Pagination** (`?page=N&itemsPerPage=M`) | `CollectionPaginationTest` | `RequestDispatcher::handleCollection` |
| **Sorting** (`?order[col]=asc\|desc`) | `CollectionSortingTest` | `GetCollectionHandler::resolveOrder` |
| **Sparse fieldsets** (`?fields[res]=col1,col2`) | `SparseFieldsetsTest` | `ResourceSerializer::serialize` |
| **Validation** (required, maxLength, minLength, regex) | `ValidationTest` | `FieldValidator` |
| **Access control** (AccessRole enum) | `AccessControlTest`, `FrontendGroupAccessTest` | `AccessController` |
| **Callable access voters** | `CallableAccessTest` | `AccessController` |
| **Operations enforcement** | `OperationsEnforcementTest` | `RequestDispatcher` |
| **Relation serialization** (shallow) | `RelationsTest` | `ResourceSerializer::buildShallowEmbed` |
| **Write MM relations** | `WriteRelationsTest` | `ColumnFilterTrait` |
| **PSR-14 events** (Before/AfterOperation, Before/AfterWrite) | `EventsTest` | `Event/` classes |
| **Hydra JSON-LD format** | All tests | `HydraResponseBuilder` |

### ❌ Not Yet Implemented

These features are identified in `TODO_MISSING_FEATURES.md` and are required for production use / t3api parity:

| # | Feature | Priority | Blocking? | Description |
|---|---|---|---|---|
| **1** | **Storage PID constraint** | 🔴 HIGH | YES | `WHERE pid IN (...)` — without this, all records are returned regardless of page tree |
| **2** | **Virtual / computed properties** | 🔴 HIGH | — | Callback-based fields like `displayName`, `url`, `previewImage` |
| **3** | **Serialization groups** | 🟠 MEDIUM | — | Per-operation field visibility (collection vs. item) |
| **4** | **Multi-field search filter** | 🔴 HIGH | — | `?search=Alice` searching across multiple columns with OR logic |
| **5** | **Range filter** | 🟢 LOW | — | `?col[gte]=100&col[lte]=200` operators |
| **6** | **Image / FAL processing** | 🔴 HIGH | — | Process `sys_file_reference` with width/crop instructions |
| **7** | **Typolink resolution** | 🟠 MEDIUM | — | Generate frontend URLs from page UIDs |
| **8** | **Nested relation depth control** | 🟢 LOW | — | `maxDepth` to prevent infinite recursion on self-references |
| **9** | **Object-level security** | 🟠 MEDIUM | — | PATCH only if `object.uid == currentUserId` |
| **10** | **Custom route patterns** | 🟠 MEDIUM | — | `/user/current` style non-CRUD operations |
| **11** | **Maximum items per page** | 🟢 LOW | — | Cap `itemsPerPage` to prevent unbounded queries |
| **12** | **Inline relation fields** | 🔴 HIGH | YES | Include specific fields from related records (not just uid/@id/@type) |
| **13** | **Relation filtering** | 🟢 LOW | — | Exclude specific UIDs from relation serialization |

---

## 3. TDD Roadmap — Agent Prompts

Each task below follows **test-driven development**: write the failing test first, then implement the minimal code to make it pass. Tasks are ordered by dependency and priority.

---

### Phase 1 — Critical Blockers

#### Task 1.1: Storage PID Constraint
**Priority:** 🔴 HIGH — Without this, all `fe_users` (or any table) are returned regardless of page tree location.

> **Prompt:**
> Add support for a `storagePid` key in the `general` section of the resource config. When set (single int or array of ints), both `findById`, `findCollection`, and `count` in `DataRepository` must add `WHERE pid IN (...)` constraints. Write a functional test `StoragePidTest` that registers a resource with `'storagePid' => 42`, inserts records on pid 42 and pid 99, and asserts that only pid 42 records appear in `GET /_api/{resource}` and that `GET /_api/{resource}/{uid}` returns 404 for a record on pid 99. Follow existing test patterns in `Tests/Functional/Api/`.

---

#### Task 1.2: Inline Relation Field Selection
**Priority:** 🔴 HIGH — Related records currently only expose `{@id, @type, uid}`.

> **Prompt:**
> Add support for an `inlineFields` key per column in the resource config. When a relational column has `'inlineFields' => ['name', 'email']`, `ResourceSerializer::buildShallowEmbed` must include those fields from the related `RecordInterface` in addition to the standard `@id`, `@type`, `uid`. Write a functional test `InlineRelationFieldsTest` that configures a relation column with `inlineFields`, creates parent + related records, and asserts the API response includes the specified fields on the embedded relation object. Also test that without `inlineFields`, only `@id/@type/uid` appear (existing behavior preserved).

---

#### Task 1.3: Maximum Items Per Page
**Priority:** 🟢 LOW (but trivial and blocks nothing)

> **Prompt:**
> Add support for a `maxItemsPerPage` key in the `general` section of the resource config. In `RequestDispatcher::handleCollection`, clamp the `$itemsPerPage` value to `min($requested, $config['general']['maxItemsPerPage'])`. Default to `100` when not configured. Write a functional test `MaxItemsPerPageTest` that configures `'maxItemsPerPage' => 5`, requests `?itemsPerPage=999`, and asserts only 5 items are returned and `hydra:view` links use `itemsPerPage=5`.

---

### Phase 2 — Search & Filter Enhancements

#### Task 2.1: Multi-Field Search Filter
**Priority:** 🔴 HIGH — Frontend uses `?search=Alice` to search across first_name + last_name.

> **Prompt:**
> Add a new filter strategy `search` that accepts a custom parameter name and searches across multiple columns with OR + partial matching. Config example: `'search' => ['strategy' => 'search', 'columns' => ['first_name', 'last_name'], 'match' => 'partial']`. In `DataRepository`, when strategy is `search`, build an OR expression: `(first_name LIKE %val% OR last_name LIKE %val%)`. Write a functional test `SearchFilterTest` that registers a resource with a `search` filter on two columns, inserts test records, and asserts `?filters[search]=Ali` returns matching records while non-matching records are excluded.

---

#### Task 2.2: Range Filter
**Priority:** 🟢 LOW

> **Prompt:**
> Add a new filter strategy `range` that supports `gte`, `lte`, `gt`, `lt` sub-operators. Config: `'dkfz_id' => ['strategy' => 'range']`. The filter value comes as `?filters[dkfz_id][gte]=100&filters[dkfz_id][lte]=200`. In `DataRepository::applyFilterConstraint`, when strategy is `range`, the value is an array of operators. Build AND constraints for each operator. Write a functional test `RangeFilterTest` that inserts records with varying integer values, applies range filters, and asserts correct result sets for `gte`, `lte`, `gt`, `lt`, and combinations.

---

### Phase 3 — Serialization Enhancements

#### Task 3.1: Serialization Groups (Per-Operation Field Visibility)
**Priority:** 🟠 MEDIUM

> **Prompt:**
> Add support for per-operation field visibility via a `readableIn` key on each column config. When set (e.g. `'readableIn' => ['list']` or `'readableIn' => ['show']`), the field is only included in responses for that operation. When `readableIn` is not set, the field appears in all operations (current behavior). Pass the current operation name (`list` or `show`) through to `ResourceSerializer::serialize` and filter columns accordingly. Write a functional test `SerializationGroupsTest` that configures some fields as list-only and others as show-only, and asserts the correct fields appear in collection vs. item responses.

---

#### Task 3.2: Virtual / Computed Properties
**Priority:** 🔴 HIGH

> **Prompt:**
> Add support for a `virtualProperties` key in the resource config. Each virtual property defines a `name` and a `callback` (callable array `[ClassName::class, 'methodName']`). The callback receives the serialized row array and the full raw database row, and returns a computed value. `ResourceSerializer::serialize` appends virtual properties after serializing all real columns. Write a functional test `VirtualPropertiesTest` that configures a virtual property `displayName` with a test callback that concatenates `last_name, first_name`, inserts a record, and asserts `GET /_api/{resource}/{id}` includes the computed `displayName` field.

---

#### Task 3.3: Relation Filtering in Serialization
**Priority:** 🟢 LOW

> **Prompt:**
> Add support for an `excludeUids` key on relational column configs. When set (e.g. `'excludeUids' => [1, 287]`), `ResourceSerializer::buildShallowEmbed` (or its caller) filters out related records whose uid is in the exclude list before serialization. Write a functional test `RelationFilteringTest` that configures a many-to-many relation with `excludeUids`, creates related records including excluded ones, and asserts the API response omits the excluded UIDs.

---

### Phase 4 — File & URL Processing

#### Task 4.1: Image / FAL Reference Processing
**Priority:** 🔴 HIGH

> **Prompt:**
> Add support for image processing on `sys_file_reference` columns. Add a column config key `processing` (e.g. `'processing' => ['width' => 600, 'cropVariant' => 'square']`). When `ResourceSerializer` encounters a relational field whose foreign table is `sys_file_reference`, it should use TYPO3's `ImageService` or `ProcessedFile` API to generate a processed image URL instead of a shallow embed. Write a functional test `ImageProcessingTest` that configures an image column with processing instructions, creates a record with a `sys_file_reference`, and asserts the API response contains a processed image URL (or at minimum a public URL string rather than a relation object).

---

#### Task 4.2: Typolink Resolution
**Priority:** 🟠 MEDIUM

> **Prompt:**
> Add support for a column type or virtual property mechanism that resolves TYPO3 typolinks. This can be implemented as a virtual property whose callback uses `ContentObjectRenderer::typoLink_URL()`. Write a functional test `TypolinkResolutionTest` that configures a virtual property `url` with a callback that generates a URL from a page UID, and asserts the serialized output contains a resolved URL string. If `ContentObjectRenderer` is unavailable in test context, verify the callback mechanism works and the property appears in the output.

---

### Phase 5 — Advanced Security & Routing

#### Task 5.1: Object-Level Security for PATCH
**Priority:** 🟠 MEDIUM

> **Prompt:**
> Extend the security system to support object-level access checks on update/delete operations. The callable voter for `update` and `delete` should receive the existing record's data (fetched from DB) so it can compare e.g. `$record['uid'] === $currentFeUserId`. Modify `RequestDispatcher` to fetch the record before calling `AccessController::isAllowed` for item-level operations, and pass it as context. Write a functional test `ObjectLevelSecurityTest` that configures `'update' => [TestOwnerChecker::class, 'isOwner']`, creates a record owned by fe_user 1, authenticates as fe_user 1 (allowed) and fe_user 2 (denied), and asserts PATCH returns 200 vs. 403 accordingly.

---

#### Task 5.2: Custom Route Patterns
**Priority:** 🟠 MEDIUM

> **Prompt:**
> Add support for custom (non-CRUD) operations via a `customOperations` key in the resource config. Each custom operation defines a `path`, `handler` (class name), and `security` role. The `RequestDispatcher` should check for custom path matches before falling through to standard CRUD routing. Write a functional test `CustomRouteTest` that registers a custom operation `get_current` at path `/users/current` with a test handler that returns a hard-coded response, and asserts `GET /_api/users/current` returns the custom handler's response while standard CRUD still works.

---

#### Task 5.3: Nested Relation Depth Control
**Priority:** 🟢 LOW

> **Prompt:**
> Add support for a `maxDepth` key on relational column configs to prevent infinite recursion on self-referencing relations (e.g. `fe_users.representative` → `fe_users`). When `maxDepth` is reached, serialize the relation as a minimal identity stub `{@id, @type, uid}` even if `inlineFields` is configured. Write a functional test `NestedDepthControlTest` that configures a self-referencing relation with `maxDepth: 1`, creates a chain of related records, and asserts the response stops embedding at the configured depth.

---

### Phase 6 — Polish

#### Task 6.1: Hydra `hydra:search` IRI Template
**Priority:** 🟢 LOW

> **Prompt:**
> Add a `hydra:search` section to collection responses that describes available filter parameters as an IRI template. `HydraResponseBuilder::buildCollection` should include `hydra:search` with `hydra:template` and `hydra:mapping` entries derived from the resource's `filters` config. Write a functional test that asserts the `hydra:search` key is present in collection responses and contains correct template variables matching the configured filters.

---

#### Task 6.2: OpenAPI Schema Generation
**Priority:** 🟢 LOW

> **Prompt:**
> Create an `OpenApiGenerator` class that traverses `ApiRegistry` and produces an OpenAPI 3.1 spec array. Add a dedicated route `GET /_api/openapi.json` handled in `RequestDispatcher` (or a separate middleware). The spec should include paths for all registered resources, request/response schemas derived from column configs, and filter parameters. Write a functional test that requests `/_api/openapi.json` and asserts valid JSON with correct paths and schemas for the test resource.

---

## 4. Configuration Reference (Current + Planned)

```php
return [
    'general' => [
        'table'           => 'tx_myext_domain_model_article',  // ✅ Implemented
        'resourceName'    => 'articles',                        // ✅ Implemented
        'resourceType'    => 'Article',                         // ✅ Implemented
        'operations'      => ['list', 'show', 'create', ...],  // ✅ Implemented
        'itemsPerPage'    => 20,                                // ✅ Implemented
        'defaultPid'      => 1,                                 // ✅ Implemented (writes)
        'storagePid'      => 42,          // ❌ TODO Task 1.1
        'maxItemsPerPage' => 100,         // ❌ TODO Task 1.3
    ],

    'columns' => [
        'title' => [
            'type'       => 'string',     // ✅ Implemented
            'readable'   => true,         // ✅ Implemented
            'writable'   => true,         // ✅ Implemented
            'required'   => true,         // ✅ Implemented
            'readableIn' => ['list'],     // ❌ TODO Task 3.1
            'validators' => [...],        // ✅ Implemented
        ],
        'committee' => [
            'readable'     => true,       // ✅ Implemented
            'resourceName' => 'committees', // ✅ Implemented
            'inlineFields' => ['name'],   // ❌ TODO Task 1.2
            'excludeUids'  => [1, 287],   // ❌ TODO Task 3.3
            'maxDepth'     => 1,          // ❌ TODO Task 5.3
        ],
        'image' => [
            'readable'   => true,         // ✅ Implemented
            'processing' => ['width' => 600, 'cropVariant' => 'square'],  // ❌ TODO Task 4.1
        ],
    ],

    'filters' => [
        'title'  => ['strategy' => 'exact'],       // ✅ Implemented
        'name'   => ['strategy' => 'partial'],     // ✅ Implemented
        'search' => [                               // ❌ TODO Task 2.1
            'strategy' => 'search',
            'columns'  => ['first_name', 'last_name'],
            'match'    => 'partial',
        ],
        'dkfz_id' => ['strategy' => 'range'],      // ❌ TODO Task 2.2
        'categories' => ['strategy' => 'mm', ...],  // ✅ Implemented
    ],

    'order' => [
        'allowed' => ['title', 'uid'],              // ✅ Implemented
        'default' => ['uid' => 'asc'],              // ✅ Implemented
    ],

    'security' => [
        'list'   => AccessRole::PUBLIC,              // ✅ Implemented
        'create' => AccessRole::FE_USER,             // ✅ Implemented
        'update' => [Checker::class, 'isOwner'],     // ✅ Callable implemented
        // Object-level context for voters:          // ❌ TODO Task 5.1
    ],

    'virtualProperties' => [                         // ❌ TODO Task 3.2
        [
            'name'     => 'displayName',
            'callback' => [UserVirtualProps::class, 'displayName'],
        ],
    ],

    'customOperations' => [                          // ❌ TODO Task 5.2
        'get_current' => [
            'path'     => '/users/current',
            'handler'  => GetCurrentUserHandler::class,
            'security' => AccessRole::FE_USER,
        ],
    ],
];
```

---

## 5. Test Infrastructure

**Test framework:** TYPO3 Testing Framework with functional test cases.
**Base class:** `ApiFunctionalTestCase` — sets up TYPO3 instance, loads extension, imports SQL fixtures.
**Pattern:** Each test class registers a resource config in `setUp()`, imports fixture data, makes HTTP requests via internal request handling, and asserts JSON response structure.

```bash
# Run all tests
vendor/bin/phpunit -c phpunit.xml

# Run a single test
vendor/bin/phpunit -c phpunit.xml --filter StoragePidTest

# Static analysis
composer php:stan
composer php:fixer
```
