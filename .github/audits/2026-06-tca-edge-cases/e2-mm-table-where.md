> **Status:** Possible issue — not yet confirmed. Filed for investigation, triggered by reverse-MM wildcard work on branch `reverse-mm`.

## Symptom

A resource whose `type=group` MM column has `MM_table_where` configured in TCA may return relations that should have been filtered out by that clause, because the API ignores it.

## Hypothesis

`MM_table_where` is a documented TCA option that lets an MM column attach an extra WHERE constraint when joining the MM table (e.g. `'MM_table_where' => '{#uid_local}=###REC_FIELD_tableName###'`). A search of `Classes/` shows no references to it. `Classes/DataAccess/DataRepository.php::findHasManyByMM()` builds its WHERE only from `uid_local IN (...)` and `MM_match_fields`. Without honoring `MM_table_where`, any caller relying on it for scoping (commonly: language, storage pid, or status filtering) gets unfiltered results.

## Recommendation

Worth investigating which TYPO3 core / community extensions actually use `MM_table_where` (sample: pages, sys_file_reference, content elements with sys_file_collection). If real-world configurations carry semantic constraints there, the API needs to honor them; otherwise this is a minor parity gap worth a deprecation-style note rather than implementation.

## Verification prompt

<details>
<summary>Self-contained brief for a verification agent</summary>

You are verifying whether the `maikschneider/tca-api` extension honors the TCA `MM_table_where` option for `type=group` and `type=select` MM relations.

### Setup
- Working directory: `~/Sites/tca-api`
- DDEV environment — prefix commands with `ddev exec`

### Investigation steps
1. Grep the codebase for any handling of `MM_table_where`:
   ```bash
   rg "MM_table_where" Classes/ Tests/
   ```
   Expected: zero hits.
2. Read `Classes/DataAccess/DataRepository.php::findHasManyByMM()`. Note the WHERE composition uses only `uid_local IN (...)` and `MM_match_fields`.
3. Look at TYPO3 core TCA for tables that ship with `MM_table_where`. Candidates worth grepping in a TYPO3 install:
   ```bash
   rg "MM_table_where" vendor/typo3/cms-core/Configuration/TCA/
   ```
4. Pick one real-world config that uses it. Build a fixture resource that mirrors that config and assert the API result matches what TYPO3's BE listing shows for the same record.
5. Consult TYPO3 docs: https://docs.typo3.org/m/typo3/reference-tca/main/en-us/ColumnsConfig/CommonProperties/Mm.html — section on `MM_table_where`, including the `###REC_FIELD_*###` placeholder semantics.

### Conclusion gates
- **Confirmed gap:** at least one real-world TCA config relies on `MM_table_where` for correctness and the API returns a different result set than TYPO3 BE.
- **Not an issue:** no realistic configuration depends on `MM_table_where` for read-side correctness, or the placeholder semantics make it write-only-relevant.

### Files to read
- `Classes/DataAccess/DataRepository.php`
- `Classes/DataAccess/EmbedPreloader.php`

</details>
