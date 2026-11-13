# Csv

> Streaming reader (auto-detected delimiter, BOM, Windows-1250 support), atomic writer, batch validation import.

Canonical: https://foundry.insightdevhub.ro/docs/csv/  
Namespace: `Foundry\Csv` · File: `Csv.php` · Version 1.5 · PHP 8.3+ · Extensions: mbstring, iconv

Reading, writing, and importing CSV files, Excel-compatible, engineered for large volumes (millions of rows): entirely streaming-based, inside the consumer's loop, with constant memory usage.

## Avoids pitfalls of native functions

- fgetcsv() returns false both on EOF and on error; an empty line becomes [null]
- the default '\' escape character of fgetcsv() / fputcsv() corrupts values containing '\"'
- Romanian Excel files use ';' as delimiter, include a BOM, and frequently use Windows-1250 encoding
- Excel executes formulas in values ('=HYPERLINK(...)', CSV injection).

Reading (read): first row is the header (no empty names or duplicates); delimiter is auto-detected from the header if not provided; BOM is stripped; in non-UTF-8 files, each field that is not valid UTF-8 is converted from the fallback encoding (default Windows-1250), valid UTF-8 fields are kept as they are. A row with a different field count than the header causes an error (trailing empty fields are tolerated). A record longer than 8 MB (typically an unclosed quote) rejects the file before reading, so memory stays bounded.

Writing (writer / write / download): the writer object receives rows one by one in the consumer's loop. UTF-8 with BOM and ';' by default (Romanian Excel), CRLF; final file is created atomically only on close(). With append: true, rows are appended to an existing file (for multi-request chunked exports). Values starting with = + - @ are prefixed with ' (prevents formula execution).

Importing (import): one or more files validated against a schema, delivered in batches. Each batch contains valid rows (normalized), detected errors, per-file statistics, and a resume cursor. The consumer writes the batch (e.g. insertMany), saves the cursor, and can stop at any time (e.g. web request timeout limit); import resumes from the cursor without re-reading processed files. Errors report file, row, column, and a stable code; file values never appear in messages.

Schema: ['column' => [rules]], where rules can be:

```text
'required' => true                       mandatory value (after trim)
'type' => 'string'|'int'|'decimal'|'date'|'bool'   (default 'string')
'format' => 'd.m.Y' or list of formats   for 'date' (default ['d.m.Y', 'Y-m-d'])
'scale' => 2, 'decimal' => ','           for 'decimal' (maximum decimals, separator)
'max' => 100                             maximum character length
'pattern' => '/^\d{13}$/'                regular expression
'in' => ['A', 'B']                       allowed values
'unique' => true                         no duplicates across all imported files
```

Normalized values: string trimmed (empty -> null), int, decimal as string '1234.50', date as 'Y-m-d', bool (1/0, true/false, yes/no, da/nu).

Uniqueness is tracked in memory, compactly (64-bit hash per value, ~40 MB for 1 million values), up to the maxUnique option. When resuming from a cursor, tracked values are persisted in the file specified via the uniqueFile option.

## Usage

```php
require_once 'Csv.php';
use Foundry\Csv;

foreach (Csv::read('customers.csv') as $row => $values) { ... }      // $values['name']

$out = Csv::writer('report.csv');                                  // header from first row keys
foreach ($db->cursor('SELECT ...') as $row) {
    $out->add($row);
}
$out->close();

foreach (Csv::import($files, $schema, batchSize: 5000, cursor: $cursor) as $batch) {
    $db->insertMany('customers', array_column($batch['rows'], 'data'));
    $report = [...$report, ...$batch['errors']];
    $cursor = $batch['cursor'];                                     // save to resume later
    if (microtime(true) - $start > 20) {
        break;                                                      // next request resumes from here
    }
}
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Csv.php';

use Foundry\Csv;
```

## Methods

### __destruct()

```php
public function __destruct()
```

An abandoned file (without close(), e.g. after an exception) does not appear at destination.

### read()

```php
public static function read(string $path, ?string $delimiter = null, string $fallbackEncoding = 'Windows-1250'): Generator
```

File rows as associative arrays with the header from the first row. The generator key is the file row number (the header is row 1).

Parameters:
- `$path` (`string`, required)
- `$delimiter` (`string|null`, default `null`): null = auto-detected from header (; , tab |).
- `$fallbackEncoding` (`string`, default `'Windows-1250'`)

Returns `Generator<int, array<string, string>>`.

Throws:
- `RuntimeException`: If the file cannot be read.
- `UnexpectedValueException`: If the file is not a CSV, header is invalid, a row has a mismatched field count, or contains invalid bytes in the fallback encoding.

### writer()

```php
public static function writer(string $path, ?array $header = null, string $delimiter = ';', bool $bom = true, string $dateFormat = 'Y-m-d H:i:s', bool $append = false): self
```

A CSV file written row by row: add() in a loop, followed by close().

Without $append, rows are written to a temporary file, and $path only appears at close() (atomically). With $append, rows are appended directly to $path (created if missing); for an existing file, $header is required and must match the existing file header.

