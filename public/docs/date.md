# Date

> Strict parsing (31.02 fails), localization formatting, Romanian public holidays, business day calculations.

Canonical: https://foundry.insightdevhub.ro/docs/date/  
Namespace: `Foundry\Date` · File: `Date.php` · Version 1.2 · PHP 8.3+

Strict calendar dates, working days, and statutory public holidays in Romania.

## Pitfalls of native functions avoided

- strtotime() / date_create() return false on invalid values
- DateTime::createFromFormat() silently rolls over 31.02.2026 to 03.03.2026, and any time omitted from the format defaults to current time rather than 00:00
- 'Y' accepts '26' as year 0026
- date('F') formats months in English only, while setlocale() / strftime() depend on server environment (and strftime() is deprecated).

All returned values are DateTimeImmutable instances. The default timezone is from php.ini; date creation methods accept an explicit DateTimeZone.

Statutory holidays match the Romanian Labor Code (art. 139), including effective start years for each holiday; Easter and Pentecost follow the Orthodox calendar. If the law changes, holidays() and @version must be updated.

Error messages include the expected format rather than the provided input (which may be sensitive, like a date of birth).

## Usage

```php
require_once 'Date.php';
use Foundry\Date;

$date = Date::parse($_POST['data'], 'd.m.Y');          // 31.02.2026 -> throws exception
$db = Date::roToDb('15.03.2026');                      // '2026-03-15'
echo Date::format($date, 'j F Y');                     // '15 martie 2026'
$deadline = Date::addWorkingDays(Date::today(), 10);   // skips weekends and public holidays
$age = Date::age(Date::fromDb($row['birth_date']));
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Date.php';

use Foundry\Date;
```

## Constants

| Constant | Type | Value | Description |
| --- | --- | --- | --- |
| `RO_DATE` | `string` | `'d.m.Y'` |  |
| `RO_DATETIME` | `string` | `'d.m.Y H:i'` |  |
| `DB_DATE` | `string` | `'Y-m-d'` |  |
| `DB_DATETIME` | `string` | `'Y-m-d H:i:s'` |  |

## Methods

### parse()

```php
public static function parse(string $value, string $format = self::RO_DATE, ?DateTimeZone $timezone = null): DateTimeImmutable
```

Parses a date string matching an exact format. Omitted parts (e.g. time) default to 0 rather than current time.

Parameters:
- `$value` (`string`, required)
- `$format` (`string`, default `self::RO_DATE`)
- `$timezone` (`?DateTimeZone`, default `null`)

Returns `DateTimeImmutable`.

Throws:
- `InvalidArgumentException`: If the value is empty.
- `UnexpectedValueException`: If the value does not match the format or is not a real calendar date (e.g. 31.02).

### parseOrNull()

```php
public static function parseOrNull(?string $value, string $format = self::RO_DATE, ?DateTimeZone $timezone = null): ?DateTimeImmutable
```

Same as parse(), but null and empty strings (e.g. unfilled form fields) return null.

Parameters:
- `$value` (`?string`, required)
- `$format` (`string`, default `self::RO_DATE`)
- `$timezone` (`?DateTimeZone`, default `null`)

Returns `?DateTimeImmutable`.

Throws:
- `UnexpectedValueException`: If the value does not match the format or is not a real calendar date.

### fromDb()

```php
public static function fromDb(string $value, ?DateTimeZone $timezone = null): DateTimeImmutable
```

Parses a date from a database DATE / DATETIME column: 'Y-m-d', 'Y-m-d H:i:s', or 'Y-m-d H:i:s.u'. MySQL zero date ('0000-00-00') is rejected.

Parameters:
- `$value` (`string`, required)
- `$timezone` (`?DateTimeZone`, default `null`)

Returns `DateTimeImmutable`.

Throws:
- `InvalidArgumentException`: If the value is empty.
- `UnexpectedValueException`: If the value is not a valid date in database format.

### toDb()

```php
public static function toDb(DateTimeInterface $date, bool $withTime = false): string
```

Formats a date for a database DATE column ('Y-m-d') or, with $withTime, DATETIME ('Y-m-d H:i:s').

Parameters:
- `$date` (`DateTimeInterface`, required)
- `$withTime` (`bool`, default `false`)

Returns `string`.

### roToDb()

```php
public static function roToDb(string $value): string
```

Converts '15.03.2026' to '2026-03-15'.

Parameters:
- `$value` (`string`, required)

Returns `string`.

Throws:
- `InvalidArgumentException`: If the value is empty.
- `UnexpectedValueException`: If the value is not a valid 'd.m.Y' date.

### dbToRo()

```php
public static function dbToRo(string $value): string
```

Converts '2026-03-15' or '2026-03-15 10:30:00' to '15.03.2026' (time is not preserved).

Parameters:
- `$value` (`string`, required)

Returns `string`.

Throws:
- `InvalidArgumentException`: If the value is empty.
- `UnexpectedValueException`: If the value is not a valid date in database format.

### format()

```php
public static function format(DateTimeInterface $date, string $format = self::RO_DATE): string
```

Same as DateTimeInterface::format(), with month and day names in Romanian: F (martie), M (mar), l (luni), D (lun). Other characters retain their native format meaning.

Parameters:
- `$date` (`DateTimeInterface`, required)
- `$format` (`string`, default `self::RO_DATE`)

