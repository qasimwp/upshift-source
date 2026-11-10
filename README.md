# Upshift

Source code and build scripts of **Upshift**, a WordPress plugin that checks plugins and themes for code that breaks on a newer PHP version, optionally shows which of that code really runs on the site, and warns when a plugin starts failing after a PHP change.

The plugin is published on WordPress.org: https://wordpress.org/plugins/upshift/

## What is in this repository

| Path | What it is |
|---|---|
| `upshift.php`, `uninstall.php` | Plugin bootstrap and uninstall |
| `src/` | The plugin's own code (namespace `Upshift\`, PSR-4) |
| `assets/` | Admin CSS and JavaScript (plain files, no build step) |
| `languages/` | Translation template (`upshift.pot`) |
| `engine/composer.json`, `engine/composer.lock` | The pinned versions of the check engine (PHP_CodeSniffer and PHPCompatibility) |
| `scoper.inc.php` | PHP-Scoper configuration: renames the engine into `Upshift\Vendor` and removes its shell and `eval()` calls |
| `bin/build-engine.php` | Builds `vendor-prefixed/` (the scoped engine) |
| `bin/build-zip.php` | Builds the release zip |
| `composer.json`, `.phpcs.xml.dist` | Dev tools: WordPress Coding Standards and PHP 7.2 compatibility linting of the plugin's own code |

`vendor-prefixed/` is build output and is not stored here. The release zip on WordPress.org contains it.

## Requirements for building

- PHP 8.2 or newer on the command line (PHP-Scoper needs it; the plugin itself runs on older PHP, see `readme.txt`)
- Composer 2
- PHP extensions: `zip`, `tokenizer`, `simplexml`, `xmlwriter`, `libxml`
- Git (the zip build writes the commit hash into `build.txt`)
- [PHP-Scoper](https://github.com/humbug/php-scoper) as a PHAR at `tools/php-scoper.phar` (the release was built with 0.18.20)

## Build steps

```sh
# 1. Dev tools (only needed for linting)
composer install
composer lint

# 2. PHP-Scoper
mkdir -p tools
curl -L -o tools/php-scoper.phar https://github.com/humbug/php-scoper/releases/download/0.18.20/php-scoper.phar

# 3. The scoped engine: installs engine/ with Composer, scopes it, strips the parts the plugin never loads,
#    checks that no exec-family call or eval() is left and that every patch landed, then writes vendor-prefixed/
php bin/build-engine.php

# 4. The release zip: dist/upshift.zip with the folder upshift/ inside
php bin/build-zip.php
```

`bin/build-zip.php` refuses to build when `vendor-prefixed/` is missing or was built from other inputs.

## Bundled third-party code

| Package | Version | License |
|---|---|---|
| squizlabs/php_codesniffer | 4.0.4 | BSD-3-Clause |
| phpcompatibility/php-compatibility | 10.0.0-alpha2 | LGPL-3.0-or-later |
| phpcompatibility/phpcompatibility-wp | 3.0.0-alpha2 | LGPL-3.0-or-later |
| phpcompatibility/phpcompatibility-paragonie | 2.0.0-alpha2 | LGPL-3.0-or-later |
| phpcsstandards/phpcsutils | 1.2.3 | LGPL-3.0-or-later |

The exact changes made to the bundled code are the patchers in `scoper.inc.php` and the strip lists in `bin/build-engine.php`.

## Notes

Code comments refer to design decisions as `DECISIONS Dnn` and to spec sections as `spec §n`. Those are the author's internal working notes and are not part of this repository.

## License

GPL-3.0-or-later. See `LICENSE`.
