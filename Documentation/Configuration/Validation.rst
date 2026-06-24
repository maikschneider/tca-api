..  _validation:

==========
Validation
==========

Configure validators per column to enforce data integrity on write operations.
Validators run on every POST, PUT, and PATCH request before the record is written.

Auto-derivation from TCA
========================

TCA API reads your existing TCA configuration at boot time and automatically
derives validators from constraints that are already declared there. **No
configuration is required in your resource definition** — the constraints are
picked up for free.

The following TCA constraints are derived automatically:

.. list-table::
   :header-rows: 1
   :widths: 20 20 20 40

   * - TCA column type
     - TCA key
     - Derived validator
     - Notes
   * - ``input``, ``text``
     - ``config.max``
     - ``maxLength``
     - Derived when ``max > 0``
   * - ``number``
     - ``config.range.lower``
     - ``minValue``
     - Only when ``lower`` is set
   * - ``number``
     - ``config.range.upper``
     - ``maxValue``
     - Only when ``upper`` is set
   * - ``group``, ``inline``, ``file``, ``category``
     - ``config.maxitems``
     - ``maxItems``
     - Always derived when set
   * - ``group``, ``inline``, ``file``, ``category``
     - ``config.minitems``
     - ``minItems``
     - Only when ``minitems > 0``
   * - any
     - ``config.required``
     - ``required`` flag
     - Standard TYPO3 field flag

Derivation is **gap-fill only**: if you declare an explicit validator of the
same type in the resource config, the TCA-derived one is skipped for that type.
Explicit configuration always wins.

..  code-block:: php

    // TCA declares max=255 for 'title', but your resource sets maxLength=20 —
    // the explicit maxLength=20 is kept and 255 is NOT added.
    'title' => [
        'groups'     => ['list', 'show', 'create', 'update'],
        'validators' => [
            ['type' => 'maxLength', 'max' => 20],  // wins over TCA max=255
        ],
    ],

    // 'first_name' has no explicit validators — TCA max=255 is injected automatically.
    'first_name' => [
        'groups' => ['list', 'show', 'create', 'update'],
    ],

    // 'profile_photo' (type=file, maxitems=1 in TCA) — maxItems:1 injected automatically.
    'profile_photo' => [
        'groups' => ['list', 'show', 'create', 'update'],
        'upload' => ['folder' => '1:/uploads/'],
    ],

Opt-out per column
------------------

To disable all TCA derivation for a specific column, set ``'tcaValidation' =>
false``:

..  code-block:: php

    'notes' => [
        'groups'         => ['list', 'show', 'create', 'update'],
        'tcaValidation'  => false,  // no validators derived from TCA for this column
    ],

The ``required`` flag follows the same gap-fill rule: it is only injected from
TCA when the ``required`` key is completely absent from the column config. An
explicit ``'required' => false`` is honored and never overridden.

Validator types
===============

The following validator types are available, both for explicit configuration
and as targets for TCA auto-derivation.

.. list-table::
   :header-rows: 1
   :widths: 15 20 15 50

   * - Type
     - Parameters
     - Error code
     - Description
   * - ``required``
     - *(none)*
     - ``REQUIRED``
     - Field must be present and non-empty on POST/PUT. Set via the ``required``
       column key, not inside ``validators``. Skipped on PATCH for absent fields.
       Auto-derived from TCA ``config.required: true``.
   * - ``maxLength``
     - ``max`` (int)
     - ``MAX_LENGTH``
     - Maximum string length (multi-byte safe).
       Auto-derived from ``input``/``text`` TCA ``config.max``.
   * - ``minLength``
     - ``min`` (int)
     - ``MIN_LENGTH``
     - Minimum string length.
   * - ``maxValue``
     - ``max`` (int|float)
     - ``MAX_VALUE``
     - Maximum numeric value. Skipped for non-numeric values.
       Auto-derived from ``number`` TCA ``config.range.upper``.
   * - ``minValue``
     - ``min`` (int|float)
     - ``MIN_VALUE``
     - Minimum numeric value. Skipped for non-numeric values.
       Auto-derived from ``number`` TCA ``config.range.lower``.
   * - ``maxItems``
     - ``max`` (int)
     - ``MAX_ITEMS``
     - Maximum number of items in an array-valued relation field. Skipped for
       non-array values. Auto-derived from ``group``/``inline``/``file``/
       ``category`` TCA ``config.maxitems``.
   * - ``minItems``
     - ``min`` (int)
     - ``MIN_ITEMS``
     - Minimum number of items. Skipped for non-array values.
       Auto-derived from ``config.minitems`` when ``> 0``.
   * - ``regex``
     - ``pattern`` (string)
     - ``REGEX``
     - PCRE pattern (including delimiters) the value must match. The pattern is
       validated at config load time — broken patterns raise an exception
       immediately rather than silently failing at runtime.

