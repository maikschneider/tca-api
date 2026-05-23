..  _configuration-overrides:

=========================================
Overriding Third-Party Resource Configs
=========================================

TCA API mirrors TYPO3's ``$GLOBALS['TCA']`` + ``TCA/Overrides/`` loading
pattern. Any active extension can modify a resource configuration that was
defined by another extension.

How it works
============

At boot time the loader runs two passes over every active package:

1. **Base pass** — loads every :file:`Configuration/TcaApi/*.php` file
   and writes the result into ``$GLOBALS['TCA_API']`` keyed by
   ``resourceName``. When two packages define the same ``resourceName``, the
   last package to load wins (TYPO3 package load order).

2. **Override pass** — requires every :file:`Configuration/TcaApi/Overrides/*.php`
   file (depth 0, sorted alphabetically within each package). Override files
   are plain PHP side-effects on ``$GLOBALS['TCA_API']`` — no return value is
   expected.

After both passes the global is parsed into typed ``ApiDefinition`` DTOs and
cached. At runtime, use :php:`ApiRegistry::get()` to read definitions; the
global itself is not guaranteed to be set on cache hits.

Writing an override file
========================

Create a PHP file under :file:`Configuration/TcaApi/Overrides/` in any active
extension. The filename is arbitrary.

**Add a column**

..  code-block:: php

    <?php

    declare(strict_types=1);

    // EXT:my_ext/Configuration/TcaApi/Overrides/ExtendArticles.php
    $GLOBALS['TCA_API']['articles']['columns']['subtitle'] = [
        'groups' => ['list', 'show'],
    ];

**Remove a column**

..  code-block:: php

    unset($GLOBALS['TCA_API']['articles']['columns']['internal_notes']);

**Restrict operations**

..  code-block:: php

    $GLOBALS['TCA_API']['articles']['general']['operations'] = ['list', 'show'];

**Change a security role**

..  code-block:: php

    use MaikSchneider\TcaApi\Enum\AccessRole;

    $GLOBALS['TCA_API']['articles']['security']['delete'] = AccessRole::FE_USER;

**Add a filter**

..  code-block:: php

    use MaikSchneider\TcaApi\Filter\ExactFilter;

    $GLOBALS['TCA_API']['articles']['filters']['category_id'] = ExactFilter::class;

Multiple overrides for the same resource are applied in alphabetical filename
order within a package, and in TYPO3 package load order across packages. The
last write always wins.

Explicit vs. implicit mode
===========================

The visibility mode — **implicit** (all TCA columns exposed) vs. **explicit**
(only columns with ``groups``) — is re-evaluated after all overrides are
applied.

- Adding a column with a ``groups`` key to an otherwise implicit resource
  switches it to explicit mode for that resource.
- Removing the only column that had ``groups`` switches it back to implicit.
- Adding a column *without* ``groups`` to an explicit resource keeps it
  explicit; the new column is recorded in the DTO but excluded from all
  operation outputs.
