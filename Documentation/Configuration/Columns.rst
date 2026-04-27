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
     - Override the related resource used for relation columns. Normally TCA
       API looks up the child resource by its DB ``foreign_table``. Set this
       when multiple resources are registered for the same table, or to
       explicitly control which resource's security and column config applies
       to nested writes. See :ref:`relations` for a full example.
   * - ``processor``
     - Column processor class. Does **not** trigger explicit mode. See
       :ref:`column-processors`.
   * - ``validators``
     - Array of validation rules. See :ref:`validation`.
   * - ``upload``
     - Enable file upload for this column via ``multipart/form-data`` requests.
       Must be an array with at least a ``folder`` key (FAL storage reference,
       e.g. ``1:/uploads/``). See :ref:`file-uploads` for all options.
   * - ``image``
     - Image processing options for ``ImageProcessor`` columns. Controls
       dimensions, crop variant selection, format conversion, and URL mode.
       See :ref:`image-processor` for all options.

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

..  _image-processor:

``ImageProcessor``
   Serialises image file references (FAL) with full crop-variant support and
   configurable processing options. By default the processor is used for every
   ``type=file`` TCA column that has no explicit ``processor`` key.

   Options are controlled via the ``image`` sub-key on the column definition:

   ..  code-block:: php

       use MaikSchneider\TcaApi\Serializer\FileProcessing\ImageProcessor;

       'hero_image' => [
           'groups'    => ['list', 'show'],
           'processor' => ImageProcessor::class,  // optional — also the default for type=file
           'image'     => [
               'maxWidth'      => 1200,
               'maxHeight'     => 800,
               'fileExtension' => 'webp',
               // omit cropVariant → all variants returned as a map
           ],
       ],

   **Output mode 1 — all variants** (``cropVariant`` omitted or ``null``):

   Every crop variant stored on the file reference is processed and returned
   as a ``cropVariants`` map:

   ..  code-block:: json

       {
           "hero_image": {
               "publicUrl": "/fileadmin/hero.jpg",
               "mimeType": "image/jpeg",
               "fileSize": 204800,
               "metadata": {
                   "title": "Hero",
                   "alternative": "A hero image",
                   "description": null,
                   "copyright": "© 2026"
               },
               "cropVariants": {
                   "default": {
                       "publicUrl": "/fileadmin/_processed_/hero_c.webp",
                       "width": 1024,
                       "height": 512
                   },
                   "mobile": {
                       "publicUrl": "/fileadmin/_processed_/hero_m.webp",
                       "width": 375,
                       "height": 200
                   }
               }
           }
       }

   **Output mode 2 — single variant** (``cropVariant`` set to a variant ID):

   Only that variant is processed and the result is inlined as the top-level
   image — no ``cropVariants`` key:

   ..  code-block:: php

       'hero_image' => [
           'groups' => ['list', 'show'],
           'image'  => [
               'maxWidth'    => 1200,
               'cropVariant' => 'default',    // single-variant mode
           ],
       ],

   ..  code-block:: json

       {
           "hero_image": {
               "publicUrl": "/fileadmin/_processed_/hero_c.webp",
               "width": 1200,
               "height": 600,
               "mimeType": "image/jpeg",
               "fileSize": 204800,
               "metadata": { "title": "Hero", "alternative": null, "description": null, "copyright": null }
           }
       }

   .. list-table:: ``image`` key reference
      :header-rows: 1
      :widths: 20 15 65

      * - Key
        - Type
        - Description
      * - ``width``
        - ``string``
        - Target width. Accepts a plain integer (``"400"``), crop-scale
          (``"400c"``), or scale-down-only (``"400m"``). Mutually usable with
          ``maxWidth``.
      * - ``height``
        - ``string``
        - Target height — same notation as ``width``.
      * - ``minWidth``
        - ``int``
        - Minimum width in pixels. Must be a positive integer.
      * - ``minHeight``
        - ``int``
        - Minimum height in pixels. Must be a positive integer.
      * - ``maxWidth``
        - ``int``
        - Maximum width in pixels. Must be a positive integer.
      * - ``maxHeight``
        - ``int``
        - Maximum height in pixels. Must be a positive integer.
      * - ``cropVariant``
        - ``string``
        - Crop variant identifier (e.g. ``'default'``, ``'mobile'``). When set,
          only that variant is processed and the URL is returned as top-level
          ``publicUrl`` (no ``cropVariants`` key). When omitted, all variants
          are returned as a ``cropVariants`` map.
      * - ``fileExtension``
        - ``string``
        - Target file extension for format conversion, e.g. ``'webp'``.
      * - ``absolute``
        - ``bool``
        - Force an absolute URL. Default: ``false``.

Custom processors must implement
:php:`MaikSchneider\TcaApi\Serializer\Processing\ColumnProcessorInterface`.
