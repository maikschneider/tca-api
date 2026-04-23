# Plan: Record-Level Ownership Security for Write Operations

## Context

The current role-based access control (`AccessRole` enum) only checks *who the user is* globally — any `FE_USER` can update or delete any record. There's no way to restrict operations to the record's creator/owner. This plan adds a declarative `ownership` config section and a new `AccessRole::OWNER` pseudo-role to cover the standard ownership use case without requiring custom voter classes.

---

## New Configuration Format

A new top-level `ownership` key is added to resource configs, alongside `security`, `columns`, etc.

### Minimal (single column — auth + tracking):
```php
'security' => [
    'create' => AccessRole::FE_USER,
    'update' => AccessRole::OWNER,   // only the record owner can update
    'delete' => AccessRole::OWNER,
],
'ownership' => [
    'column' => 'fe_user_id',        // compared against current FE user UID on update/delete
                                    // also auto-set on create
],
```

### Separate tracking vs. auth columns:
```php
'ownership' => [
    'column'      => 'fe_user_id',    // auth column (used for OWNER check)
    'setOnCreate' => 'fe_creator_id', // different column auto-set on create only
    'beAdminBypass' => true,          // (default) BE_ADMIN skips ownership check
],
```

### Tracking only (no auth):
```php
'ownership' => [
    'setOnCreate' => 'fe_creator_id', // just records who created it, no auth gating
],
// No AccessRole::OWNER used in security — any FE_USER can update
```

### Config key reference:
| Key | Required | Default | Purpose |
|---|---|---|---|
| `column` | for OWNER auth | — | DB column holding owner UID; compared on update/delete |
| `setOnCreate` | no | same as `column` | Column to auto-set on create (if different from `column`) |
| `beAdminBypass` | no | `true` | When true, BE_ADMIN bypasses OWNER check |

---

## Architecture

### 1. `AccessRole::OWNER` (new enum case)
**File:** `Classes/Enum/AccessRole.php`

Add `case OWNER = 'OWNER';`. Used in `security` config to signal ownership-based check. If used without `ownership.column` configured → secure-by-default deny (403).

### 2. `AccessController::isAllowed()` — extend for OWNER
**File:** `Classes/Security/AccessController.php`

- Add optional `array $config = []` parameter (BC-safe, all existing callers unaffected)
- Add `AccessRole::OWNER` branch that calls new private `isOwner()` method
- `isOwner()` logic: read `$config['ownership']['column']`, compare `$record[$column]` to `$feUser->user['uid']`; respects `beAdminBypass` (default true)

### 3. `RequestDispatcher::checkAccess()` — pass config
**File:** `Classes/Dispatcher/RequestDispatcher.php`

Pass `$config` to `AccessController::isAllowed()`. `$config` is already available at call site.

### 4. `CreateHandler::handle()` — auto-inject owner UID
**File:** `Classes/OperationHandler/CreateHandler.php`

After `filterWritableColumns()`, before dispatching `BeforeWriteEvent`:
```php
$ownerColumn = $config['ownership']['setOnCreate'] ?? $config['ownership']['column'] ?? null;
if ($ownerColumn !== null) {
    $feUser = $request->getAttribute('frontend.user');
    if ($feUser?->user['uid'] ?? null) {
        $data[$ownerColumn] = (int)$feUser->user['uid'];
    }
}
```
This happens server-side — the column is not writable by the client.

### 5. `ColumnFilterTrait::filterWritableColumns()` — strip ownership columns
**File:** `Classes/OperationHandler/ColumnFilterTrait.php`

Remove `ownership.column` and `ownership.setOnCreate` from client-submitted data in both create and update. Prevents a client from POSTing `{"fe_user_id": 99}` to poison ownership.

---

## Behavioral Rules

- `OWNER` on `list`/`show` would always deny (no single record to compare) — don't use it for reads; document this
- Unauthenticated request + `OWNER` → 403 (no FE user found)
- `OWNER` without `ownership.column` in config → 403 (misconfiguration, fail secure)
- `setOnCreate` without a logged-in FE user → column is not set (no injection if user is null)
- Ownership columns are always stripped from client input regardless of their `groups` config

---

## Files to Change

| File | Change |
|---|---|
| `Classes/Enum/AccessRole.php` | Add `OWNER` case |
| `Classes/Security/AccessController.php` | Add `isOwner()`, handle `OWNER` in `isAllowed()`, add `$config` param |
| `Classes/Dispatcher/RequestDispatcher.php` | Pass `$config` to `isAllowed()` |
| `Classes/OperationHandler/CreateHandler.php` | Inject owner UID after `filterWritableColumns` |
| `Classes/OperationHandler/ColumnFilterTrait.php` | Strip ownership columns from client data |

No new files. No new services. No event changes.

---

## Verification

1. Existing tests must remain green (BC-safe changes only)
2. New functional tests:
  - `POST` with `FE_USER` → record has correct `fe_user_id` set
  - `POST` with `setOnCreate` column → correct separate column is set, `column` is untouched
  - `POST {"fe_user_id": 99}` → field is ignored, server-set value wins
  - `PUT` as record owner → 200
  - `PUT` as different FE user → 403
  - `PUT` unauthenticated → 403
  - `PUT` as BE_ADMIN with `beAdminBypass: true` (default) → 200
  - `OWNER` in `security` without `ownership.column` → 403
  - Existing resource config without `ownership` key → no behavior change
