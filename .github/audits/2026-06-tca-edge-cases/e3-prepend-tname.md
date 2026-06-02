> **Status:** Possible issue — not yet confirmed. Filed for investigation, triggered by reverse-MM wildcard work on branch `reverse-mm`.

## Symptom

A `type=group` column with a single `allowed` table AND `prepend_tname=true` stores its UIDs in TYPO3 as `<tableName>_<uid>` (e.g. `tt_content_5,tt_content_3`) even though only one table is allowed. The API may parse those values as `0` because it treats them as plain integers.

## Hypothesis

`Classes/Serializer/GroupFieldSerializer.php:95` runs `GeneralUtility::intExplode(',', (string)($row[$column] ?? ''), true)` when single-table dispatch falls through to the UID-list slow path. If `prepend_tname` is set, the raw column value is `tt_content_5,tt_content_3` and `intExplode` returns `[0, 0]` (or filters them out entirely with `removeEmptyValues=true`). The relation is silently empty. Same risk in `Classes/DataAccess/EmbedPreloader.php::collectUidListRelations()`. The multi-table-group path (lines 201-221) already strips the prefix correctly because it's designed for `prepend_tname`-style values; the single-table path is not.

## Recommendation

Worth confirming how common `prepend_tname=true` is on single-allowed-table group columns in TYPO3 13/14 core and contrib extensions, then deciding whether to (a) strip the prefix in the single-table path or (b) route any `prepend_tname=true` config through the multi-table path regardless of `count(allowed)`.

## Verification prompt

<details>
<summary>Self-contained brief for a verification agent</summary>

You are verifying whether `type=group` columns with `prepend_tname=true` and a single allowed table are handled correctly.

### Setup
- Working directory: `~/Sites/tca-api`
- DDEV environment

### Investigation steps
1. Grep the codebase:
   ```bash
   rg "prepend_tname" Classes/ Tests/
   ```
   Expected: zero hits.
2. Read `Classes/Serializer/GroupFieldSerializer.php:32-104` and `Classes/DataAccess/EmbedPreloader.php:82-97`. Confirm both branches assume plain integer UIDs.
3. Locate TCA in TYPO3 core that uses `prepend_tname=true` with `count(allowed)=1`:
   ```bash
   rg "prepend_tname.*=>.*true" vendor/typo3/cms-core/Configuration/TCA/
   ```
4. Construct a fixture: a custom table with `'type'=>'group', 'allowed'=>'pages', 'prepend_tname'=>true`. Insert a row whose group column value is `pages_3`. GET via the API and inspect the response.
5. Consult: https://docs.typo3.org/m/typo3/reference-tca/main/en-us/ColumnsConfig/Type/Group/Index.html#confval-group-prepend-tname

### Conclusion gates
- **Confirmed:** API returns empty array for a single-allowed-table group with `prepend_tname=true` and a non-empty raw column value.
- **Not an issue:** no realistic single-allowed-table config carries `prepend_tname=true` (i.e. it's only used with multi-table groups in practice).

### Files to read
- `Classes/Serializer/GroupFieldSerializer.php`
- `Classes/DataAccess/EmbedPreloader.php`
- `Classes/Utility/UidListParser.php`

</details>