Configuration
=============

Validators are declared in the ``validators`` array of a column definition:

..  code-block:: php

    'columns' => [
        'title' => [
            'groups'     => ['list', 'show', 'create', 'update'],
            'required'   => true,
            'validators' => [
                ['type' => 'maxLength', 'max' => 255],
                ['type' => 'minLength', 'min' => 3],
                ['type' => 'regex', 'pattern' => '/^[\w\s]+$/u'],
            ],
        ],
        'rating' => [
            'groups'     => ['list', 'show', 'create', 'update'],
            'validators' => [
                ['type' => 'minValue', 'min' => 1],
                ['type' => 'maxValue', 'max' => 5],
            ],
        ],
    ],

Error response
==============

Validation failures return **422 Unprocessable Entity** with a Hydra-compatible
error body. All violations are collected and returned together — the request is
not aborted on the first failure.

..  code-block:: json

    {
        "@context": "http://www.w3.org/ns/hydra/context.jsonld",
        "@type": "hydra:Error",
        "hydra:title": "Validation Failed",
        "hydra:description": "2 validation error(s)",
        "violations": [
            {
                "propertyPath": "title",
                "message": "Field 'title' is required.",
                "code": "REQUIRED"
            },
            {
                "propertyPath": "rating",
                "message": "Field 'rating' must not exceed 5.",
                "code": "MAX_VALUE"
            }
        ]
    }

PATCH behaviour
===============

On PATCH requests (partial updates), validation only runs on fields that are
present in the request body. Absent fields — including ``required`` ones — are
skipped. Auto-derived validators follow the same rule.

Custom validators
=================

When the built-in types are not enough, register your own rule by implementing
:php:`MaikSchneider\TcaApi\Validation\ValidatorInterface` and referencing it by
class-string as the validator ``type``. Implementations are auto-discovered via
the ``tca_api.validator`` DI tag — no ``Services.yaml`` entry is needed as long
as your extension uses ``autoconfigure`` (the TYPO3 default).

..  code-block:: php

    'iban' => [
        'groups'     => ['list', 'show', 'create', 'update'],
        'validators' => [
            ['type' => \Acme\Validator\IbanValidator::class, 'options' => ['country' => 'DE']],
        ],
    ],

The validator receives a typed
:php:`MaikSchneider\TcaApi\Validation\ValidationContext` and returns a (possibly
empty) list of :php:`MaikSchneider\TcaApi\Validation\Violation` objects:

..  code-block:: php

    use MaikSchneider\TcaApi\Validation\ValidationContext;
    use MaikSchneider\TcaApi\Validation\ValidatorInterface;
    use MaikSchneider\TcaApi\Validation\Violation;

    final class IbanValidator implements ValidatorInterface
    {
        public function validate(ValidationContext $context): array
        {
            if (is_valid_iban((string)$context->value, $context->option('country'))) {
                return [];
            }

            return [new Violation('Not a valid IBAN.', 'IBAN')];
        }
    }

``ValidationContext`` exposes:

..  list-table::
    :header-rows: 1
    :widths: 25 75

    * - Property
      - Description
    * - ``value``
      - The submitted value of the column being validated.
    * - ``column`` / ``table``
      - Column name and resource table.
    * - ``options``
      - Per-validator options from the config; use ``option($key, $default)``.
    * - ``body``
      - The full request body — enables cross-field rules.
    * - ``partial``
      - ``true`` on PATCH requests.
    * - ``resourceConfig``
      - The full :php:`ApiDefinition` for the resource.

Each returned ``Violation`` carries a ``message`` and a machine-readable
``code``; its ``propertyPath`` defaults to the validated column but can be set
explicitly to point a violation at a different field. Violations merge into the
same **422** Hydra error response as the built-in validators. Built-in and
custom validators can be combined freely on the same column.
