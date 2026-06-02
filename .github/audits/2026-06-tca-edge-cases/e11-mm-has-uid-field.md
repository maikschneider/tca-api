> **Status:** Possible issue — not yet confirmed. Filed for investigation, triggered by reverse-MM wildcard work on branch `reverse-mm`. Likely future-only, irrelevant until reverse-side writes land.

## Symptom

Legacy MM tables (pre-TYPO3 v6) carry a `uid` primary-key column on the MM row itself (`MM_hasUidField=true`). This API does not consider that column. For reads it's likely harmless; for writes (when reverse-side writes are eventually implemented) it could matter.

## Hypothesis

`MM_hasUidField` is a documented TCA option. No reference exists in `Classes/`. For SELECT statements the column is irrelevant since we project specific columns (`uid_local`, `uid_foreign`, etc.) and never `*`. For INSERT/UPDATE/DELETE on the MM table, `MM_hasUidField=true` may require addressing rows by their `uid` rather than by the `(uid_local, uid_foreign)` composite key. Without write support today, this is theoretical.

## Recommendation

Worth documenting that `MM_hasUidField=true` MM tables are not explicitly supported, and revisiting when reverse-side writes are implemented. Investigation outcome answers whether this needs an explicit deprecation/incompatibility note in the README.

## Verification prompt

<details>
<summary>Self-contained brief for a verification agent</summary>

You are verifying whether `MM_hasUidField=true` MM tables work correctly with this extension for reads.

### Setup
- Working directory: `~/Sites/tca-api`
- DDEV environment

### Investigation steps
1. Grep:
   ```bash
   rg "MM_hasUidField" Classes/ Tests/
   ```
   Expected: zero hits.
2. Find any real-world TCA config that ships `MM_hasUidField=true`:
   ```bash
   rg "MM_hasUidField" vendor/typo3/
   ```
3. If any are found, build a fixture mirroring that config and assert API reads work.
4. Consult: https://docs.typo3.org/m/typo3/reference-tca/main/en-us/ColumnsConfig/CommonProperties/Mm.html — search for `MM_hasUidField`.

### Conclusion gates
- **Confirmed gap:** reads on an `MM_hasUidField=true` MM table return wrong data.
- **Not an issue:** SELECT-only access pattern is insensitive to the presence of an extra `uid` column.
- **Defer:** mark as future concern, file under reverse-side writes issue.

### Files to read
- `Classes/DataAccess/DataRepository.php`
- `Classes/DataAccess/EmbedPreloader.php`

</details>
