..  _columns:

=======
Columns
=======

The ``columns`` section of a resource definition controls which database columns
are exposed and how they behave. Each entry maps to a database column name.

Visibility modes
================

TCA_API has two visibility modes. The mode is **auto-detected** per resource:

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
   * - ``nestedWrite``
     - Access role required to create a related record **through this column**.
       Also opts the column into nested creation when the child table has no
       resource of its own. Same shapes as a ``security`` entry. See
       :ref:`relations`.
   * - ``processor``
     - Column processor class. Does **not** trigger explicit mode. See
       :ref:`column-processors`.
   * - ``callback``
     - ``[ClassName::class, 'method']`` tuple invoked after all columns and
       relations are resolved (but before virtual properties). Its return
       value replaces the column's value. See :ref:`column-callbacks`.
   * - ``validators``
     - Array of validation rules. See :ref:`validation`.
   * - ``link``
     - Allow JSON writes to reference an existing FAL file on this column, and
       declare which files are in scope (``folders`` and/or ``check``). Absent
       means links are rejected. See :ref:`linking-existing-files`.
   * - ``upload``
     - Enable file upload for this column via ``multipart/form-data`` requests.
       Must be an array with at least a ``folder`` key (FAL storage reference,
       e.g. ``1:/uploads/``). See :ref:`file-uploads` for all options.
   * - ``image``
     - Image processing options for ``ImageProcessor`` columns. Controls
       dimensions, crop variant selection, format conversion, and URL mode.
       See :ref:`image-processor` for all options.
   * - ``route``
     - URL generation options for ``RouteEnhancerProcessor`` columns and
       virtual properties. Drives the TYPO3 site router so any
       ``routeEnhancer`` configured on the target page applies transparently.
       See :ref:`route-enhancer` for all options.

..  _field-type-support:

Field type support
==================

The serializer automatically handles all TYPO3 TCA field types. Relational types
are resolved via dedicated serializers; scalar types that store encoded data are
decoded before output; sensitive types are excluded entirely.

.. list-table::
   :header-rows: 1
   :widths: 20 40 40

   * - TCA type
     - Handling
     - Output
   * - ``file``
     - ``FileFieldSerializer`` — auto-selects ``ImageProcessor`` or
       ``FileProcessor``
     - Processed file/image object(s)
   * - ``category``
     - ``RelationSerializer``
     - Shallow stub or embedded record
   * - ``select`` (relational)
     - ``RelationSerializer``
     - Shallow stub or embedded record
   * - ``inline``
     - ``RelationSerializer``
     - Shallow stub or embedded record
   * - ``group``
     - ``GroupFieldSerializer``
     - Shallow stub or embedded record
   * - ``json``
     - Auto-decoded via ``json_decode``
     - Decoded array/object
   * - ``imageManipulation``
     - Auto-decoded via ``json_decode``
     - Decoded crop config object
   * - ``flex``
     - Auto-decoded via ``GeneralUtility::xml2array``
     - Decoded associative array
   * - ``datetime``
     - Auto-formatted to ISO 8601 (``DateTimeInterface::ATOM``)
     - ``"2024-01-01T00:00:00+00:00"`` or ``null``
   * - ``link``
     - Auto-applies ``TypoLinkProcessor``
     - Resolved public URL string
   * - ``password``
     - **Excluded** — never appears in API responses
     - *(column omitted)*
   * - ``input``, ``text``, ``number``, ``email``, ``color``, ``country``,
       ``slug``, ``radio``, ``select`` (static)
     - Raw DB value
     - String, integer, or appropriate scalar
   * - ``check``
     - Raw DB value
     - Bitmask integer
   * - ``language``
     - Excluded by ``TcaColumnDiscovery`` via ``ctrl.languageField``
     - *(column omitted)*
   * - ``folder``, ``none``, ``passthrough``, ``user``
     - Raw DB value
     - Implementation-defined

An explicit ``processor`` on a column definition always overrides the automatic
handling described above.

..  _datetime-round-trips:

Datetime round-trips
====================

``datetime`` columns are symmetric: responses always carry a genuine UTC instant,
and an instant sent back on ``create``/``update`` is stored as that same instant.
This holds for both persistence modes (Unix timestamp and native ``dbType``), on
every supported TYPO3 version, and across DST boundaries — the API never applies
the server's UTC offset to your value.

..  code-block:: json

    {"published_at": "2026-08-15T12:30:00Z"}
    {"published_at": "2026-08-15T14:30:00+02:00"}
    {"published_at": 1786797000}

All three are the same instant and round-trip identically; a ``GET`` returns the
canonical UTC form ``"2026-08-15T12:30:00+00:00"``.

..  note::

    A value sent **without** a timezone designator (``"2026-08-15T12:30:00"`` or
    ``"2026-08-15 12:30:00"``) is interpreted as UTC, matching the response
    format. Send an explicit offset whenever you mean local time.

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

