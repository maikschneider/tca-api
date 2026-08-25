<div align="center">

![Extension icon](Resources/Public/Icons/Extension.svg)

# TYPO3 extension `tca_api`

[![Latest version](https://typo3-badges.dev/badge/tca_api/version/shields.svg)](https://extensions.typo3.org/extension/tca_api)
[![Supported TYPO3 versions](https://typo3-badges.dev/badge/tca_api/typo3/shields.svg)](https://extensions.typo3.org/extension/tca_api)
[![Supported PHP versions](https://img.shields.io/packagist/dependency-v/maikschneider/tca-api/php?logo=php)](https://packagist.org/packages/maikschneider/tca-api)
[![Coverage](https://codecov.io/gh/maikschneider/tca-api/graph/badge.svg?token=J2CNGVXEX1)](https://codecov.io/gh/maikschneider/tca-api)
[![Tests](https://img.shields.io/github/actions/workflow/status/maikschneider/tca-api/tests.yml?label=tests&logo=github)](https://github.com/maikschneider/tca-api/actions/workflows/tests.yml)
[![CGL](https://img.shields.io/github/actions/workflow/status/maikschneider/tca-api/sca.yml?label=cgl&logo=github)](https://github.com/maikschneider/tca-api/actions/workflows/sca.yml)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE.md)

</div>

This TYPO3 extension exposes database tables as **Hydra JSON-LD** resources through a configuration-driven REST API. Drop a PHP array into `Configuration/TcaApi/` naming the table, and the extension handles routing, serialization, filtering, sorting, pagination, validation, and access control — no controllers, no Extbase models.

```php
<?php
// EXT:my_ext/Configuration/TcaApi/Articles.php

return [
    'general' => [
        'table'        => 'tx_myext_domain_model_article',
        'resourceName' => 'articles',
        'resourceType' => 'Article',
    ],
];
```

That is a complete, read-only API at `/_api/articles`, including an OpenAPI spec and Swagger UI. Everything beyond it is opt-in.

## ✨ Features

**[Resource definitions](https://docs.typo3.org/p/maikschneider/tca-api/main/en-us/Configuration/ResourceDefinition.html)** — Expose a table by registering a PHP array
* Full CRUD (`list`, `show`, `create`, `update`, `delete`); reads are on by default, writes are explicit
* [Serialization groups](https://docs.typo3.org/p/maikschneider/tca-api/main/en-us/Configuration/Columns.html) control which columns appear per operation
* [Override third-party configs](https://docs.typo3.org/p/maikschneider/tca-api/main/en-us/Configuration/Overrides.html) the way TYPO3's `TCA/Overrides/` works

**[Querying](https://docs.typo3.org/p/maikschneider/tca-api/main/en-us/Configuration/Filters.html)** — Exact, partial, word-start, range, full-text and MM filters
* [Relation-path filters](https://docs.typo3.org/p/maikschneider/tca-api/main/en-us/Configuration/Filters.html#relation-path-filters) (`categories.title`) reach across up to three hops
* [Sorting](https://docs.typo3.org/p/maikschneider/tca-api/main/en-us/Configuration/Sorting.html), offset pagination, and sparse fieldsets (`?fields[]=…`)

**[Security](https://docs.typo3.org/p/maikschneider/tca-api/main/en-us/Configuration/Security.html)** — Per-operation roles, from `PUBLIC` to record-level `OWNER`
* [Write privilege model](https://docs.typo3.org/p/maikschneider/tca-api/main/en-us/Configuration/WritePrivileges.html) with actor-aware writes, a table deny list, and audit logging
* [Validation](https://docs.typo3.org/p/maikschneider/tca-api/main/en-us/Configuration/Validation.html) auto-derived from TCA, with structured `422` responses

**[Serialization](https://docs.typo3.org/p/maikschneider/tca-api/main/en-us/Configuration/Columns.html)** — Every TCA field type handled, relations resolved
* [Relations](https://docs.typo3.org/p/maikschneider/tca-api/main/en-us/Configuration/Relations.html) as IRI strings or embedded records, created inline on write
* [Virtual properties](https://docs.typo3.org/p/maikschneider/tca-api/main/en-us/Configuration/VirtualProperties.html), [image processing](https://docs.typo3.org/p/maikschneider/tca-api/main/en-us/Configuration/Columns.html#column-processors), [speaking URLs](https://docs.typo3.org/p/maikschneider/tca-api/main/en-us/Configuration/RouteEnhancer.html) and [file uploads](https://docs.typo3.org/p/maikschneider/tca-api/main/en-us/Configuration/FileUploads.html)

**[Multi-language](https://docs.typo3.org/p/maikschneider/tca-api/main/en-us/Configuration/Languages.html) & [caching](https://docs.typo3.org/p/maikschneider/tca-api/main/en-us/Configuration/Caching.html)** — Production concerns handled
* URL base segments and an `X-Locale` header resolve the `SiteLanguage`
* Tag-based response caching, invalidated automatically by the DataHandler

**[OpenAPI](https://docs.typo3.org/p/maikschneider/tca-api/main/en-us/ApiUsage/Index.html#openapi-spec-swagger-ui)** — Spec and Swagger UI generated from the registered resources
* Interactive Swagger UI at the API prefix, plus a backend module on TYPO3 v14
* Compatible with [API Platform](https://api-platform.com/) and its admin

**[Extensibility](https://docs.typo3.org/p/maikschneider/tca-api/main/en-us/Developer/Index.html)** — Every layer is replaceable
* [Custom operation handlers](https://docs.typo3.org/p/maikschneider/tca-api/main/en-us/Developer/CustomHandlers.html), [filters](https://docs.typo3.org/p/maikschneider/tca-api/main/en-us/Configuration/Filters.html#custom-filters), validators and column processors
* [PSR-14 events](https://docs.typo3.org/p/maikschneider/tca-api/main/en-us/Developer/Events.html) around every operation and every write

## 🔥 Installation

### Requirements

* TYPO3 13.4 LTS or 14.3+
* PHP 8.2+

### Composer

[![Packagist](https://img.shields.io/packagist/v/maikschneider/tca-api?label=version&logo=packagist)](https://packagist.org/packages/maikschneider/tca-api)
[![Packagist Downloads](https://img.shields.io/packagist/dt/maikschneider/tca-api?color=brightgreen)](https://packagist.org/packages/maikschneider/tca-api)

```bash
composer require maikschneider/tca-api
```

### TER

[![TER version](https://typo3-badges.dev/badge/tca_api/version/shields.svg)](https://extensions.typo3.org/extension/tca_api)
[![TER downloads](https://typo3-badges.dev/badge/tca_api/downloads/shields.svg)](https://extensions.typo3.org/extension/tca_api)

Download the zip file from [TYPO3 extension repository (TER)](https://extensions.typo3.org/extension/tca_api).

## 📂 Setup

The extension ships a TYPO3 **site set**. Add it to your site's `config/sites/<site>/config.yaml`, or via
**Site Management → Sites** in the backend:

```yaml
dependencies:
  - maikschneider/tca-api
```

The API then responds under `/_api/`. Prefix, pagination defaults, CORS and OpenAPI access are
[site settings](https://docs.typo3.org/p/maikschneider/tca-api/main/en-us/Configuration/SiteSettings.html),
editable under **Site Management → Sites → Settings**.

## 📙 Documentation

Please have a look at the
[official extension documentation](https://docs.typo3.org/p/maikschneider/tca-api/main/en-us/Index.html).

A runnable demo covering a range of configurations lives at
[maikschneider/typo3-petstore](https://github.com/maikschneider/typo3-petstore).

## 🧑‍💻 Contributing

Please have a look at [`CONTRIBUTING.md`](CONTRIBUTING.md), and join the
[discussions](https://github.com/maikschneider/tca-api/discussions) — feedback on the architecture,
security model and design decisions is very welcome.

## 🔒 Security Policy

Please read our [security policy](SECURITY.md) if you discover a security vulnerability in this extension.

## ⭐ License

This project is licensed under [GNU General Public License 2.0 (or later)](LICENSE.md).
