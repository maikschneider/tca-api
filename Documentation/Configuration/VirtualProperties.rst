..  _virtual-properties:

==================
Virtual Properties
==================

Virtual properties are computed fields appended to the serialized output. They
appear after all real columns (and after column callbacks) and can be driven by
a **callable**, a **column processor**, or a **file/image column reference**.

A ``callback`` is not mutually exclusive with a processor or file reference: when
both are present, the processor/file produces the base value and the callback
runs **last** as a final transform. A virtual-property callback always runs —
after all columns and column callbacks, and after its own base value — so it can
read any column or earlier virtual property from ``$serializedRow``.

Callable
========

A callable receives the already-serialized row and the raw DB record, and returns
any serializable value:

..  code-block:: php

    'virtualProperties' => [
        'displayName' => [
            'callback' => [DisplayNameCallable::class, 'build'],
            'groups'   => ['list', 'show'],
        ],
    ],

The callable signature is
``(array $serializedRow, array $rawRow): mixed``.
``$serializedRow`` reflects columns already serialized in this request;
``$rawRow`` is the raw DB record.

Column processor
================

A virtual property can also use a column processor:

..  code-block:: php

    'virtualProperties' => [
        'titleUppercase' => [
            'processor' => UppercaseProcessor::class,
            'groups'    => ['list', 'show'],
        ],
    ],

The processor implements
:php:`MaikSchneider\TcaApi\Serializer\Processing\ColumnProcessorInterface`.
Without a ``column`` key the value passed to the processor is ``null``.

Referencing an existing column
==============================

Add a ``column`` key to source the virtual property's value from an existing DB
column:

..  code-block:: php

    'virtualProperties' => [
        'titleCopy' => [
            'column'    => 'title',
            'processor' => MyProcessor::class,
            'groups'    => ['list', 'show'],
        ],
    ],

The processor receives the column's **raw DB value** instead of ``null``.

File / image column references
------------------------------

For **file/image columns** the file references are fetched automatically and the
result of the virtual property's own file processor is returned. This lets you
expose the same image at different sizes per operation:

..  code-block:: php

    'virtualProperties' => [
        'profile_photo_thumb' => [
            'column'  => 'profile_photo',     // existing type=file column
            'image'   => [
                'maxWidth'    => 200,
                'maxHeight'   => 200,
                'cropVariant' => 'default',   // single variant → flat publicUrl/width/height
            ],
            'groups'  => ['list'],            // small thumb in list only
        ],
        'profile_photo_large' => [
            'column'  => 'profile_photo',
            'image'   => [
                'maxWidth'  => 1600,
                'maxHeight' => 1200,
                // no cropVariant → all variants returned as cropVariants map
            ],
            'groups'  => ['show'],            // full size in show only
        ],
    ],

The virtual property uses its **own** ``image`` config — the referenced
column's original config is ignored.

Visibility gate
===============

Virtual properties respect the same serialization groups as regular columns.
When any column has a ``groups`` key (explicit mode), virtual properties without
``groups`` are excluded:

..  code-block:: php

    'virtualProperties' => [
        'displayName' => [
            'callback' => [DisplayNameCallable::class, 'build'],
            'groups'   => ['list', 'show'],  // required in explicit mode
        ],
        'adminNote' => [
            'callback' => [AdminNoteCallable::class, 'build'],
            'groups'   => ['show'],          // only in show, not list
        ],
    ],

Virtual property options reference
===================================

.. list-table::
   :header-rows: 1
   :widths: 20 80

   * - Key
     - Description
   * - ``callback``
     - Callable ``[ClassName::class, 'method']`` that receives
       ``(array $serializedRow, array $rawRow)`` and returns any serializable
       value. Runs last — after all columns, column callbacks, and its own
       base value — and composes with ``processor``/``image`` (transforms their
       output) rather than replacing them.
   * - ``processor``
     - Column processor class implementing ``ColumnProcessorInterface``.
   * - ``column``
     - Name of an existing DB column to source the value from. When set, the
       processor receives the raw DB value instead of ``null``. For file columns,
       file references are fetched automatically.
   * - ``groups``
     - Array of operations where this virtual property is active (``list``,
       ``show``). Required in explicit mode.
   * - ``image``
     - Image processing options applied when the referenced ``column`` is a
       ``type=file`` column. Accepts the same keys as the column-level ``image``
       config: ``width``, ``height``, ``minWidth``, ``minHeight``, ``maxWidth``,
       ``maxHeight``, ``cropVariant``, ``fileExtension``, ``absolute``. See
       :ref:`image-processor`.
   * - ``route``
     - URL generation options for ``RouteEnhancerProcessor``. Typically used
       as a virtual property to expose a record's frontend URL without a DB
       column. Drives the TYPO3 site router so any ``routeEnhancer`` on the
       target page applies transparently. See :ref:`route-enhancer`.
