..  _file-uploads:

============
File Uploads
============

TCA API supports multipart file uploads on ``POST`` (create) and ``PUT``/``PATCH``
(update) endpoints. Adding an ``upload`` key to a column configuration enables
the column to accept uploaded files via ``multipart/form-data`` requests.

.. contents::
   :local:
   :depth: 2

How it works
============

When a column has an ``upload`` key defined:

1. The client sends the request as ``multipart/form-data`` instead of JSON.
2. The file is validated against the constraints in ``upload`` and the allowed
   extensions from the TCA ``type=file`` column config.
3. On success the file is stored in the configured FAL storage folder and a
   ``sys_file_reference`` record is created, linking the file to the parent record.
4. On PATCH requests, existing file references are **replaced** when a new file is
   uploaded for that column. If the upload field is omitted entirely, existing
   references are preserved (PATCH semantics).

Configuration
=============

Add an ``upload`` key to any ``type=file`` column:

..  code-block:: php

    use MaikSchneider\TcaApi\Serializer\FileProcessing\FileProcessor;

    'columns' => [
        'profile_photo' => [
            'groups' => ['list', 'show', 'create', 'update'],
            'upload' => [
                'folder'      => '1:/user_upload/',
                'maxSize'     => '5M',
                'duplication' => 'rename',
            ],
        ],
        'downloads' => [
            'groups'    => ['list', 'show', 'create', 'update'],
            'processor' => FileProcessor::class,
            'upload'    => [
                'folder'  => '1:/user_upload/',
                'maxSize' => '20M',
            ],
        ],
    ],

Upload config reference
=======================

.. list-table::
   :header-rows: 1
   :widths: 20 10 10 60

   * - Key
     - Required
     - Default
     - Description
   * - ``folder``
     - Yes
     - —
     - FAL storage reference including path, e.g. ``1:/uploads/``. Must start
       with a storage UID followed by ``:/``.
   * - ``maxSize``
     - No
     - unlimited
     - Maximum allowed file size. Accepts an integer (bytes) or a human-readable
       string: ``5M``, ``100K``, ``1G``. Supports suffixes ``K``/``KB``,
       ``M``/``MB``, ``G``/``GB`` (case-insensitive).
   * - ``duplication``
     - No
     - ``rename``
     - How to handle filename collisions in the target folder. One of:

       * ``rename`` — add a numeric suffix (default)
       * ``replace`` — overwrite the existing file
       * ``cancel`` — return an error when the filename already exists
   * - ``filenameMask``
     - No
     - *(original filename)*
     - Template string for the stored filename. Supports the following
       placeholders:

       * ``{name}`` — original filename without extension
       * ``{extension}`` — file extension without dot (e.g. ``jpg``)
       * ``{ext}`` — file extension with dot (e.g. ``.jpg``), empty if none
       * ``{contentHash}`` — MD5 hash of file content
       * ``{nameHash}`` — MD5 hash of the original filename (without extension)
       * ``{timestamp}`` — current Unix timestamp
       * ``{unique}`` — unique ID via TYPO3's ``StringUtility::getUniqueId()``

       Example: ``{timestamp}_{unique}{ext}`` → ``1714000000_abc123.jpg``

Allowed file extensions
=======================

Allowed extensions are **not** configured in the ``upload`` key — they are read
at validation time from the TCA ``type=file`` column's ``allowed`` config:

..  code-block:: php

    // EXT:myext/Configuration/TCA/tx_myext_domain_model_article.php
    'profile_photo' => [
        'config' => [
            'type'    => 'file',
            'allowed' => 'jpg,jpeg,png,gif,webp',
        ],
    ],

The special value ``common-image-types`` is resolved via
``$GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext']``.

When no ``allowed`` key is present, all file types are accepted.

Sending a file upload request
==============================

Use ``Content-Type: multipart/form-data``. All non-file fields are sent as
regular form parts; file fields are sent as file parts.

Single-file upload (curl)
--------------------------

..  code-block:: bash

    curl -X POST https://example.com/_api/articles \
      -H "Cookie: fe_typo_user=..." \
      -F "title=My Article" \
      -F "profile_photo=@/path/to/photo.jpg"

Update with partial PATCH
--------------------------

..  code-block:: bash

    # Replace only the file — title is unchanged
    curl -X PATCH https://example.com/_api/articles/1 \
      -H "Cookie: fe_typo_user=..." \
      -F "profile_photo=@/path/to/new-photo.jpg"

    # Update only the title — existing file reference is preserved
    curl -X PATCH https://example.com/_api/articles/1 \
      -H "Cookie: fe_typo_user=..." \
      -F "title=Updated Title"

Multiple files (single field)
------------------------------

When the TCA column allows multiple files, pass the field multiple times:

..  code-block:: bash

    curl -X POST https://example.com/_api/articles \
      -F "title=My Article" \
      -F "downloads[]=@/path/to/file1.pdf" \
      -F "downloads[]=@/path/to/file2.pdf"

Validation errors
=================

Upload violations return **422 Unprocessable Entity** in the same structured
format as other validation errors:

..  code-block:: json

    {
        "@context": "http://www.w3.org/ns/hydra/context.jsonld",
        "@type": "hydra:Error",
        "hydra:title": "Validation Failed",
        "hydra:description": "2 validation error(s)",
        "violations": [
            {
                "propertyPath": "profile_photo",
                "message": "The file type is not allowed.",
                "code": "UPLOAD_MIME_TYPE"
            },
            {
                "propertyPath": "profile_photo",
                "message": "The file exceeds the maximum allowed size.",
                "code": "UPLOAD_MAX_SIZE"
            }
        ]
    }

Violation codes
===============

.. list-table::
   :header-rows: 1
   :widths: 25 75

   * - Code
     - Meaning
   * - ``UPLOAD_ERROR``
     - PHP file upload transport error (e.g. upload aborted, temp directory
       not writable). Reported error code from ``$_FILES``.
   * - ``UPLOAD_MIME_TYPE``
     - The detected MIME type is not permitted for this column.
   * - ``UPLOAD_MAX_SIZE``
     - The file exceeds the ``maxSize`` limit configured in ``upload``.
   * - ``UPLOAD_EXTENSION``
     - The file extension is not in the list allowed by the TCA column.

OpenAPI / Swagger UI
====================

Upload columns are reflected in the auto-generated OpenAPI spec as binary
fields in a ``multipart/form-data`` request body. The Swagger UI renders an
interactive file-picker for every upload column, so you can test uploads
directly from the browser.

Combining uploads with read processors
=======================================

A column can have both an ``upload`` key *and* a ``processor`` key. The upload
key controls how incoming files are stored; the processor controls how the
stored file references are serialized in read responses:

..  code-block:: php

    use MaikSchneider\TcaApi\Serializer\FileProcessing\FileProcessor;
    use MaikSchneider\TcaApi\Serializer\FileProcessing\ImageProcessor;

    'columns' => [
        // Accept uploads, serialize as full file metadata on read
        'downloads' => [
            'groups'    => ['list', 'show', 'create', 'update'],
            'processor' => FileProcessor::class,
            'upload'    => ['folder' => '1:/downloads/'],
        ],
        // Accept uploads, serialize with crop-variant data on read
        'hero_image' => [
            'groups'    => ['list', 'show', 'create', 'update'],
            'processor' => ImageProcessor::class,
            'upload'    => ['folder' => '1:/images/', 'maxSize' => '10M'],
        ],
    ],
