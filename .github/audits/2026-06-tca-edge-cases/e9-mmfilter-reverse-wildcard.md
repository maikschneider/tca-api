> **Status:** Possible issue — not yet confirmed. Filed for investigation, triggered by reverse-MM wildcard work on branch `reverse-mm`.

## Symptom

Filtering an API collection by a reverse-side MM relation that uses `MM_oppositeUsage` (e.g. filter `/api/categories?filter[items]=...`) likely cannot work, because `MmFilter::preResolve()` only recognizes `MM_opposite_field` for orientation flipping.

## Hypothesis

`Classes/Filter/MmFilter.php::preResolve()` (line 65) and `deriveMmConfigFromTca()` (line 103) both branch on `$hasOppositeField = isset($config['MM_opposite_field'])`. Neither recognizes `MM_oppositeUsage`. The forward-side filter subquery is `SELECT uid_foreign FROM mm WHERE uid_local = ?` — for a reverse-side wildcard column, the subquery would need to additionally constrain by `tablenames` and `fieldname` from `MM_oppositeUsage`, which the filter has no representation for.

## Recommendation

Worth confirming the failure mode empirically (filter just returns wrong rows? throws? returns empty?). The resolution path mirrors the reverse-MM work: introduce a shared resolver helper that both the serializer and the filter consult to derive MM orientation and table/field constraints from `MM_oppositeUsage`.

## Verification prompt

<details>
<summary>Self-contained brief for a verification agent</summary>

You are verifying whether `MmFilter` works correctly against a reverse-side MM column.

### Setup
- Working directory: `~/Sites/tca-api`
- DDEV environment
- Depends on the reverse-MM wildcard fix landing on branch `reverse-mm` (so `sys_category.items` is at least exposable).

### Investigation steps
1. Read `Classes/Filter/MmFilter.php` end-to-end. Confirm `MM_oppositeUsage` is not referenced.
2. Once the reverse-MM fix lands, build a resource exposing `sys_category.items` with a filter declared on it:
   ```php
   'filters' => [ 'items' => [MmFilter::class] ],
   ```
3. GET `/api/categories?filter[items]=3` (where article uid=3 is linked to sys_category uid=1 via `sys_category_record_mm`). Assert sys_category uid=1 appears in the result set.
4. If the filter returns the wrong category, or all categories, or zero categories, document the failure mode.
5. Also test cross-table filtering: `/api/categories?filter[items.tablenames]=tt_content&filter[items.uid_foreign]=42` — is there any API surface for table-scoped filtering at all?

### Conclusion gates
- **Confirmed:** filter produces incorrect rows on a reverse-side wildcard MM column.
- **Not an issue:** filter works correctly because MmFilter implicitly handles both orientations.

### Files to read
- `Classes/Filter/MmFilter.php`
- `Classes/Filter/FilterContext.php`
- `Classes/Filter/FilterDefinition.php`

</details>
