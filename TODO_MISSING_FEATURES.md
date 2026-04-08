# tca-api: Missing Features for User API Parity

> Tracked during migration from `sourcebroker/t3api` to `maikschneider/tca-api`.
> Reference: `Xima\XmDkfzNetSite\Domain\Model\Api\User` → `Configuration/TcaApi/Users.php`

---

## ✅ Already working

| Feature | Notes |
|---|---|
| Collection endpoint `GET /_api/users` | list operation via `GetCollectionHandler` |
| Item endpoint `GET /_api/users/{id}` | show operation via `GetItemHandler` |
| Scalar field serialization | `first_name`, `last_name`, `email`, `title`, `slug`, `responsibilities`, `public_profile` |
| Exact filter on `slug` | `?filters[slug]=john-doe` |
| Partial filter on `first_name`, `last_name` | `?filters[first_name]=Joh` |
| Order by `last_name` | `?order[last_name]=asc` |
| Pagination | `?page=1&itemsPerPage=500` |
| Relation serialization (shallow) | `committee`, `usergroup`, `contacts`, `features` as `{@id, @type, uid}` |
| Public access | No auth required for list/show |

---

## ❌ Missing Features (TODOs)

### 1. Storage PID Constraint
**Priority: HIGH** — without this, all `fe_users` are returned regardless of page tree location.

- **t3api**: `persistence.storagePid = 91`
- **Needed**: A `storagePid` key in `general` config adding `WHERE pid = 91` to all queries in `DataRepository`.

### 2. Virtual / Computed Properties
**Priority: HIGH** — several critical API fields are computed at serialization time.

| Property | Logic | Used in |
|---|---|---|
| `displayName` | `"Title LastName, FirstName"` or fallback to `username` | collection |
| `contactFunction` | First contact record's `function` field | collection |
| `contactRoom` | First contact record's `room` field | collection |
| `url` | Typolink: page 159 + `userUid` + `L` + `slug` | collection |
| `previewImage` | Image processed at `width=600`, `cropVariant=square` | collection |
| `imageCrop` | Raw JSON crop config from `sys_file_reference.crop` | item |
| `displayHomepageWelcomeBox` | `true` if `registration_date` within last 14 days | item |

**Suggested approach**: Add a `virtualProperties` config key per resource, where each entry defines a `callback` (class+method or closure) receiving the serialized row and returning a value. Alternatively, document the use of `AfterOperationEvent` listeners.

### 3. Serialization Groups (Per-Operation Field Visibility)
**Priority: MEDIUM** — controls which fields appear in collection vs. item responses.

- **t3api**: `normalizationContext.groups` per operation. Collection only shows `api_get_dkfz_users`-tagged fields; item shows everything.
- **Needed**: Per-operation `columns` override or a `groups` mechanism:
  ```php
  'columns' => [
      'email' => [
          'readable' => true,
          'readableIn' => ['list', 'show'], // or per-operation visibility
      ],
  ]
  ```

### 4. Multi-Field Search Filter with Custom Parameter Name
**Priority: HIGH** — the frontend uses `?search=Alice` to search across `first_name` and `last_name`.

- **t3api**: `SearchFilter` with `parameterName: "search"` combining `first_name` (partial) + `last_name` (partial) with OR logic.
- **Needed**: A `search` filter strategy:
  ```php
  'filters' => [
      'search' => [
          'strategy' => 'search',
          'columns' => ['first_name', 'last_name'],
          'match' => 'partial',
      ],
  ]
  ```

### 5. Range Filter
**Priority: LOW** — used for `dkfzId` integer range queries.

- **t3api**: `RangeFilter` supporting `?dkfzId[gte]=100&dkfzId[lte]=200`.
- **Needed**: `strategy: 'range'` in `DataRepository::applyFilterConstraint` with `gte`/`lte`/`gt`/`lt` operators.

### 6. Image / File Reference Processing
**Priority: HIGH** — profile preview images need FAL processing.

- **t3api**: `@Image(width="600", cropVariant="square")` processes FAL references.
- **Needed**: Column config for file references with processing instructions:
  ```php
  'image' => [
      'readable' => true,
      'type' => 'image',
      'processing' => ['width' => 600, 'cropVariant' => 'square'],
  ]
  ```
  Must integrate with TYPO3's `ImageService` / `ProcessedFile`.

### 7. Typolink Resolution
**Priority: MEDIUM** — generates frontend URLs from TYPO3 page UIDs.

- **t3api**: `@Serializer\Type\Typolink` on `getUrl()` method.
- **Needed**: Either a virtual property mechanism (see #2) or a column type `typolink` that resolves using `ContentObjectRenderer::typoLink_URL`.

### 8. Nested Relation Depth Control
**Priority: LOW** — prevents infinite recursion on self-referencing `fe_users` relations.

- **t3api**: `@MaxDepth(1)` on `representative` and `representative2`.
- **Needed**: `maxDepth` key per relational column in config.

### 9. PATCH Operations with Object-Level Security
**Priority: MEDIUM** — needed for profile editing and bookmark management.

- **t3api**: `security: "object.getUid() == currentUserId"` ensures only the owner can PATCH.
- **Needed**: Expression-based security or a callable voter:
  ```php
  'security' => [
      'update' => [SecurityVoter::class, 'isOwner'],
  ]
  ```
  `AccessController` already supports callable arrays — just needs the convention documented and the uid comparison helper.

### 10. Custom Route Patterns (Non-CRUD Operations)
**Priority: MEDIUM** — `/user/current` resolves the authenticated user.

- **t3api**: Item operation `get_current` with custom path `/user/current`.
- **Needed**: Custom operation definitions:
  ```php
  'operations' => ['list', 'show', 'get_current'],
  'customOperations' => [
      'get_current' => [
          'path' => '/user/current',
          'handler' => GetCurrentUserHandler::class,
          'security' => AccessRole::FE_USER,
      ],
  ]
  ```

### 11. Maximum Items Per Page
**Priority: LOW** — prevents clients from requesting unbounded result sets.

- **t3api**: `maximum_items_per_page: 500`
- **Needed**: `maxItemsPerPage` in `general` config, enforced in `RequestDispatcher::handleCollection`.

### 12. Inline Relation Field Selection
**Priority: HIGH** — related records must expose specific fields, not just `uid/@id/@type`.

- **t3api**: Each related model has `@Serializer\Groups` controlling which fields are serialized when embedded.
- **Needed**: Per-column `inlineFields` config:
  ```php
  'committee' => [
      'readable' => true,
      'inlineFields' => ['name'],
  ],
  'contacts' => [
      'readable' => true,
      'inlineFields' => ['record_type', 'number'],
  ],
  ```
  `ResourceSerializer::buildShallowEmbed` would need to read these fields from the `RecordInterface` and include them.

### 13. Relation Filtering in Serialization
**Priority: LOW** — some relations need post-processing before output.

- **t3api**: `getUsergroup()` skips groups with uid=1 and uid=287.
- **Needed**: Per-column `exclude` or `filter` callback:
  ```php
  'usergroup' => [
      'readable' => true,
      'excludeUids' => [1, 287],
  ]
  ```

---

## Migration Path

1. Implement **#1** (storagePid) and **#12** (inline fields) — these are required for the collection response to be usable.
2. Implement **#2** (virtual properties) and **#4** (multi-field search) — needed for frontend compatibility.
3. Implement **#6** (image processing) — needed for profile images.
4. Remaining items can be addressed incrementally.

Once all HIGH-priority items are resolved, the `GET /_api/users` endpoint from tca-api will be a functional replacement for the current `sourcebroker/t3api` endpoint.
