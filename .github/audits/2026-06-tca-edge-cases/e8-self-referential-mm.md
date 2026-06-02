> **Status:** Possible issue — not yet confirmed. Filed for investigation, triggered by reverse-MM wildcard work on branch `reverse-mm`.

## Symptom

A self-referential MM relation (the MM table joins a table to itself — e.g. `pages.related_pages` where both sides are `pages`) has no fixture or test coverage, so behavior is unknown. Potential failure modes: cycle handling, sort column ambiguity, MM key orientation.

## Hypothesis

`Classes/Serializer/ResourceSerializer.php` already maintains a `$visited` set keyed by `table:uid` for cycle prevention (`Classes/Serializer/GroupFieldSerializer.php:138`). That should cover self-referential cases at any depth. But:
1. Sort orientation: when uid_local and uid_foreign both point at the same table, MM_opposite_field flipping logic still applies — but the conceptual "forward" and "reverse" sides may both be the same column. Behavior under embed is unverified.
2. Preloader: `EmbedPreloader::preloadMm()` does not differentiate self-referential cases; the parent UIDs being fetched and the target UIDs share the same table, which could collide in `$preloaded['rows'][$table]` (probably harmless, but unverified).

## Recommendation

Worth adding a fixture for self-referential MM (e.g. `pages.related_pages`) and asserting both reverse and forward reads, with and without embed, produce correct results. Outcome determines whether any code path needs adjustment.

## Verification prompt

<details>
<summary>Self-contained brief for a verification agent</summary>

You are verifying self-referential MM behavior in the `maikschneider/tca-api` extension.

### Setup
- Working directory: `~/Sites/tca-api`
- DDEV environment

### Investigation steps
1. Look for any existing self-referential test/fixture:
   ```bash
   rg "self.refer|recursive" Tests/
   ```
   Expected: nothing on point.
2. Build a fixture: create a small TYPO3 test table (or extend an existing fixture table) with a `related` column declared as `type=group, allowed='self', MM='self_related_mm'`. Insert rows that link to each other in a cycle (A→B→C→A).
3. GET each via the API at embed depth 0, 1, and 2. Assert IRIs are correct at depth 0, embeds break the cycle at depth ≥1 via the `$visited` guard.
4. Repeat with reverse-side configuration (if applicable).
5. Read `Classes/Serializer/GroupFieldSerializer.php:107-195` (the multi-table path) and `Classes/DataAccess/EmbedPreloader.php::preloadMm()` to verify pool keying doesn't collide.

### Conclusion gates
- **Confirmed:** any of cycle handling, sort orientation, or pool keying produces a wrong response or an exception.
- **Not an issue:** all three behave correctly under self-referential MM.

### Files to read
- `Classes/Serializer/GroupFieldSerializer.php`
- `Classes/Serializer/ResourceSerializer.php`
- `Classes/DataAccess/EmbedPreloader.php`

</details>
