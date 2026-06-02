> **Status:** Audit umbrella — coordinates 14 sub-investigations. Each linked issue is a **possible** problem flagged during reverse-MM wildcard planning. None is confirmed yet; each carries a self-contained verification prompt for an agent or human to investigate.

## Origin

While planning the fix for `GroupFieldSerializer` crashing on `type=group` columns with `allowed='*'` (the reverse-side MM wildcard convention used by `sys_category.items`), several adjacent code paths and TCA scenarios surfaced as potentially incorrect or unhandled. Rather than expand the reverse-MM PR scope, each finding is filed here for separate investigation.

The reverse-MM wildcard fix itself is being implemented on branch `reverse-mm` and is **not** a sub-issue of this audit. The ISA lives outside the repo at `PAI/MEMORY/WORK/tca-api-reverse-mm-wildcard/ISA.md` (principal-local).

## How to work this audit

For each sub-issue:

1. Open the issue, expand the collapsed **Verification prompt** section.
2. Hand the prompt to an agent (or work it yourself) — the prompt is self-contained and names the files, commands, and conclusion gates.
3. Close the issue with a verdict comment: **Confirmed**, **Not an issue**, or **Defer with reason**.
4. If **Confirmed**, file a follow-up fix issue and link it from the audit issue's comments.

The recommendations in each sub-issue are deliberately neutral — they describe what an investigation should answer, not what the fix should be. Implementation bias is left to the follow-up fix issue.

## Sub-investigations

### Read-path correctness
- [ ] #113 — [Audit E1] Reverse-side MM may use wrong sort column
- [ ] #116 — [Audit E4] findHasManyByMM may skip enableFields on JOIN target
- [ ] #124 — [Audit E12] Workspace/language overlay on MM-joined relations

### Dispatch parity & TCA features
- [ ] #115 — [Audit E3] prepend_tname on single-allowed-table group columns
- [ ] #117 — [Audit E5] type=select reverse-MM dispatch parity with type=group
- [ ] #118 — [Audit E6] type=category sugar — verify parity with explicit MM
- [ ] #119 — [Audit E7] type=group with maxitems=1 may not unwrap to hasOne
- [ ] #120 — [Audit E8] Self-referential MM has no test coverage

### Missing TCA support
- [ ] #114 — [Audit E2] MM_table_where TCA option ignored
- [ ] #121 — [Audit E9] MmFilter does not recognize MM_oppositeUsage
- [ ] #123 — [Audit E11] MM_hasUidField legacy MM tables not considered

### Documentation accuracy
- [ ] #122 — [Audit E10] OpenAPI/Hydra may not emit union for polymorphic group

### Deferred from reverse-MM PR (per principal direction)
- [ ] #125 — [Audit Q2] Reverse-MM cache-tag emission for newly-linked records
- [ ] #126 — [Audit Q3] Reverse-MM write support (enhancement)

## Suggested verification order

Lowest-cost / highest-leverage first:

1. **#116 (E4 enableFields)** — five-minute test, security-relevant if confirmed.
2. **#113 (E1 sort column)** — straightforward fixture test against `sorting_foreign`.
3. **#124 (E12 overlays)** — extends an existing test class.
4. **#122 (E10 OpenAPI union)** — purely doc generation, no DB needed.
5. **#118 (E6 type=category)** — direct parity check.
6. **#119 (E7 maxitems=1)** — boundary check.
7. **#115 (E3 prepend_tname)** — legacy-config parity check.
8. **#117 (E5 select-MM parity)**, **#121 (E9 MmFilter)** — wait until reverse-MM lands.
9. **#114 (E2 MM_table_where)**, **#120 (E8 self-referential MM)** — lower priority feature gaps.
10. **#123 (E11 MM_hasUidField)**, **#125 (Q2 cache tags)**, **#126 (Q3 reverse writes)** — future-looking, revisit after the above conclude.

## Filing date

2026-06-02
