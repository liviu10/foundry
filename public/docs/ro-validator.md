# RoValidator

> Romanian specific validators: CNP, CUI/CIF, IBAN, phone numbers, postal codes, identity card series; returns boolean, usable directly in Validator.

Canonical: https://foundry.insightdevhub.ro/docs/ro-validator/  
Namespace: `Foundry\RoValidator` · File: `RoValidator.php` · Version 1.2 · PHP 8.3+

Validation of Romanian identifiers: CNP (Personal Numeric Code), CUI/CIF (Fiscal Code), IBAN, phone numbers, postal codes, and identity card series and numbers. Validation methods return bool (they do not throw exceptions for invalid data), so they can be directly used as custom rules in Validator:

```text
'cnp' => ['required', 'cnp' => RoValidator::cnp(...)]
```

## Rules

- Leading and trailing whitespace is trimmed; CNP and CUI are also accepted as int
- Lowercase letters are accepted where values contain letters (IBAN, RO prefix, ID card series)
- Exception messages do not disclose the sensitive value (e.g. CNP is personal data).

## Usage

```php
require_once 'RoValidator.php';
use Foundry\RoValidator;

RoValidator::cnp('1800101420010');              // control digit, birth date, county
RoValidator::cnpInfo($cnp)['birthDate'];         // DateTimeImmutable
RoValidator::cui('RO6291812');                   // with or without RO prefix
RoValidator::iban('RO49 AAAA 1B31 0075 9384 0000');
RoValidator::normalizePhone('0721 234 567');     // '+40721234567'
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/RoValidator.php';

use Foundry\RoValidator;
```

## Methods

### cnp()

```php
public static function cnp(mixed $value): bool
```

Validates a CNP: 13 digits, sex/century, valid birth date not in the future, county code, serial number 001-999, and control digit.

Parameters:
- `$value` (`mixed`, required)

Returns `bool`.

### cnpInfo()

```php
public static function cnpInfo(string|int $cnp): array
```

Extracts information from CNP. For leading digit 7, 8, or 9 the century is not encoded: year 20xx is selected if not in the future, otherwise 19xx.

Parameters:
- `$cnp` (`string|int`, required)

Returns `array{birthDate: DateTimeImmutable, sex: 'M'|'F'|null, county: string, foreigner: bool}`: sex is null for leading digit 9 (foreign citizens).

Throws:
- `InvalidArgumentException`: If the CNP is invalid (contains reason without disclosing the value).

### cui()

```php
public static function cui(mixed $value): bool
```

Validates a CUI / CIF, with or without RO prefix (VAT payer): 2-10 digits and check digit.

Parameters:
- `$value` (`mixed`, required)

Returns `bool`.

### iban()

```php
public static function iban(mixed $value, bool $anyCountry = false): bool
```

Validates an IBAN (mod 97). By default Romanian only: RO + 2 check digits + 4 bank letters + 16 characters. With $anyCountry, any country is accepted (general ISO 13616 structure, without country-specific length). Whitespace between character groups is accepted.

Parameters:
- `$value` (`mixed`, required)
- `$anyCountry` (`bool`, default `false`)

Returns `bool`.

### phone()

```php
public static function phone(mixed $value): bool
```

Validates a Romanian phone number, mobile (07...) or landline (02..., 03...), in 0..., +40..., or 0040... format. Spaces, dots, dashes, and parentheses are accepted as delimiters.

Parameters:
- `$value` (`mixed`, required)

Returns `bool`.

### mobilePhone()

```php
public static function mobilePhone(mixed $value): bool
```

Same as phone(), but restricted to mobile numbers (07...).

Parameters:
- `$value` (`mixed`, required)

Returns `bool`.

### normalizePhone()

```php
public static function normalizePhone(string $value): string
```

Formats a phone number in international standard without separators: '0721 234 567' -> '+40721234567'.

Parameters:
- `$value` (`string`, required)

Returns `string`.

Throws:
- `InvalidArgumentException`: If the value is not a valid Romanian phone number.

### postalCode()

```php
public static function postalCode(mixed $value): bool
```

Validates a Romanian postal code: 6 digits.

Parameters:
- `$value` (`mixed`, required)

Returns `bool`.

### identityCard()

```php
public static function identityCard(mixed $value): bool
```

Validates identity card series and number: 2 letters + 6 digits ('RX 123456' or 'RX123456'). Checks format validity, not whether the series was officially issued.

Parameters:
- `$value` (`mixed`, required)

Returns `bool`.

