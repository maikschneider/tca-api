> **Status:** Possible issue — not yet confirmed. Filed for investigation, triggered by reverse-MM wildcard work on branch `reverse-mm`.

## Symptom

A `type=group` column with `maxitems=1` may be serialized as an array (hasMany shape) when TCA convention treats it as a single relation (hasOne shape). The same column is correctly unwrapped to a scalar by `FileFieldSerializer` for `type=file` (`Classes/Serializer/FileFieldSerializer.php:38-40`), but the group path may not check `maxitems`.

## Hypothesis

In TYPO3 TCA, `maxitems=1` on a relational column is the conventional way to express a single relation when the field type is otherwise hasMany. `Classes/Serializer/FileFieldSerializer.php:38` honors this: `if (($field->getConfiguration()['maxitems'] ?? 0) === 1) { return isset($fileRefs[0]) ? ... : null; }`. `Classes/Serializer/GroupFieldSerializer.php::serialize()` does not check `maxitems` and always returns an array. Downstream consumers expecting a single object for such columns get a one-element array instead.

## Recommendation

Worth investigating which existing fixtures or real-world configs use `'type'=>'group', 'maxitems'=>1` and whether the array shape is causing any downstream surprise. Decision: either unwrap to scalar/null (matching file behavior), keep as array (consistency with other hasMany), or expose both via a column option.

## Verification prompt

<details>
<summary>Self-contained brief for a verification agent</summary>

You are verifying whether `type=group` columns with `maxitems=1` are correctly shaped in API responses.

### Setup
- Working directory: `~/Sites/tca-api`
- DDEV environment

### Investigation steps
1. Read `Classes/Serializer/GroupFieldSerializer.php` and `Classes/Serializer/FileFieldSerializer.php`. Compare how each handles `maxitems`.
2. Grep TYPO3 core for `type=group` + `maxitems=1`:
   ```bash
   rg -B 3 -A 8 "'type'\s*=>\s*'group'" vendor/typo3/cms-core/Configuration/TCA/ | rg -B 4 -A 1 "maxitems.*=>\s*1"
   ```
3. Build a fixture: a column with `'type'=>'group', 'allowed'=>'pages', 'maxitems'=>1`. Insert a row with one related page UID. GET via the API and inspect the shape of the column in the response.
4. Cross-reference: a `type=select` with `maxitems=1` — how does the API shape that column? If it unwraps to scalar, group should do the same for consistency. If not, the inconsistency is between file vs the rest.

### Conclusion gates
- **Confirmed:** `type=group` with `maxitems=1` returns a single-element array where consumers expect a scalar/object.
- **Not an issue:** array shape is intentional and documented.

### Files to read
- `Classes/Serializer/GroupFieldSerializer.php`
- `Classes/Serializer/FileFieldSerializer.php`
- `Classes/Serializer/RelationSerializer.php`

</details>