``RouteEnhancerProcessor``
   Generates a frontend URL per record from a typed ``route`` config and
   defers URL construction to the TYPO3 site router, so any configured
   ``routeEnhancer`` (e.g. an Extbase News plugin) applies transparently.
   Most often used on a virtual property:

   ..  code-block:: php

       use MaikSchneider\TcaApi\Serializer\Processing\RouteEnhancerProcessor;

       'virtualProperties' => [
           'url' => [
               'processor' => RouteEnhancerProcessor::class,
               'route'     => [
                   'pid'        => '{$tca_api.news.detailPid}',
                   'extension'  => 'News',
                   'plugin'     => 'Pi1',
                   'controller' => 'News',
                   'action'     => 'detail',
                   'arguments'  => ['news' => '{uid}'],
               ],
           ],
       ],

   See :ref:`route-enhancer` for placeholder grammar, language handling,
   and the full options reference.

..  _image-processor:

``ImageProcessor``
   Serialises image file references (FAL) with full crop-variant support and
   configurable processing options. By default the processor is used for every
   ``type=file`` TCA column that has no explicit ``processor`` key.

   ``uid``, ``name`` and ``extension`` identify the underlying ``sys_file``, not
   the file reference — they are what a client matches a file on across
   responses. Both processors emit them.

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
               "uid": 12,
               "name": "hero.jpg",
               "extension": "jpg",
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
               "uid": 12,
               "name": "hero.jpg",
               "extension": "jpg",
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
        - Force a full scheme+host URL (e.g. ``https://example.com/fileadmin/…``).
          Default: ``false`` — see the URL contract note below.

..  note::

    **URL contract.** Both ``FileProcessor`` and ``ImageProcessor`` emit
    **root-absolute** URLs by default: every ``publicUrl`` (and each
    ``cropVariants[*].publicUrl``) starts with a leading slash
    (``/fileadmin/_processed_/foo.jpg``) so it resolves against the host root
    regardless of the page path it is used on. URLs that are already absolute —
    full URLs with a scheme (``http://``, ``https://``), scheme-relative URLs
    (``//host/…``), and values that already start with ``/`` — are left
    untouched. Set the image ``absolute`` option to ``true`` to emit a full
    scheme+host URL instead.

Custom processors must implement
:php:`MaikSchneider\TcaApi\Serializer\Processing\ColumnProcessorInterface`.

..  _processor-error-containment:

Processor failures are contained
--------------------------------

A processor operates on one cell of one record, so a failure is scoped to that
cell rather than to the response. When a column or file processor throws, the
column serializes as ``null`` and the whole record — and the rest of the
collection — is returned normally. In a multi-file field the reference that
failed is dropped rather than left as a ``null`` hole.

Every contained failure is logged at ``error`` level with the table, uid,
column, processor class and the original exception, so the offending record
stays findable instead of silently disappearing.

..  code-block:: text

    ERROR TCA API column processing failed
      table:     tx_myext_domain_model_article
      uid:       17
      column:    teaser_image
      processor: MaikSchneider\TcaApi\Serializer\FileProcessing\ImageProcessor
      exception: TypeError
      message:   …, file, line

Set the ``tca_api.debugMode`` site setting to re-throw the original throwable
instead — development and CI then fail loudly, while production degrades. This
containment applies to processors only; a :ref:`callback <column-callbacks>` is
your own code and is not guarded.

..  note::

    Custom processors should **not** blanket-catch their own exceptions. An
    unlogged swallow inside a processor turns a genuine bug into "this column
    has no value"; letting it reach the guard produces the same degraded output
    plus a log entry that names the cause.

..  _column-callbacks:

Column callbacks
================

A ``callback`` is a lighter-weight alternative to a processor when you only need
to post-process a single column. Unlike a processor — which receives just the
raw column value — a callback runs **last**, after every column, relation, and
relation has already been resolved into the response. It receives the
fully serialized row and the raw DB row, and its return value **replaces** the
column's value. Column callbacks run *before* virtual properties, so a virtual
property can build on the final, callback-transformed column values.

..  code-block:: php

    use Vendor\MyExt\Api\ArticleCallbacks;

    'columns' => [
        'title'    => ['groups' => ['list', 'show']],
        'color_id' => ['groups' => ['list', 'show'], 'embed' => true],
        // Derive a label from the already-embedded relation:
        'label'    => [
            'groups'   => ['list', 'show'],
            'callback' => [ArticleCallbacks::class, 'buildLabel'],
        ],
    ],

The callback signature is ``(array $serializedRow, array $rawRow): mixed``:

..  code-block:: php

    final class ArticleCallbacks
    {
        public function buildLabel(array $serializedRow, array $rawRow): string
        {
            // $serializedRow already contains the resolved 'color_id' relation.
            $color = $serializedRow['color_id']['name'] ?? 'n/a';

            return sprintf('%s (%s)', $serializedRow['title'] ?? '', $color);
        }
    }

The callback class is instantiated via :php:`GeneralUtility::makeInstance()`, so
constructor dependency injection works. Because callbacks run after relation
resolving, they can read embedded relations, processor output, and other columns
from ``$serializedRow``. They run before virtual properties, so a virtual
property may consume a column's callback result. Callbacks honour the same
visibility rules as the column itself — they are skipped when the column is
hidden by ``groups`` for the current operation or excluded by a sparse fieldset
(``?fields[]=…``).

The same ``callback`` key is also available on virtual properties (see
:ref:`virtual-properties`), where it is the primary mechanism for computing a
value that has no backing column.
