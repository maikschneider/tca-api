..  _configuration-module:

====================
Configuration Module
====================

When ``typo3/cms-lowlevel`` is installed, TCA API registers a provider in the
TYPO3 backend **Configuration** module (:guilabel:`System → Configuration`).
The provider displays all loaded resource definitions as an interactive tree,
which is useful for debugging your setup and verifying that definitions are
loaded and parsed as expected.

..  figure:: /Images/ConfigurationModule.jpg
    :alt: TCA API configuration tree in the TYPO3 Configuration module
    :class: with-shadow border rounded

What is shown
=============

Each resource definition is listed by its ``resourceName`` and expanded into all
resolved properties. All values reflect the parsed state after ``ApiDefinitionLoader``
has normalised the raw PHP arrays — so what you see is exactly what the extension acts
on at runtime.

.. note::

    The tree is read-only. It reflects the in-memory state after the DI
    container has been warmed up. Clear the TYPO3 caches after changing a
    resource definition file to see the updated values.
