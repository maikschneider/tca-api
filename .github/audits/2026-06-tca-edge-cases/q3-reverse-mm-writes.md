> **Status:** Enhancement — not yet implemented. Deferred from reverse-MM wildcard work on branch `reverse-mm` (principal answered: read-only for now, file enhancement).

## Goal

Once reverse-MM reads work (`GET /api/categories/1` returns `items` as a polymorphic IRI list), enable write operations on those reverse-side relations:
- POST/PATCH `/api/categories/1` with an `items` payload should create/update MM rows linking forward-side records back to this category.
- DELETE semantics on the reverse side: clear all forward-side links, or only the specified ones.

## Open design questions

1. **Payload shape:** for a polymorphic reverse-side, the input must name BOTH the target table and the target UID. JSON-LD `@id` references are the natural carrier: `"items": [ "/api/articles/3", "/api/pages/5" ]`. Should the API resolve these strictly (404 if any referenced resource doesn't exist) or lenient (skip unresolvable)?
2. **MM row provenance:** each MM row carries `tablenames` and `fieldname`. The `fieldname` for the forward side is named by `MM_oppositeUsage[tablenames]`. When that array lists multiple fields per table, the API must choose one. Disambiguate via an extended payload (`{ "@id": "/api/articles/3", "field": "categories" }`)? Or reject if `MM_oppositeUsage` is non-singleton per table?
3. **Sorting:** does the API set `sorting`, `sorting_foreign`, or both? In what order are the items inserted?
4. **Atomic semantics:** if 3 of 5 referenced records exist, do we link the 3 and skip 2, or fail the whole request?
5. **Permissions:** writing the reverse side typically requires write permission on the forward-side table (you're modifying its relations). The current security model needs an explicit rule for this — `ownership` and `security` keys per forward table?
6. **DataHandler integration:** does this route through TYPO3's DataHandler (so backend hooks/preview/workspaces fire correctly) or do we write the MM table directly?
7. **`MM_table_where` and `MM_match_fields`:** the API must populate any required constants when inserting rows (see E2 for `MM_table_where` handling status).

## Recommendation

Worth scoping this as a follow-up RFC/design issue after E1–E12 investigations conclude — several of them (E1 sort orientation, E2 MM_table_where, E4 enableFields, E9 MmFilter) interact directly with reverse-side write semantics and should land first.

## Verification prompt

<details>
<summary>Self-contained brief for a design/implementation agent</summary>

You are designing reverse-side MM write support for the `maikschneider/tca-api` extension.

### Required reading first
- `Classes/OperationHandler/CreateHandler.php`
- `Classes/OperationHandler/UpdateHandler.php`
- `Classes/DataAccess/RelationInputResolver.php`
- `Tests/Functional/Write/WriteRelationsTest.php`
- The reverse-MM read implementation once it lands on `reverse-mm` (or `main` after merge).

### Design deliverables
1. Decide payload shape (strict-`@id` references, optional `field` disambiguator, sorting hints).
2. Decide permission model (per-forward-table check, ownership inheritance, or new explicit rule).
3. Decide DataHandler routing (use TYPO3 DataHandler vs direct MM writes — write up tradeoffs).
4. Decide atomic semantics (best-effort vs all-or-nothing).
5. List dependencies on E1–E12 — which must conclude before write support is safe to ship.

### Implementation gates
- Read-side tests for `sys_category.items` are green (the reverse-MM PR).
- E1 (sort orientation), E2 (`MM_table_where`), E4 (enableFields on JOIN), and E9 (MmFilter parity) investigations are concluded.
- A focused RFC document is approved by the principal before code lands.

### Reference
- TYPO3 DataHandler reference: https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/Typo3CoreEngine/Database/Index.html
- TYPO3 MM TCA reference: https://docs.typo3.org/m/typo3/reference-tca/main/en-us/ColumnsConfig/CommonProperties/Mm.html

### Decision note
Principal answered Q3 on 2026-06-02: reverse-side writes deferred from the reverse-MM PR; file as enhancement. This is that enhancement.

</details>
