# Xlsx

> Zero-dependency .xlsx reader/writer: streaming generation, split-archive ZIP exports when exceeding sheet limits, batch import.

Canonical: https://foundry.insightdevhub.ro/docs/xlsx/  
Namespace: `Foundry\Xlsx` · File: `Xlsx.php` · Version 1.6 · PHP 8.3+ · Extensions: mbstring, zip, xmlreader, xmlwriter

Reading, writing, and importing Excel .xlsx files without external libraries (a .xlsx file is a ZIP archive containing XML files; requires zip, xmlreader, and xmlwriter extensions). Designed for large volumes: completely streaming-based, inside the consumer's loop, with constant memory usage.

Reading (read / sheets): sheets are traversed as streams; shared strings for large workbooks are stored on disk. The first row is the header (no empty names or duplicates). Values retain their Excel types: int, float, string, bool, DateTimeImmutable (cells formatted as dates), null (empty cell). Error cells (#N/A, #DIV/0!...) are read as text. Formulas: reads the pre-calculated cached value. Legacy .xls format is not supported (it is binary); save as .xlsx first.

Writing: writer() receives rows one by one in the consumer's loop; using a .zip path, the export is automatically split into multiple .xlsx files (Excel limit: 1,048,576 rows per sheet). writeSheets() / download() write multiple sheets at once. Bold and frozen header, auto-filter, dates as Excel date cells (dd.mm.yyyy); final file is written atomically; Excel limits are checked. Text is never interpreted as a formula. downloadFile() streams a pre-written export.

Importing (import): one or more files validated against a schema, delivered in batches with a resume cursor; schema, normalization, batches, and reporting are identical to Csv::import(). In addition: error codes 'not_xlsx' (file is not .xlsx), 'sheet' (requested sheet is missing), and 'cell_error' (Excel cell error).

Fast resume (stage): a .xlsx file is compressed and cannot be read from an arbitrary byte offset, so without staging every resume re-parses the sheet up to the cursor. stage() converts the sheet once into a working file (one row per line with types preserved); with the 'staged' option, import reads from it and jumps directly to the cursor, just like CSV. The working file records source size, mtime, and sheet name: if the source has changed, import rejects it.

## Usage

```php
require_once 'Xlsx.php';
use Foundry\Xlsx;

foreach (Xlsx::read('customers.xlsx') as $row => $values) { ... }        // first sheet
foreach (Xlsx::read('customers.xlsx', 'Legal Entities') as $values) { ... }

$out = Xlsx::writer('/tmp/export.zip');                // .zip: multiple .xlsx files exceeding Excel row limit
foreach ($db->cursor('SELECT ...') as $row) {
    $out->add($row);
}
$out->close();
Xlsx::downloadFile('/tmp/export.zip', 'policies.zip');

Xlsx::writeSheets('report.xlsx', ['Policies' => $policies, 'Claims' => $claims]);

foreach (Xlsx::import($files, $schema, batchSize: 5000, cursor: $cursor) as $batch) {
    $db->insertMany('customers', array_column($batch['rows'], 'data'));
    $cursor = $batch['cursor'];
}

// In web requests: first request stages the files, subsequent requests import in chunks.
$total = Xlsx::stage($path, $jobDir.'/january.stage');        // number of rows (progress)
foreach (Xlsx::import(['january.xlsx' => $path], $schema, ['staged' => ['january.xlsx' => $jobDir.'/january.stage'],
    'uniqueFile' => $jobDir.'/unique.bin'], 5000, $cursor) as $batch) { ... }
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Xlsx.php';

use Foundry\Xlsx;
```

## Constants

| Constant | Type | Value | Description |
| --- | --- | --- | --- |
| `MAX_ROWS` | `int` | `1_048_576` |  |
| `MAX_COLUMNS` | `int` | `16_384` |  |
| `MAX_CELL_LENGTH` | `int` | `32_767` |  |

## Methods

### __destruct()

```php
public function __destruct()
```

An abandoned export (without close(), e.g. after an exception) does not appear at destination.

### sheets()

```php
public static function sheets(string $path): array
```

Sheet names in file order.

Parameters:
- `$path` (`string`, required)

Returns `list<string>`.

Throws:
- `RuntimeException`: If the file cannot be read.
- `UnexpectedValueException`: If the file is not a valid .xlsx file.

### read()

```php
public static function read(string $path, string|int|null $sheet = null): Generator
```

Rows of a sheet as associative arrays, with header from the first row. The generator key is the Excel row number.

Parameters:
- `$path` (`string`, required)
- `$sheet` (`string|int|null`, default `null`): Sheet name, 0-based index, or null for the first sheet.

Returns `Generator<int, array<string, mixed>>`.

Throws:
- `RuntimeException`: If the file cannot be read.
- `UnexpectedValueException`: If the file is not valid .xlsx, sheet is missing, header is invalid, or a row has values outside the header columns.

### write()

```php
public static function write(string $path, iterable $rows, ?array $header = null, string $sheet = 'Sheet1'): void
```

Writes rows to a single sheet.

Parameters:
- `$path` (`string`, required)
- `$rows` (`iterable<array<array-key, mixed>>`, required): Associative rows (header = keys of first row) or lists (with $header).
- `$header` (`list<string>|null`, default `null`)
- `$sheet` (`string`, default `'Sheet1'`)

Throws:
- `InvalidArgumentException`: If data exceeds Excel limits or contains unsupported types.
- `RuntimeException`: If the file cannot be written.

### writeSheets()

```php
public static function writeSheets(string $path, array $sheets): void
```

Writes multiple sheets: ['Sheet name' => associative rows].

Parameters:
- `$path` (`string`, required)
- `$sheets` (`array<string, iterable<array<string, mixed>>>`, required)

Throws:
- `InvalidArgumentException`: If sheets are empty, names are invalid, or data exceeds Excel limits.
- `RuntimeException`: If the file cannot be written.

### download()

```php
public static function download(string $filename, array $sheets): void
```

Streams a .xlsx with one or more sheets to the browser: ['Sheet name' => associative rows].

Parameters:
- `$filename` (`string`, required)
- `$sheets` (`array<string, iterable<array<string, mixed>>>`, required)

Throws:
- `LogicException`: If HTTP headers have already been sent.
- `InvalidArgumentException`: If sheets or data are invalid.
- `RuntimeException`: If the temporary file cannot be written.

### writer()

```php
public static function writer(string $path, ?array $header = null, string $sheet = 'Sheet1', int $maxRowsPerFile = self::MAX_ROWS - 1): self
```

A row-by-row streaming export: add() in a loop, followed by close().

With a .xlsx path, produces a single file (exceeding $maxRowsPerFile rows throws OverflowException). With a .zip path, the export is chunked into files of $maxRowsPerFile rows each (name_1.xlsx, name_2.xlsx...; a single file is named name.xlsx), each containing a header. The destination file appears only at close(), atomically.

Parameters:
- `$path` (`string`, required)
- `$header` (`list<string>|null`, default `null`): null = keys of the first row.
- `$sheet` (`string`, default `'Sheet1'`)
- `$maxRowsPerFile` (`int`, default `1048575`)

Returns `Foundry\Xlsx`.

Throws:
- `InvalidArgumentException`: If extension, sheet name, or limits are invalid.
- `RuntimeException`: If directory does not exist.

### add()

```php
public function add(array $row): void
```

Adds a row: associative (keys = header, in same order) or list (in header order).

Parameters:
- `$row` (`array<array-key, mixed>`, required)

Throws:
- `LogicException`: If the export is already closed.
- `OverflowException`: If a .xlsx path exceeds the row limit.
- `InvalidArgumentException`: If row does not match the header or contains invalid values.
- `RuntimeException`: If temporary files cannot be written.

### close()

```php
public function close(): void
```

Finalizes the export: final sheet, packaging (.zip if needed), and moving to destination. Subsequent calls have no effect.

Throws:
- `RuntimeException`: If file cannot be written.

### count()

```php
public function count(): int
```

Number of added rows (excluding headers).

Returns `int`.

### downloadFile()

```php
public static function downloadFile(string $path, ?string $filename = null): void
```

Streams a pre-generated file from disk (.xlsx or .zip) to the browser without loading it into memory.

Parameters:
- `$path` (`string`, required)
- `$filename` (`?string`, default `null`)

Throws:
- `RuntimeException`: If file does not exist or cannot be streamed.
- `LogicException`: If HTTP headers have already been sent.

### stage()

```php
public static function stage(string $path, string $workFile, string|int|null $sheet = null): int
```

Prepares a sheet for chunked import across multiple requests: converts it once into a working file (one row per line, types preserved) from which import() with the 'staged' option resumes directly at the cursor. Returns data row count (excluding header), useful for progress tracking. The working file appears only when fully written. Duration: roughly the time of one sheet read.

If stage() throws an exception (e.g. file is not .xlsx), importing without 'staged' reports the issue normally (not_xlsx, corrupt), as for any other file.

Parameters:
- `$path` (`string`, required)
- `$workFile` (`string`, required)
- `$sheet` (`string|int|null`, default `null`)

Returns `int`.

Throws:
- `RuntimeException`: If file cannot be read or working file cannot be written.
- `UnexpectedValueException`: If file is not valid .xlsx or sheet is missing.

### import()

```php
public static function import(array $files, array $schema, array $options = [], int $batchSize = 1000, ?array $cursor = null): Generator
```

Imports one or more files validated and normalized according to schema, in batches (same schema, batch format, and cursor as Csv::import()).

Each batch has at most $batchSize read rows and contains: rows    valid rows: ['file' => name, 'row' => number, 'data' => [column => value]] errors  errors found in the batch: ['file', 'row', 'column', 'code', 'message'] files   cumulative statistics: [name => ['status' => ok|invalid|unreadable, 'rows', 'valid', 'errors']] cursor  position after batch (JSON-serializable array); passed back into $cursor to resume done    true on the last batch (which may have empty rows) valid   no errors encountered so far (on done: across the entire import)

Parameter validation is performed immediately; files are only read as the generator progresses.

Parameters:
- `$files` (`array<array-key, string>`, required): File paths; with string keys, key is the display name.
- `$schema` (`array<string, array<string, mixed>>`, required)
- `$options` (`array{sheet?: string|int|null, allowExtraColumns?: bool, maxErrors?: int, uniqueFile?: string|null, maxUnique?: int, staged?: array<string, string>}`, default `[]`): staged: [file name => working file created with stage()]
- `$batchSize` (`int`, default `1000`)
- `$cursor` (`array<string, mixed>|null`, default `null`): Cursor of the last processed batch, to resume.

Returns `Generator<int, array{rows: list<array{file: string, row: int, data: array<string, mixed>}>, errors: list<array{file: string, row: int|null, column: string|null, code: string, message: string}>, files: array<string, array{status: string, rows: int, valid: int, errors: int}>, cursor: array<string, mixed>, done: bool, valid: bool}>`.

Throws:
- `InvalidArgumentException`: If schema, options, file list, or cursor are invalid.

