> **Status:** Possible issue — not yet confirmed. Filed for investigation, triggered by reverse-MM wildcard work on branch `reverse-mm`.

## Symptom

Hidden, deleted, or start-/endtime-restricted records on the **target side** of an MM JOIN may leak into API responses, because the JOIN doesn't apply the target table's enableFields.

## Hypothesis

`Classes/DataAccess/DataRepository.php::findHasManyByMM()` (line ~134) does:
```php
$qb->select('f.*', 'mm.' . $mmParentKey . ' AS __parent_uid')
   ->from($foreignTable, 'f')
   ->join('f', $mmTable, 'mm', ...)
```
QueryBuilder's `RestrictionContainerInterface` only applies enableFields to the **primary** `from` table — `f` is the primary here, so it should pick up the default restrictions automatically. **However**, this needs verification: when QueryBuilder is instantiated via `getQueryBuilderForTable($foreignTable)`, the default restrictions apply to that table's alias, but depending on TYPO3 version, joined tables and aliased restrictions can interact in non-obvious ways. Worth confirming behavior empirically rather than from doc-reading.

## Recommendation

Worth verifying empirically that a deleted or hidden row on the JOIN target is filtered out of the result set. If it is, this is a non-issue. If it isn't, the JOIN needs explicit `enableFields` application or aliased restrictions.

## Verification prompt

<details>
<summary>Self-contained brief for a verification agent</summary>

You are verifying whether `DataRepository::findHasManyByMM()` correctly excludes deleted/hidden target rows.

### Setup
- Working directory: `~/Sites/tca-api`
- DDEV environment

### Investigation steps
1. Read `Classes/DataAccess/DataRepository.php::findHasManyByMM()` carefully — note how QueryBuilder is obtained and whether explicit restriction handling appears.
2. Cross-check `findHasManyByForeignField()` in the same file for parity — does it handle enable fields differently?
3. Construct a functional test:
   - Use existing `Tests/Functional/Fixtures/sys_category_record_mm.csv` + `sys_categories.csv`.
   - Add one extra row to `sys_categories.csv` with `deleted=1` and add an MM link to it.
   - GET `/api/articles/1` and assert the deleted category does NOT appear in the embedded `categories` field.
   - Repeat with `hidden=1`.
   - Repeat with `starttime` in the future.
4. Run: `ddev exec vendor/bin/phpunit -c phpunit.xml --testsuite Embed`.
5. Consult: https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/Database/RestrictionBuilder/Index.html

### Conclusion gates
- **Confirmed:** deleted/hidden target rows appear in API output.
- **Not an issue:** default QueryBuilder restrictions filter them out automatically.

### Files to read
- `Classes/DataAccess/DataRepository.php`

</details>
