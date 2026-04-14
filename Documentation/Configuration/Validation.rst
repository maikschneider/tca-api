..  _validation:

==========
Validation
==========

Configure validators per column to enforce data integrity on write operations.

Validator types
===============

.. list-table::
   :header-rows: 1
   :widths: 15 20 65

   * - Type
     - Parameters
     - Description
   * - ``required``
     - *(none)*
     - Enforced via the ``required`` column key. The field must be present and
       non-empty on POST/PUT. Skipped on PATCH if the field is absent.
   * - ``maxLength``
     - ``max`` (int)
     - Maximum string length.
   * - ``minLength``
     - ``min`` (int)
     - Minimum string length.
   * - ``regex``
     - ``pattern`` (string)
     - PCRE pattern the value must match.

Configuration
=============

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
    ],

Error response
==============

Validation failures return **422 Unprocessable Entity** with a Hydra-compatible
error response:

..  code-block:: json

    {
        "@context": "http://www.w3.org/ns/hydra/context.jsonld",
        "@type": "hydra:Error",
        "hydra:title": "Validation Failed",
        "hydra:description": "1 validation error(s)",
        "violations": [
            {
                "propertyPath": "title",
                "message": "Field 'title' is required.",
                "code": "REQUIRED"
            }
        ]
    }

PATCH behaviour
===============

On PATCH requests (partial updates), the ``required`` check is **skipped** for
fields that are not present in the request body. Only the submitted fields are
validated.
