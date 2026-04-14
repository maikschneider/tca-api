..  _columns:

=======
Columns
=======

The ``columns`` section of a resource definition controls which database columns
are exposed and how they behave. Each entry maps to a database column name.

Visibility modes
================

TCA API has two visibility modes. The mode is **auto-detected** per resource:

Default mode
   Active when **no column** has ``groups`` set. All non-system TCA columns
   (i.e. not ``hidden``, ``deleted``, ``tstamp``, ``crdate``, language/workspace
   fields) are automatically exposed for read and write.

Explicit mode
   Active as soon as **any column** declares ``groups``. Only columns with a
   matching ``groups`` entry are exposed; all others are hidden.

Serialization groups
====================

Use ``groups`` to control visibility per operation:

..  code-block:: php

    'columns' => [
        'title'  => ['groups' => ['list', 'show', 'create', 'update']],  // everywhere
        'teaser' => ['groups' => ['list']],                              // list only
        'body'   => ['groups' => ['show']],                              // detail view only
        'secret' => ['groups' => []],                                    // never exposed
    ],

Valid group names: ``list``, ``show``, ``create``, ``update``.

Column options reference
========================

All keys are optional.

.. list-table::
   :header-rows: 1
   :widths: 20 80

   * - Key
     - Description
   * - ``type``
     - Data type hint for OpenAPI schema (e.g. ``string``, ``integer``).
   * - ``readable``
     - ``true`` — include in responses. Legacy option; use ``groups`` instead.
   * - ``writable``
     - ``true`` — accept in create/update requests. Legacy option; use ``groups``
       instead.
   * - ``groups``
     - Array of operations where this column is active — triggers explicit mode
       (``list``, ``show``, ``create``, ``update``).
   * - ``required``
     - Require on POST/PUT (skipped on PATCH if absent).
   * - ``embed``
     - ``true`` or ``['depth' => N]`` — inline related records instead of shallow
       stubs. See :ref:`relations`.
   * - ``resourceName``
     - Override related resource name for relation columns.
   * - ``processor``
     - Column processor class. Does **not** trigger explicit mode. See
       :ref:`column-processors`.
   * - ``validators``
     - Array of validation rules. See :ref:`validation`.

..  _column-processors:

Column processors
=================

Column processors transform values during serialization. The extension ships two
built-in processors:

``FileProcessor``
   Serialises file references (FAL). Useful for file download columns.

   ..  code-block:: php

       use MaikSchneider\TcaApi\Serializer\FileProcessing\FileProcessor;

       'downloads' => [
           'groups'    => ['list', 'show'],
           'processor' => FileProcessor::class,
       ],

``TypoLinkProcessor``
   Resolves TYPO3 typolinks to full URLs.

   ..  code-block:: php

       use MaikSchneider\TcaApi\Serializer\Processing\TypoLinkProcessor;

       'article_url' => [
           'groups'    => ['list', 'show'],
           'processor' => TypoLinkProcessor::class,
       ],

Custom processors must implement
:php:`MaikSchneider\TcaApi\Serializer\Processing\ColumnProcessorInterface`.
