> **Status:** Possible issue — not yet confirmed. Filed for investigation, triggered by reverse-MM wildcard work on branch `reverse-mm`.

## Symptom

Items returned by an MM relation read from the **reverse side** (i.e. where the configured column has `MM_opposite_field` set, such as a forward-side `categories` field read in reverse) may appear in a different order than they do in the TYPO3 backend.

## Hypothesis

`DataRepository::findHasManyByMM()` (`Classes/DataAccess/DataRepository.php:134`) always orders results by `mm.sorting`. On the **forward** side that is correct — `sorting` is the position of the related record inside the parent's list. On the **reverse** side the canonical order column is `sorting_foreign`, which TYPO3 maintains as the position from the opposite perspective. Current code applies `mm.sorting` unconditionally regardless of orientation, so reverse-side reads may surface MM rows in forward-side order instead of reverse-side order. Callers that flip orientation via `MM_opposite_field` (`Classes/Serializer/GroupFieldSerializer.php:84`, `Classes/Serializer/RelationSerializer.php:209`) do not pass orientation information into the repository call.

## Recommendation

Worth investigating whether the order returned for a reverse-side MM relation in the API matches the order shown in the TYPO3 backend list module for the same record, across MM tables that maintain both `sorting` and `sorting_foreign`. Outcome of the investigation determines whether the repository call needs to accept an orientation argument.

## Verification prompt

<details>
<summary>Self-contained brief for a verification agent</summary>

You are verifying whether reverse-side MM relations in the `maikschneider/tca-api` extension return rows in the correct order.

### Setup
- Working directory: `~/Sites/tca-api`
- DDEV environment — prefix all commands with `ddev exec`
- Branch: `reverse-mm`

### Investigation steps
1. Read `Classes/DataAccess/DataRepository.php` around line 134 (`findHasManyByMM`). Note the hard-coded `->addOrderBy('mm.sorting')`.
2. Read `Classes/Serializer/GroupFieldSerializer.php:60-104` and `Classes/Serializer/RelationSerializer.php:200-220`. Note that both flip `uid_local`/`uid_foreign` based on `MM_opposite_field` but do not flip the sort column.
3. Consult TYPO3 docs: https://docs.typo3.org/m/typo3/reference-tca/main/en-us/ColumnsConfig/Type/Group/Index.html — specifically the `MM_opposite_field` semantics for `sorting` vs `sorting_foreign`.
4. Construct a test:
   - Fixture: two parent records on the forward side, both linking to the same related record, but with different `sorting_foreign` values (so the reverse-side order differs from the forward-side order).
   - Resource config: expose the reverse side as a hasMany.
   - GET the reverse-side resource and compare the returned `@id` order against `sorting_foreign` order.
5. Run: `ddev exec vendor/bin/phpunit -c phpunit.xml --testsuite Embed --filter ReverseMm` (after adding the test).

### Conclusion gates
- **Confirmed:** test shows ordering matches `sorting`, not `sorting_foreign`, when the relation is read from the reverse side.
- **Not an issue:** test shows ordering already matches `sorting_foreign` (e.g. because the underlying query plan happens to surface the right order, or because `MM_opposite_field` callers route through a different repository method).

### Files to read
- `Classes/DataAccess/DataRepository.php`
- `Classes/Serializer/GroupFieldSerializer.php`
- `Classes/Serializer/RelationSerializer.php`
- `Tests/Functional/Fixtures/sys_category_record_mm.csv` (existing fixture shape)

### Reference
- TYPO3 TCA reference for `type=group` MM: https://docs.typo3.org/m/typo3/reference-tca/main/en-us/ColumnsConfig/Type/Group/Index.html

</details>
