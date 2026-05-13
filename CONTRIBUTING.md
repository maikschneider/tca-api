# Contributing to tca-api

Thank you for considering contributing to tca-api! We welcome contributions of all kinds.

## Local Development Setup

This extension uses DDEV for local development.

### 1. Start the Environment

```bash
ddev start
```

### 2. Install Dependencies

```bash
ddev composer install
```

### 3. Set up TYPO3 + fixtures

```bash
ddev init-typo3
```

## Running Tests

Tests use the TYPO3 Testing Framework and PHPUnit. You can run all tests or specific suites/files.

```bash
# Run all test suites
ddev exec vendor/bin/phpunit -c phpunit.xml

# Run a specific suite
ddev exec vendor/bin/phpunit -c phpunit.xml --testsuite Collection
ddev exec vendor/bin/phpunit -c phpunit.xml --testsuite Security
ddev exec vendor/bin/phpunit -c phpunit.xml --testsuite Write
ddev exec vendor/bin/phpunit -c phpunit.xml --testsuite Embed
ddev exec vendor/bin/phpunit -c phpunit.xml --testsuite Serialization
ddev exec vendor/bin/phpunit -c phpunit.xml --testsuite SiteSettings

# Run a single test file
ddev exec vendor/bin/phpunit -c phpunit.xml --filter PaginationTest
```

## Code Quality Standards

Please run all checks before submitting a pull request.

### Run All Checks

```bash
ddev composer sca
```

### Individual Tools

```bash
# PHP CS Fixer - fix code style
ddev composer php:fixer

# PHPStan - static analysis
ddev composer php:stan

# PHP syntax check
ddev composer php:lint

# TypoScript lint
ddev composer typoscript:lint

# YAML lint
ddev composer yaml:lint

# EditorConfig lint
ddev composer editorconfig:lint

# Translation validator
ddev composer language:lint

# Normalize composer.json
ddev composer composer:normalize
```

## Getting Help

- **Issues:** Open an issue on GitHub for bugs or feature requests
- **Discussions:** Start a discussion for questions or ideas

## Code of Conduct

Please be respectful and constructive in all interactions.

## License

By contributing, you agree that your contributions will be licensed under GPL-2.0-or-later.

---

Thank you for contributing!
