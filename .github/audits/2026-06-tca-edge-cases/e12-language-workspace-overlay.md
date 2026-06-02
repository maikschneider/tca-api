> **Status:** Possible issue — not yet confirmed. Filed for investigation, triggered by reverse-MM wildcard work on branch `reverse-mm`.

## Symptom

Records returned through an MM JOIN may not get the same language overlay / workspace overlay treatment as records fetched through `DataRepository::findById()` or `findByIds()`. Cross-language or workspace previews may see relations resolve to default-language rows when overlays are expected.

## Hypothesis

`Classes/DataAccess/DataRepository.php::findHasManyByMM()` uses a raw JOIN with `f.*` projection. It does not appear to apply `PageRepository::getRecordOverlay()` or workspace overlay logic to the joined rows. By contrast, `findById()`/`findByIds()` likely route through a code path that respects the active `LanguageAspect` / `WorkspaceAspect` (worth confirming). If MM joins skip overlays while single-record fetches honor them, the same record fetched two ways produces different shapes depending on which path resolved it.

## Recommendation

Worth verifying empirically: GET a parent record with a hasMany MM relation while a non-default language is active, and assert the related rows are returned in the translated form (when translations exist). Compare with the same record fetched directly via `/api/<resource>/<uid>`. Outcome determines whether the JOIN path needs an overlay pass.

## Verification prompt

<details>
<summary>Self-contained brief for a verification agent</summary>

You are verifying that MM-joined relations apply the active language / workspace overlay.

### Setup
- Working directory: `~/Sites/tca-api`
- DDEV environment
- Existing test suites: `LanguageFilteringTest`, `LanguageCachingTest`

### Investigation steps
1. Read `Classes/DataAccess/DataRepository.php`. Compare `findById()` / `findByIds()` against `findHasManyByMM()` and `findHasManyByForeignField()` regarding overlay handling.
2. Read `Tests/Functional/Language/LanguageFilteringTest.php` — does it cover MM relations or only direct reads?
3. Construct a fixture:
   - `sys_categories_multilang.csv` already exists; extend it with translated rows.
   - Link an article to both default and translated categories via `sys_category_record_mm`.
   - GET `/api/articles/1?language=1` with embed and assert the embedded category names appear in the translated form.
4. Repeat for workspace overlay (if workspace test fixtures exist).
5. Consult: https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/PageAndRecord/Overlays.html

### Conclusion gates
- **Confirmed:** MM-joined relations return default-language rows when translations should be returned.
- **Not an issue:** overlays are applied consistently (perhaps via QueryBuilder restrictions configured upstream).

### Files to read
- `Classes/DataAccess/DataRepository.php`
- `Tests/Functional/Language/LanguageFilteringTest.php`
- `Tests/Functional/Language/LanguageCachingTest.php`

</details>
