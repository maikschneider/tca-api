> **Status:** Possible issue — not yet confirmed. Filed for investigation, deferred from reverse-MM wildcard work on branch `reverse-mm` (principal answered: defer).

## Symptom

A cached API response that includes a reverse-side MM relation (e.g. `/api/categories/1` embedding `items`) does NOT get invalidated when one of the forward-side records (e.g. a `tt_content` or `tx_news_domain_model_news` row that links back to that category) is created, updated, or deleted in the TYPO3 backend.

## Hypothesis

`Classes/Cache/CacheTagCollector` adds tags via `ResourceSerializer::serialize()` for every serialized record — `{table}_{uid}` and `{table}`. For the reverse-MM case, when `sys_category/1` is serialized with embedded items, the embedded records ARE serialized so their tables and UIDs DO get tagged. **However**, the response is also cached against `sys_category` tags, and `CacheInvalidationHook` only invalidates by the table whose record was just edited. So:
1. Editing a `tt_content` row that links to category 1 → invalidation fires for `tt_content` tag → the cached category response IS invalidated (because it carries that tag through embed). **This may already work.**
2. Editing a `tt_content` row to NEWLY LINK to category 1 (the row didn't previously appear in the embed) → the cached response has NO tag for this `tt_content` UID yet → invalidation does NOT fire for the cached category response. **This is likely the real gap.**

## Recommendation

Worth designing a verification scenario for case (2) above. Possible resolutions if confirmed: (a) tag cached reverse-side responses with the MM table itself; (b) tag with all forward tables listed in `MM_oppositeUsage` so any insert/update to those tables invalidates dependent reverse-side caches; (c) document the gap and recommend `parametersToIgnore` or short cache lifetimes for reverse-MM resources.

## Verification prompt

<details>
<summary>Self-contained brief for a verification agent</summary>

You are verifying cache invalidation behavior for reverse-MM API responses.

### Setup
- Working directory: `~/Sites/tca-api`
- DDEV environment
- Depends on the reverse-MM wildcard fix landing on branch `reverse-mm`.

### Investigation steps
1. Read `Classes/Cache/CacheTagCollector.php`, `Classes/Cache/CacheDefinition.php`, `Classes/Cache/CacheInvalidationHook.php`, `Classes/Dispatcher/RequestDispatcher.php` (cache key building + tag emission).
2. Once reverse-MM is exposed, configure caching on the `Categories` fixture resource (`cache.enabled = true`).
3. Test case A — UPDATE of already-linked record:
   - GET `/api/categories/1` (with embed=items) → MISS, response cached.
   - In TYPO3 backend, edit the title of an article that's already linked to category 1.
   - GET `/api/categories/1` again — assert MISS again (cache invalidated) and the response carries the updated title.
4. Test case B — NEW link inserted:
   - GET `/api/categories/1` → MISS, response cached (with items = [article 1, article 2]).
   - In TYPO3 backend, link a new article (article 3) to category 1 (insert into `sys_category_record_mm`).
   - GET `/api/categories/1` — does the response include article 3, or is the stale response served?
5. Read `Classes/Serializer/ResourceSerializer.php::serialize()` to see exactly which `addTag` calls fire and what tags they emit during reverse-MM serialization.

### Conclusion gates
- **Confirmed gap:** case B serves stale responses.
- **Not an issue:** both cases invalidate correctly.

### Files to read
- `Classes/Cache/*.php`
- `Classes/Dispatcher/RequestDispatcher.php`
- `Classes/Serializer/ResourceSerializer.php`

### Decision note
Principal answered Q2 on 2026-06-02: no cache-tag handling in the reverse-MM PR; file as separate investigation for follow-up. This is that issue.

</details>
