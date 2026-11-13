# Usage and exceptions

> How Foundry classes are called, which exceptions they throw, how error messages are written, and when to catch them.

Canonical: https://foundry.insightdevhub.ro/docs/usage/

## Calling the classes

All classes live in the `Foundry` namespace. Most utilities are static (`Json`, `Str`, `Cast`, `Date`, `Money`); stateful ones are objects you create (`Validator`, `Log`, `MySQL`, `View`). Each file header documents the purpose and rules of the class, and every public method has a docblock listing what it throws. The same information appears on the reference page of each class.

```php
use Foundry\Cast;
use Foundry\Date;
use Foundry\Money;

$id = Cast::toInt($_GET['id'] ?? null);        // '12' -> 12; 'abc', '' or missing -> exception
$date = Date::parse('15.03.2026', 'd.m.Y');    // 31.02.2026 -> exception, never rolled over to 03.03.2026
$total = Money::add('1250.50', '99.99');       // '1350.49', without floating-point inaccuracies
```

## Exceptions

Foundry never signals an error by returning `false` or `null`. Every failure throws one of the standard PHP SPL exceptions. There are no custom exception classes to learn or import.

| Exception | Typical meaning |
| --- | --- |
| `InvalidArgumentException` | A parameter is wrong: a negative depth, an unknown option, an invalid rule. Usually a bug in the calling code. |
| `UnexpectedValueException` | The data is wrong: a value that is not an integer, a date that does not exist, a malformed amount. Usually user input. |
| `RuntimeException` | The environment failed: a file cannot be written, a connection was refused, a command timed out. |
| `LogicException` | An operation was called in the wrong state, for example using a session that was not started. |
| `JsonException` | Invalid JSON, invalid UTF-8, or a value that cannot be encoded. |

The exact exceptions of every method are listed under **Throws** in its reference entry.

## Error messages

Messages follow one pattern, so a log line tells you what failed and where without a debugger:

```text
<Class>::<method>(): <what happened> [<context>]: <root cause>.
```

```text
Json::decode(): Syntax error (input length: 24 bytes).
```

Messages never contain the data being processed. They report its type, length or path instead, so logs stay free of personal data such as names, CNP numbers, IBANs or passwords.

Native PHP warnings (from `fopen()`, `unserialize()`, `gzdecode()` and others) are captured inside the class and become the cause of the exception. The `@` error suppression operator is never used, and warnings never reach your global error handler.

## When to catch

You do not need `try` / `catch` blocks everywhere. Unhandled exceptions bubble up to `ErrorHandler`, which logs them and returns a generic HTTP 500 page (see [Application bootstrap](https://foundry.insightdevhub.ro/docs/bootstrap/)). Catch only what you can actually recover from, typically invalid user input:

```php
use Foundry\Number;

try {
    $amount = Number::parse($_POST['amount']);        // '1.234,50' -> 1234.5
} catch (UnexpectedValueException $e) {
    $errors['amount'] = 'The amount is invalid.';
}
```

For whole forms, use `Validator` instead of catching per field: it collects one error per field without throwing. See [Form handling](https://foundry.insightdevhub.ro/docs/form-handling/).
