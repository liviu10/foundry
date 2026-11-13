---
title: Design principles
description: The rules every Foundry class follows - explicit errors, one file per tool, pure native PHP, no personal data in error messages - and what is deliberately left out.
---

## The rules

**Explicit errors.** Any error throws an exception that names the operation, its context and the root cause. No silent `false` or `null` return values for error states.

**One file = one tool.** There are no dependencies between utility files. A little duplicated code across files is preferred over links between them, so any single file can be copied on its own.

**Pure native PHP.** No third-party packages. Composer is only a development tool of the library (tests, static analysis, code style), never a runtime requirement.

**Standard SPL exceptions.** `InvalidArgumentException`, `RuntimeException`, `UnexpectedValueException`, `LogicException` and friends. No custom exception hierarchy to import or learn.

**The `Foundry` namespace.** Names like `Log`, `Str` or `Arr` are common; the namespace removes any risk of collision with your own classes.

**No side effects on require.** Including a file never changes global state. Classes that do (`ErrorHandler`, `Session`) must be activated explicitly.

**Native warnings converted internally.** PHP warnings raised inside a class are caught and become the cause of the exception, whatever error handler the host application uses.

**No personal data in error messages.** Exceptions report metadata (type, length, path), never customer or sensitive data.

**Data over HTML.** UI helpers (`Flash`, `Breadcrumbs`, `FormBuilder`) return arrays and data structures. Markup, CSS classes and escaping stay in your templates, so any template engine or design system works.

## Code conventions

- `declare(strict_types=1)`, `final` classes and strict comparisons everywhere.
- Static analysis at the strictest level (PHPStan max) with no ignored errors and no baseline.
- Every file header has a description, a usage example, `@version` and `@since`.
- Every public method documents its parameters, return value and the exceptions it throws.
- The `@` error suppression operator is never used.

## Deliberately left out

Some things are not part of Foundry on purpose:

- **Custom exception hierarchies and assertion libraries.** Native types, `Validator` and `Cast` cover the same ground.
- **HTML builders and translation engines.** They would tie you to one template engine and one translation store.
- **Polyfills for features already native in PHP 8.3**, such as `str_contains()`, `array_is_list()`, `json_validate()` or `password_hash()`.