Returns `string`.

### today()

```php
public static function today(?DateTimeZone $timezone = null): DateTimeImmutable
```

Returns today's date at 00:00:00.

Parameters:
- `$timezone` (`?DateTimeZone`, default `null`)

Returns `DateTimeImmutable`.

### now()

```php
public static function now(?DateTimeZone $timezone = null): DateTimeImmutable
```

Returns current date and time.

Parameters:
- `$timezone` (`?DateTimeZone`, default `null`)

Returns `DateTimeImmutable`.

### diffInDays()

```php
public static function diffInDays(DateTimeInterface $from, DateTimeInterface $to): int
```

Calendar days between $from and $to (negative if $to is before $from). Time is ignored: from Monday 23:00 to Tuesday 01:00 counts as 1 day.

Parameters:
- `$from` (`DateTimeInterface`, required)
- `$to` (`DateTimeInterface`, required)

Returns `int`.

### diffInMonths()

```php
public static function diffInMonths(DateTimeInterface $from, DateTimeInterface $to): int
```

Full months between $from and $to (negative if $to is before $from). Time is ignored.

Parameters:
- `$from` (`DateTimeInterface`, required)
- `$to` (`DateTimeInterface`, required)

Returns `int`.

### diffInYears()

```php
public static function diffInYears(DateTimeInterface $from, DateTimeInterface $to): int
```

Full years between $from and $to (negative if $to is before $from). Time is ignored.

Parameters:
- `$from` (`DateTimeInterface`, required)
- `$to` (`DateTimeInterface`, required)

Returns `int`.

### age()

```php
public static function age(DateTimeInterface $birthDate, ?DateTimeInterface $on = null): int
```

Calculates age in completed years as of $on (defaults to today in the birth date's timezone).

Parameters:
- `$birthDate` (`DateTimeInterface`, required)
- `$on` (`?DateTimeInterface`, default `null`)

Returns `int`.

Throws:
- `InvalidArgumentException`: If the birth date is after the reference date.

### isWeekend()

```php
public static function isWeekend(DateTimeInterface $date): bool
```

Checks whether the date falls on a Saturday or Sunday.

Parameters:
- `$date` (`DateTimeInterface`, required)

Returns `bool`.

### isHoliday()

```php
public static function isHoliday(DateTimeInterface $date): bool
```

Checks whether the date is a statutory public holiday in Romania.

Parameters:
- `$date` (`DateTimeInterface`, required)

Returns `bool`.

Throws:
- `InvalidArgumentException`: If the year is before 2009 or after 2099.

### isWorkingDay()

```php
public static function isWorkingDay(DateTimeInterface $date): bool
```

Checks whether the date is a working day (neither weekend nor public holiday).

Parameters:
- `$date` (`DateTimeInterface`, required)

Returns `bool`.

Throws:
- `InvalidArgumentException`: If the year is before 2009 or after 2099.

### holidays()

```php
public static function holidays(int $year): array
```

Returns statutory public holidays in Romania (Labor Code, art. 139), sorted: ['2026-01-01' => 'Anul Nou', ...]. Supported years: 2009 - 2099 (the legal holiday schedule prior to 2009 was different).

Parameters:
- `$year` (`int`, required)

Returns `array<string, string>`.

Throws:
- `InvalidArgumentException`: If the year is outside the supported range.

### orthodoxEaster()

```php
public static function orthodoxEaster(int $year, ?DateTimeZone $timezone = null): DateTimeImmutable
```

Computes Orthodox Easter Sunday (Meeus algorithm for Julian calendar converted to Gregorian).

Parameters:
- `$year` (`int`, required)
- `$timezone` (`?DateTimeZone`, default `null`)

Returns `DateTimeImmutable`.

Throws:
- `InvalidArgumentException`: If the year is before 1583.

### addWorkingDays()

```php
public static function addWorkingDays(DateTimeInterface $date, int $days): DateTimeImmutable
```

Adds $days working days to the date (negative to subtract). Time is preserved. Passing 0 leaves the date unchanged even if it is not a working day.

Parameters:
- `$date` (`DateTimeInterface`, required)
- `$days` (`int`, required)

Returns `DateTimeImmutable`.

Throws:
- `InvalidArgumentException`: If the result falls outside the supported year range.

### workingDaysBetween()

```php
public static function workingDaysBetween(DateTimeInterface $from, DateTimeInterface $to): int
```

Returns the number of working days after $from up to and including $to (negative if $to is before $from). For any n: workingDaysBetween($d, addWorkingDays($d, n)) === n.

Parameters:
- `$from` (`DateTimeInterface`, required)
- `$to` (`DateTimeInterface`, required)

Returns `int`.

Throws:
- `InvalidArgumentException`: If the range falls outside supported years.

### startOfMonth()

```php
public static function startOfMonth(DateTimeInterface $date): DateTimeImmutable
```

Returns the first day of the month at 00:00:00.

Parameters:
- `$date` (`DateTimeInterface`, required)

Returns `DateTimeImmutable`.

### endOfMonth()

```php
public static function endOfMonth(DateTimeInterface $date): DateTimeImmutable
```

Returns the last day of the month at 23:59:59.

Parameters:
- `$date` (`DateTimeInterface`, required)

Returns `DateTimeImmutable`.

