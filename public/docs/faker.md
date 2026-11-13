# Faker

> Generates realistic Romanian test data (valid CNP, CUI, IBAN, physical addresses), with optional deterministic seeding.

Canonical: https://foundry.insightdevhub.ro/docs/faker/  
Namespace: `Foundry\Faker` · File: `Faker.php` · Version 1.0 · PHP 8.3+

Romanian test data generator: names, CNP, CUI, IBAN, phone numbers, emails, addresses, companies, and calendar dates. Identifiers are valid (accurate check digits) and internally consistent: a person's CNP matches their gender and birth date. Names and addresses include Romanian diacritics to test UTF-8 encoding.

## Rules

- without a seed, data varies on each run; with a seed, output is deterministic (tests, demos)
- uses an internal generator: does not affect application-level mt_rand() / rand()
- emails use example.com (domain reserved for testing, never delivers to real recipients)
- data is fictitious, though a validly generated CNP may correspond to a real person: do not use in production.

## Usage

```php
require_once 'Faker.php';
use Foundry\Faker;

$faker = new Faker(seed: 42);
$persoana = $faker->person();         // name, sex, birthDate, cnp, email, phone, address
$cnp = $faker->cnp(sex: 'F', birthDate: new DateTimeImmutable('1990-05-17'));
$iban = $faker->iban();               // 'RO..BTRL................'
$firma = $faker->company();           // ['name' => 'Popescu Construct SRL', 'cui' => '...']
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Faker.php';

use Foundry\Faker;
```

## Methods

### __construct()

```php
public function __construct(?int $seed = null)
```

Parameters:
- `$seed` (`int|null`, default `null`): Same seed = identical data; null = different data on each run.

### int()

```php
public function int(int $min, int $max): int
```

Integer between $min and $max (inclusive); the range spans at most 2^32 values.

Parameters:
- `$min` (`int`, required)
- `$max` (`int`, required)

Returns `int`.

Throws:
- `InvalidArgumentException`: If $min > $max or the range is too large.

### bool()

```php
public function bool(float $probability = 0.5): bool
```

Returns true with the given probability (0..1).

Parameters:
- `$probability` (`float`, default `0.5`)

Returns `bool`.

Throws:
- `InvalidArgumentException`: If the probability is not between 0 and 1.

### element()

```php
public function element(array $items): mixed
```

Picks an element from the given array.

Parameters:
- `$items` (`array<array-key, T>`, required)

Returns `T`.

Throws:
- `InvalidArgumentException`: If the list is empty.

### digits()

```php
public function digits(int $length): string
```

String of digits of the given length (may start with 0).

Parameters:
- `$length` (`int`, required)

Returns `string`.

Throws:
- `InvalidArgumentException`: If the length is negative.

### date()

```php
public function date(DateTimeInterface $from, DateTimeInterface $to): DateTimeImmutable
```

Date between two dates (inclusive), at 00:00:00.

Parameters:
- `$from` (`DateTimeInterface`, required)
- `$to` (`DateTimeInterface`, required)

Returns `DateTimeImmutable`.

Throws:
- `InvalidArgumentException`: If $from is after $to.

### firstName()

```php
public function firstName(?string $sex = null): string
```

Parameters:
- `$sex` (`string|null`, default `null`): 'M' or 'F'; null = random.

Returns `string`.

Throws:
- `InvalidArgumentException`: If the sex is not M or F.

### lastName()

```php
public function lastName(): string
```

Returns `string`.

### name()

```php
public function name(?string $sex = null): string
```

First and last name ('Andreea Popescu').

Parameters:
- `$sex` (`string|null`, default `null`): 'M' or 'F'; null = random.

Returns `string`.

Throws:
- `InvalidArgumentException`: If the sex is not M or F.

### person()

```php
public function person(): array
```

Generates a complete and consistent person profile (CNP matching sex and birth date; email derived from name).

Returns `array{firstName: string, lastName: string, name: string, sex: 'M'|'F', birthDate: DateTimeImmutable, cnp: string, email: string, phone: string, address: array{street: string, number: string, city: string, county: string, postalCode: string, full: string}}`.

### cnp()

```php
public function cnp(?string $sex = null, ?DateTimeInterface $birthDate = null, ?string $county = null): string
```

Valid CNP. Without arguments: random sex, birth date (18-80 years old), and county.

Parameters:
- `$sex` (`string|null`, default `null`): 'M' or 'F'; null = random.
- `$birthDate` (`?DateTimeInterface`, default `null`)
- `$county` (`string|null`, default `null`): County code in CNP ('12' = Cluj, '40' = Bucharest, '70', etc.).

Returns `string`.

Throws:
- `InvalidArgumentException`: If sex, date (in future or before 1800), or county are invalid.

### cui()

```php
public function cui(bool $vatPrefix = false): string
```

Valid CUI (6-9 digits plus check digit), optionally with the 'RO' VAT prefix.

Parameters:
- `$vatPrefix` (`bool`, default `false`)

Returns `string`.

### iban()

```php
public function iban(?string $bank = null): string
```

Valid Romanian IBAN (mod 97) at a common bank or the specified bank (4-letter BIC code).

Parameters:
- `$bank` (`?string`, default `null`)

Returns `string`.

Throws:
- `InvalidArgumentException`: If the bank code is not 4 uppercase letters.

### phone()

```php
public function phone(bool $mobile = true): string
```

Romanian phone number in national format ('0721234567'): mobile (07...) or landline (02.../03...).

Parameters:
- `$mobile` (`bool`, default `true`)

Returns `string`.

### email()

```php
public function email(?string $name = null): string
```

Email on example.com (reserved for testing): derived from the given name or a random name.

Parameters:
- `$name` (`?string`, default `null`)

Returns `string`.

### address()

```php
public function address(): array
```

Address: street, number, city (county seat), county, postal code, and full formatted string.

Returns `array{street: string, number: string, city: string, county: string, postalCode: string, full: string}`.

### company()

```php
public function company(): array
```

Company: name and valid CUI.

Returns `array{name: string, cui: string}`.

