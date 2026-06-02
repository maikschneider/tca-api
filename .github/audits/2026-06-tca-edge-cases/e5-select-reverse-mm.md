> **Status:** Possible issue — not yet confirmed. Filed for investigation, triggered by reverse-MM wildcard work on branch `reverse-mm`.

## Symptom

`type=select` columns that participate in the same reverse-side MM pattern as `type=group` columns (e.g. a forward-side `categories` select-with-MM) may exhibit the same `allowed`/`foreign_table`-wildcard dispatch gap that `type=group` does — or a related dispatch problem entirely.

## Hypothesis

The reverse-MM wildcard fix (branch `reverse-mm`) addresses `type=group` with `allowed='*'`. `type=select` columns can also participate in MM relations and can have `MM_opposite_field`/`MM_oppositeUsage` set. `Classes/Serializer/RelationSerializer.php::resolveHasManyRows()` and the surrounding select-MM code paths may handle `foreign_table` differently. They likely have **no** wildcard analog because `foreign_table` is always a single table for `type=select`, but the **reverse-side detection** via `MM_oppositeUsage` is still relevant when a select column reads its MM table from the reverse side.

## Recommendation

Worth confirming whether any `type=select` column in realistic TYPO3 configurations carries `MM_oppositeUsage` (i.e. is the reverse side of an MM relation) and, if so, whether the current code path emits correct results. The `GroupAllowedResolver` introduced by the reverse-MM work may need a sibling for select columns, or the orientation flip may need to be shared via a common helper.

## Verification prompt

<details>
<summary>Self-contained brief for a verification agent</summary>

You are verifying whether `type=select` columns sharing the reverse-MM pattern need parity with the `type=group` fix.

### Setup
- Working directory: `~/Sites/tca-api`
- DDEV environment
- Reference: branch `reverse-mm` work plan (the umbrella issue links to it)

### Investigation steps
1. Read `Classes/Serializer/RelationSerializer.php` end-to-end, paying attention to how it dispatches `type=select` hasMany.
2. Grep TYPO3 core for `type=select` columns with `MM_oppositeUsage`:
   ```bash
   rg -B 2 -A 10 "MM_oppositeUsage" vendor/typo3/cms-core/Configuration/TCA/
   ```
   Note which target columns are `type=select` vs `type=group`.
3. If any are `type=select`, build a fixture exposing that column via the API and verify the result is well-formed (not crashing on a `*` foreign_table or producing empty arrays).
4. Compare the dispatch surface of select-MM vs group-MM in `RelationSerializer` — list the cases each handles and confirm parity.

### Conclusion gates
- **Confirmed:** at least one realistic select-MM reverse-side configuration is broken.
- **Not an issue:** `type=select` does not support the reverse-MM wildcard convention in practice.

### Files to read
- `Classes/Serializer/RelationSerializer.php`
- `Classes/Filter/MmFilter.php`
- The new `Classes/Tca/GroupAllowedResolver.php` once the reverse-MM PR lands.

</details>
