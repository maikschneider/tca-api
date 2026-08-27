..  _sorting:

=======
Sorting
=======

The ``order`` section configures which columns can be used for sorting and the
default sort order.

Configuration
=============

..  code-block:: php

    'order' => [
        'allowed' => ['title', 'uid'],       // columns allowed for sorting
        'default' => ['uid' => 'asc'],       // fallback when no order is requested
    ],

.. list-table::
   :header-rows: 1
   :widths: 20 80

   * - Key
     - Description
   * - ``allowed``
     - Array of column names that the client is permitted to sort by.
   * - ``default``
     - Associative array of ``column => direction`` pairs used when the client
       does not provide an ``order`` parameter. Direction can be ``asc`` or
       ``desc``.

Usage
=====

Sorting is controlled via the ``order`` query parameter:

.. code-block:: text

    GET /_api/articles?order[title]=asc
    GET /_api/articles?order[uid]=desc

Once ``allowed`` is declared, only the columns listed in it are accepted.
Requesting any other column returns ``400 Bad Request`` naming the offending
column and the sortable set, so a typo or a forgotten ``allowed`` entry surfaces
instead of silently producing a differently ordered ``200``.

A resource that declares no ``allowed`` states no restriction, so its ``order``
parameters are not rejected — they fall back to ``default``, or to raw database
order when there is no ``default`` either.
