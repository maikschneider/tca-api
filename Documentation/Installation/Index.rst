..  _installation:

============
Installation
============

Requirements
============

.. list-table::
   :header-rows: 1

   * - Dependency
     - Version
   * - PHP
     - ^8.2
   * - TYPO3
     - ^13.4 || ^14.0

Composer installation
=====================

The extension is installed via Composer:

..  code-block:: bash

    composer require maikschneider/tca-api

Site set setup
==============

TCA API ships a TYPO3 **site set** (``maikschneider/tca-api``). Add it to your
site configuration to activate the API.

In the TYPO3 backend, go to :guilabel:`Site Management → Sites` and add
``maikschneider/tca-api`` as a dependency, or edit your
:file:`config/sites/<site>/config.yaml` directly:

..  code-block:: yaml

    dependencies:
      - maikschneider/tca-api

Once the site set is active, the API is enabled and responds at the configured
URL prefix (``/_api/`` by default). Site-level settings such as the API prefix,
pagination defaults, CORS headers, and OpenAPI access can be customised in the
TYPO3 backend under :guilabel:`Site Management → Sites → Settings`.

See :ref:`site-settings` for all available options.
