> **Status:** Possible issue — not yet confirmed. Filed for investigation, triggered by reverse-MM wildcard work on branch `reverse-mm`.

## Symptom

`type=category` columns (TYPO3 v11+ syntactic sugar) may not behave identically to an equivalent explicit `type=select`/`type=group` + MM declaration when read through this API.

## Hypothesis

TYPO3 normalizes `type=category` into a standard MM-backed `type=select` (or `type=group`, depending on `renderType`) via `TcaMigration` at boot. If the normalization produces a config whose runtime shape matches what `RelationSerializer` / `GroupFieldSerializer` already handle, this is a non-issue. There is no specific code path for `type=category` in `Classes/` (grep produces zero hits), so the assumption is that normalization is enough. That assumption is worth a focused parity test.

## Recommendation

Worth adding one fixture column with `'type' => 'category'` and asserting its API output matches the explicit-MM equivalent on the same data. Outcome answers whether normalization is silent and complete or whether something needs explicit handling.

## Verification prompt

<details>
<summary>Self-contained brief for a verification agent</summary>

You are verifying that `type=category` columns produce the same API output as an equivalent explicit `type=select` with `MM=sys_category_record_mm`.

### Setup
- Working directory: `~/Sites/tca-api`
- DDEV environment

### Investigation steps
1. Read the TYPO3 reference: https://docs.typo3.org/m/typo3/reference-tca/main/en-us/ColumnsConfig/Type/Category/Index.html
2. Grep for any `type=category` handling in tca-api:
   ```bash
   rg "type.*=>.*'category'|category.*FieldType" Classes/
   ```
3. Build a small TYPO3 test extension fixture with two columns on the same fixture table:
   - `categories_v1` declared as `type=category` (v11+ sugar)
   - `categories_v2` declared as the equivalent explicit `type=select` + `foreign_table=sys_category` + `MM=sys_category_record_mm` + `MM_opposite_field='items'`
4. Insert identical fixture data linking both columns to the same `sys_category` rows.
5. GET each via the API and diff the responses. They should be identical except for column name.
6. Verify the TCA migration applied at runtime:
   ```bash
   ddev exec php -r 'var_export($GLOBALS["TCA"]["yourTable"]["columns"]["categories_v1"]["config"]);'
   ```

### Conclusion gates
- **Confirmed:** API responses differ between `type=category` and its equivalent explicit form.
- **Not an issue:** responses are identical.

### Files to read
- `Classes/Serializer/RelationSerializer.php`
- `Classes/DataAccess/EmbedPreloader.php`

</details>
