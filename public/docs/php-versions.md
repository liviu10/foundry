# PHP versions

> Foundry is written for PHP 8.3+ and can be downgraded automatically to PHP 7.4. Here is how the two targets relate and how they differ.

Canonical: https://foundry.insightdevhub.ro/docs/php-versions/

## Supported versions

| Target | Role |
| --- | --- |
| PHP 8.3+ | The source code. Every class is written, tested and documented for this version. |
| PHP 7.4 | Generated from the same source by an automated downgrade, for older servers. |

The download archive contains the PHP 8.3 sources. This documentation describes the PHP 8.3 API; the PHP 7.4 build exposes the same classes, methods and exceptions.

PHP 5.x is not supported.

## How the PHP 7.4 build is made

The PHP 7.4 files are never edited by hand. They are regenerated from the PHP 8.3 sources:

1. [Rector](https://getrector.com/) rewrites newer syntax (typed class constants, readonly properties, `match`, union types and more) into PHP 7.4 equivalents.
2. Missing PHP 8 functions used by the library, such as `get_debug_type()`, are added as small polyfills.
3. The result is checked with PHP 7.4.33: a syntax check of every file, then a real execution test.

## Differences on PHP 7.4

The behavior is the same, with two differences that come from the PHP engine itself:

- `SQLite` (pdo_sqlite) returns numeric columns as strings (`'2'`) instead of integers. Native driver types arrived in PHP 8.1.
- `gd` image handles are resources instead of `GdImage` objects. This is transparent when you use `Image`.

> **Note:** Named arguments, used in many examples in this documentation (`batchSize: 5000`), are PHP 8 syntax. On PHP 7.4, pass the arguments by position instead.
