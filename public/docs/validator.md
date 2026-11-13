# Validator

> Rule-based attribute validation ('required|email|max:255'), single error per field, stable translation error codes, custom callbacks.

Canonical: https://foundry.insightdevhub.ro/docs/validator/  
Namespace: `Foundry\Validator` · File: `Validator.php` · Version 1.1 · PHP 8.3+ · Extensions: mbstring

Incoming data validation (forms, APIs) according to field-declared rules. Invalid data does not throw an exception: it produces an error per field (stable code + message). Incorrectly written rules (unknown rule, missing parameter, invalid regex) throw an InvalidArgumentException upon construction, before anything is validated.

## Rules

```text
required          field exists and is not empty (null, '', whitespace only, [])
string, int, numeric, bool, array
                  data type; int/numeric/bool also accept form strings ("12", "1.5", "yes")
email             valid email address (without DNS check)
min:n, max:n, between:a,b
                  number (with int/numeric or int/float value), characters (string), or items (array)
in:a,b,c          one of the listed values (exact string comparison)
date, date:d.m.Y  real calendar date in the specified format (default Y-m-d)
regex:/^.../      pattern matches; in pipe-separated syntax, must be the last rule and may contain "|"
confirmed         the <name>_confirmation field has the same value
same:field        another field has the same value
'code' => Closure custom rule: fn (mixed $value): bool; 'code' is the error code
                  (for cross-field rules, pass data using 'use')
```

## Behavior

- an empty field without 'required' is not checked further (optional field)
- check order: 'required', type rules, then remaining rules in declared order; first failed rule yields the field error (single error per field)
- invalid UTF-8 strings are rejected with the 'encoding' code
- values are not converted: validated() returns input data as received (see Cast)
- error messages do not expose the user input value.

## Usage

```php
require_once 'Validator.php';
use Foundry\Validator;

$validator = new Validator($_POST, [
    'email' => 'required|email|max:255',
    'varsta' => 'int|between:18,99',
    'parola' => 'required|string|min:8|confirmed',
    'tip' => 'required|in:PF,PJ',
    'data_nasterii' => 'required|date:d.m.Y',
    'cnp' => ['required', 'cnp' => RoValidator::cnp(...)],
], messages: ['required' => 'The :field field is required.', 'cnp' => 'Invalid CNP.'],
   labels: ['data_nasterii' => 'birth date']);

if ($validator->fails()) {
    $errors = $validator->errors();   // ['email' => 'The email field must be a valid email address.']
    $codes = $validator->codes();     // ['email' => 'email']
}

$data = Validator::validate($payload, $rules); // validated data or InvalidArgumentException
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Validator.php';

use Foundry\Validator;
```

## Methods

### __construct()

```php
public function __construct(
    private readonly array $data,
    array $rules,
    private readonly array $messages = [],
    private readonly array $labels = [],
)
```

Validates input data immediately.

Parameters:
- `$data` (`array<array-key, mixed>`, required): Input data (e.g. $_POST, decoded JSON).
- `$rules` (`array<array-key, string|array<array-key, string|Closure>>`, required): Rules per field: 'required|email' or list.
- `$messages` (`array<string, string>`, default `[]`): Custom messages by code ('required') or field and code ('email.required'). Placeholders: :field, :min, :max, :values, :format, :other.
- `$labels` (`array<string, string>`, default `[]`): Field names in messages (default: key name with "_" -> space).

Throws:
- `InvalidArgumentException`: If rules are written incorrectly.
- `UnexpectedValueException`: If a custom rule does not return a boolean.

### validate()

```php
public static function validate(array $data, array $rules): array
```

Validates and returns the validated data.

Parameters:
- `$data` (`array<array-key, mixed>`, required)
- `$rules` (`array<array-key, string|array<array-key, string|Closure>>`, required)

Returns `array<string, mixed>`.

Throws:
- `InvalidArgumentException`: If data fails validation (fields and codes, without values) or if rules are written incorrectly.

### passes()

```php
public function passes(): bool
```

Returns `bool`.

### fails()

```php
public function fails(): bool
```

Returns `bool`.

### errors()

```php
public function errors(): array
```

Error messages per field, in rule order.

Returns `array<string, string>`.

### codes()

```php
public function codes(): array
```

Error codes per field (violated rule name, 'encoding', or custom rule code). These are stable and can be translated within the application.

Returns `array<string, string>`.

### error()

```php
public function error(string $field): ?string
```

Error message for a specific field or null if there is no error.

Parameters:
- `$field` (`string`, required)

Returns `?string`.

### validated()

```php
public function validated(): array
```

Validated data: only fields with rules, with their original received values. Optional fields absent from input data are also omitted from the result.

Returns `array<string, mixed>`.

Throws:
- `InvalidArgumentException`: If validation failed.

