> **Status:** Possible issue — not yet confirmed. Filed for investigation, triggered by reverse-MM wildcard work on branch `reverse-mm`.

## Symptom

The OpenAPI / Hydra documentation emitted for a `type=group` column with multiple allowed tables (forward-side multi-table group, or reverse-side wildcard) may describe the property as a single-typed reference rather than a polymorphic union of the actual target resource types.

## Hypothesis

`Classes/OpenApi/OpenApiSchemasBuilder.php` and `Classes/OpenApi/HydraApiDocumentationBuilder.php` build property schemas from TCA. For `type=group, allowed='t1,t2,t3'` the produced schema should be a `oneOf`/`anyOf` union of `t1`, `t2`, `t3` resource schemas. A quick scan of the builders suggests they pick a single foreign-table reference. For the reverse-side wildcard case, the union should be derived from `MM_oppositeUsage` keys. Both need verification.

## Recommendation

Worth inspecting an actual generated OpenAPI document for a multi-table-group column today, and comparing it against the JSON-LD response shape. If the schema undercounts the possible types, OpenAPI consumers will fail validation against the real responses.

## Verification prompt

<details>
<summary>Self-contained brief for a verification agent</summary>

You are verifying whether OpenAPI/Hydra documentation correctly describes polymorphic group columns.

### Setup
- Working directory: `~/Sites/tca-api`
- DDEV environment

### Investigation steps
1. Read `Classes/OpenApi/OpenApiSchemasBuilder.php` and `Classes/OpenApi/HydraApiDocumentationBuilder.php`. Note how `allowed` is parsed for `type=group`.
2. Find an existing multi-table-group fixture/resource (if any) or build one: `'type'=>'group', 'allowed'=>'pages,tt_content'`.
3. Generate the OpenAPI document via the API's documentation endpoint (whatever URL surfaces it — likely `/api/docs.json` or similar; grep for the documentation route).
4. Inspect the property schema for the multi-table column. Compare against what JSON Schema expects for a heterogeneous list: `{ type: 'array', items: { oneOf: [ {...t1}, {...t2} ] } }`.
5. Once the reverse-MM fix lands, repeat for `sys_category.items` with `MM_oppositeUsage` declaring multiple forward tables.

### Conclusion gates
- **Confirmed:** documentation describes a polymorphic column as a single-type reference (e.g. picks first allowed table only).
- **Not an issue:** documentation already emits a `oneOf`/`anyOf` union.

### Files to read
- `Classes/OpenApi/OpenApiSchemasBuilder.php`
- `Classes/OpenApi/HydraApiDocumentationBuilder.php`

</details>
