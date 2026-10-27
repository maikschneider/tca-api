# OpenAPI response contract

Every response a functional test receives through `ApiFunctionalTestCase` is
validated against the OpenAPI spec of the site that served it
(`OpenApiResponseValidator`, JSON Schema 2020-12). Object schemas are closed
for the check, so a property the API sends but the spec does not list fails
as well.

A mismatch fails the test, unless it is listed in the baseline of the installed
TYPO3 major version, `openapi-contract-baseline.v13.txt` or
`openapi-contract-baseline.v14.txt` (core TCA differs between them). The
baselines hold the mismatches known when the check was introduced
([#259](https://github.com/maikschneider/tca-api/issues/259)). They may only
shrink: a fix removes its lines, new code adds none.

Besides the body, the check reports a status code the operation doesn't
document, an empty body or a non-JSON content type where a JSON body is
documented.

## Regenerating the baseline

After fixing a mismatch, regenerate the baselines so its lines disappear:

```bash
ddev composer test:contract-baseline
```

This runs the functional suite with `TCA_API_CONTRACT_COLLECT` set, which
collects mismatches into a file instead of failing, and writes the sorted,
unique result to the baseline of the installed TYPO3 version. A failing run
leaves the baseline untouched. Run it once per supported version, e.g. after
`ddev composer update --with=typo3/cms-core:^13.4 -W`. Review the diff: it
should only remove lines.

## Signatures

```text
GET /_api/articles 200 /hydra:view/hydra:next: type (string, got null)
│   │              │   │                       │
│   │              │   │                       └ failing keyword and details
│   │              │   └ path in the response body, list indices as *
│   │              └ status code
│   └ path template of the operation in the spec
└ method
```

A test that breaks the spec on purpose can opt out with
`protected bool $validateResponsesAgainstOpenApi = false;`.