Parameters:
- `$path` (`string`, required)
- `$header` (`list<string>|null`, default `null`): null = keys of the first row.
- `$delimiter` (`string`, default `';'`)
- `$bom` (`bool`, default `true`)
- `$dateFormat` (`string`, default `'Y-m-d H:i:s'`)
- `$append` (`bool`, default `false`)

Returns `Foundry\Csv`.

Throws:
- `InvalidArgumentException`: If delimiter or header is invalid.
- `RuntimeException`: If the file cannot be created.

### write()

```php
public static function write(string $path, iterable $rows, ?array $header = null, string $delimiter = ';', bool $bom = true, string $dateFormat = 'Y-m-d H:i:s'): void
```

Writes all rows to a file (shorthand for writer() + add() + close()).

Parameters:
- `$path` (`string`, required)
- `$rows` (`iterable<array<array-key, mixed>>`, required): Associative rows (header = keys of first row) or lists (with $header).
- `$header` (`list<string>|null`, default `null`)
- `$delimiter` (`string`, default `';'`)
- `$bom` (`bool`, default `true`)
- `$dateFormat` (`string`, default `'Y-m-d H:i:s'`)

Throws:
- `InvalidArgumentException`: If rows do not match the header or contain unsupported types.
- `RuntimeException`: If the file cannot be written.

### download()

```php
public static function download(string $filename, iterable $rows, ?array $header = null, string $delimiter = ';', bool $bom = true, string $dateFormat = 'Y-m-d H:i:s'): void
```

Streams rows to the browser as a downloadable file (HTTP headers + streamed content).

Parameters:
- `$filename` (`string`, required)
- `$rows` (`iterable<array<array-key, mixed>>`, required)
- `$header` (`list<string>|null`, default `null`)
- `$delimiter` (`string`, default `';'`)
- `$bom` (`bool`, default `true`)
- `$dateFormat` (`string`, default `'Y-m-d H:i:s'`)

Throws:
- `LogicException`: If HTTP headers have already been sent.
- `InvalidArgumentException`: If rows do not match the header or contain unsupported types.

### downloadFile()

```php
public static function downloadFile(string $path, ?string $filename = null): void
```

Streams a pre-generated file from disk to the browser (e.g. generated via writer() across multiple requests), without loading it into memory.

Parameters:
- `$path` (`string`, required)
- `$filename` (`?string`, default `null`)

Throws:
- `RuntimeException`: If the file does not exist or cannot be streamed.
- `LogicException`: If HTTP headers have already been sent.

### add()

```php
public function add(array $row): void
```

Adds a row: associative (keys = header, in same order) or list (in header order).

Parameters:
- `$row` (`array<array-key, mixed>`, required)

Throws:
- `LogicException`: If the file is already closed.
- `InvalidArgumentException`: If the row does not match the header or contains unsupported types.
- `RuntimeException`: If writing fails.

### close()

```php
public function close(): void
```

Closes the file; without append, the file only now appears at destination. Subsequent calls have no effect.

Throws:
- `RuntimeException`: If the file cannot be moved to its destination.

### count()

```php
public function count(): int
```

Number of added rows (excluding header).

Returns `int`.

### import()

```php
public static function import(array $files, array $schema, array $options = [], int $batchSize = 1000, ?array $cursor = null): Generator
```

Imports one or more files validated and normalized according to schema, in batches.

Each batch has at most $batchSize read rows and contains: rows    valid rows: ['file' => name, 'row' => number, 'data' => [column => value]] errors  errors found in the batch: ['file', 'row', 'column', 'code', 'message'] files   cumulative statistics: [name => ['status' => ok|invalid|unreadable, 'rows', 'valid', 'errors']] cursor  position after batch (JSON-serializable array); passed back into $cursor to resume done    true on the last batch (which may have empty rows) valid   no errors encountered so far (on done: across the entire import)

Parameter validation is performed immediately; files are only read as the generator progresses.

Parameters:
- `$files` (`array<array-key, string>`, required): File paths; with string keys, the key is the display name (e.g. original uploaded file name).
- `$schema` (`array<string, array<string, mixed>>`, required)
- `$options` (`array{delimiter?: string|null, fallbackEncoding?: string, allowExtraColumns?: bool, maxErrors?: int, uniqueFile?: string|null, maxUnique?: int}`, default `[]`): maxErrors: errors listed per file (remaining are only counted); uniqueFile: file for tracking unique values (required when resuming if schema uses 'unique'); maxUnique: limit of tracked unique values (OverflowException above it).
- `$batchSize` (`int`, default `1000`)
- `$cursor` (`array<string, mixed>|null`, default `null`): Cursor of the last processed batch, to resume.

Returns `Generator<int, array{rows: list<array{file: string, row: int, data: array<string, mixed>}>, errors: list<array{file: string, row: int|null, column: string|null, code: string, message: string}>, files: array<string, array{status: string, rows: int, valid: int, errors: int}>, cursor: array<string, mixed>, done: bool, valid: bool}>`.

Throws:
- `InvalidArgumentException`: If schema, options, file list, or cursor are invalid.

