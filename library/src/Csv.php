<?php

declare(strict_types=1);

namespace Foundry;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Generator;
use InvalidArgumentException;
use LogicException;
use OverflowException;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * Csv Class
 *
 * Reading, writing, and importing CSV files, Excel-compatible, engineered for large volumes
 * (millions of rows): entirely streaming-based, inside the consumer's loop, with constant memory usage.
 *
 * Avoids pitfalls of native functions:
 *   - fgetcsv() returns false both on EOF and on error; an empty line becomes [null];
 *   - the default '\' escape character of fgetcsv() / fputcsv() corrupts values containing '\"';
 *   - Romanian Excel files use ';' as delimiter, include a BOM, and frequently use Windows-1250 encoding;
 *   - Excel executes formulas in values ('=HYPERLINK(...)', CSV injection).
 *
 * Reading (read): first row is the header (no empty names or duplicates); delimiter is auto-detected
 * from the header if not provided; BOM is stripped; in non-UTF-8 files, each field that is not valid
 * UTF-8 is converted from the fallback encoding (default Windows-1250), valid UTF-8 fields are kept as
 * they are. A row with a different field count than the header causes an error (trailing empty fields
 * are tolerated). A record longer than 8 MB (typically an unclosed quote) rejects the file before
 * reading, so memory stays bounded.
 *
 * Writing (writer / write / download): the writer object receives rows one by one in the consumer's loop.
 * UTF-8 with BOM and ';' by default (Romanian Excel), CRLF; final file is created atomically only on close().
 * With append: true, rows are appended to an existing file (for multi-request chunked exports).
 * Values starting with = + - @ are prefixed with ' (prevents formula execution).
 *
 * Importing (import): one or more files validated against a schema, delivered in batches. Each batch
 * contains valid rows (normalized), detected errors, per-file statistics, and a resume cursor.
 * The consumer writes the batch (e.g. insertMany), saves the cursor, and can stop at any time
 * (e.g. web request timeout limit); import resumes from the cursor without re-reading processed files.
 * Errors report file, row, column, and a stable code; file values never appear in messages.
 *
 * Schema: ['column' => [rules]], where rules can be:
 *   'required' => true                       mandatory value (after trim)
 *   'type' => 'string'|'int'|'decimal'|'date'|'bool'   (default 'string')
 *   'format' => 'd.m.Y' or list of formats   for 'date' (default ['d.m.Y', 'Y-m-d'])
 *   'scale' => 2, 'decimal' => ','           for 'decimal' (maximum decimals, separator)
 *   'max' => 100                             maximum character length
 *   'pattern' => '/^\d{13}$/'                regular expression
 *   'in' => ['A', 'B']                       allowed values
 *   'unique' => true                         no duplicates across all imported files
 * Normalized values: string trimmed (empty -> null), int, decimal as string '1234.50',
 * date as 'Y-m-d', bool (1/0, true/false, yes/no, da/nu).
 *
 * Uniqueness is tracked in memory, compactly (64-bit hash per value, ~40 MB for 1 million
 * values), up to the maxUnique option. When resuming from a cursor, tracked values are persisted
 * in the file specified via the uniqueFile option.
 *
 * Usage:
 *   require_once 'Csv.php';
 *   use Foundry\Csv;
 *
 *   foreach (Csv::read('customers.csv') as $row => $values) { ... }      // $values['name']
 *
 *   $out = Csv::writer('report.csv');                                  // header from first row keys
 *   foreach ($db->cursor('SELECT ...') as $row) {
 *       $out->add($row);
 *   }
 *   $out->close();
 *
 *   foreach (Csv::import($files, $schema, batchSize: 5000, cursor: $cursor) as $batch) {
 *       $db->insertMany('customers', array_column($batch['rows'], 'data'));
 *       $report = [...$report, ...$batch['errors']];
 *       $cursor = $batch['cursor'];                                     // save to resume later
 *       if (microtime(true) - $start > 20) {
 *           break;                                                      // next request resumes from here
 *       }
 *   }
 *
 * @category Utilities
 *
 * @version  1.5
 *
 * @since    PHP 8.3
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
final class Csv
{
    /** @var string UTF-8 BOM. */
    private const string BOM = "\xEF\xBB\xBF";

    /** @var list<string> Delimiters attempted during detection, in order of preference upon tie. */
    private const array DELIMITERS = [';', ',', "\t", '|'];

    /** @var list<string> Accepted rules in the import schema. */
    private const array RULES = ['required', 'type', 'format', 'scale', 'decimal', 'max', 'pattern', 'in', 'unique'];

    /** @var list<string> */
    private const array TYPES = ['string', 'int', 'decimal', 'date', 'bool'];

    /** @var array<int|string, bool> Accepted string values for 'bool'. */
    private const array BOOLEANS = ['1' => true, '0' => false, 'true' => true, 'false' => false, 'da' => true, 'nu' => false, 'yes' => true, 'no' => false];

    /** @var int File exception codes used by import() for reporting. */
    private const int ERROR_UNREADABLE = 1001;

    private const int ERROR_NOT_TEXT = 1002;

    private const int ERROR_CORRUPT = 1003;

    /** @var int Import cursor structure version. */
    private const int CURSOR_VERSION = 1;

    /** @var int Default maximum tracked unique values (~200 MB memory). */
    private const int MAX_UNIQUE = 5_000_000;

    /** @var int Longest accepted record (bytes): bounds memory when a quote is never closed. */
    private const int MAX_RECORD_BYTES = 8 << 20;

    private bool $closed = false;

    private int $written = 0;

    /**
     * @param  resource  $handle
     * @param  list<string>|null  $header
     */
    private function __construct(private readonly string $method, private readonly mixed $handle, private readonly string $path, private readonly ?string $temp, private ?array $header, private bool $headerPending, private readonly string $delimiter, private readonly string $dateFormat) {}

    /**
     * An abandoned file (without close(), e.g. after an exception) does not appear at destination.
     */
    public function __destruct()
    {
        if (! $this->closed) {
            $this->abort();
        }
    }

    /**
     * File rows as associative arrays with the header from the first row.
     * The generator key is the file row number (the header is row 1).
     *
     * @param  string|null  $delimiter  null = auto-detected from header (; , tab |).
     * @return Generator<int, array<string, string>>
     *
     * @throws RuntimeException If the file cannot be read.
     * @throws UnexpectedValueException If the file is not a CSV, header is invalid, a row has a mismatched
     *                                  field count, or contains invalid bytes in the fallback encoding.
     */
    public static function read(string $path, ?string $delimiter = null, string $fallbackEncoding = 'Windows-1250'): Generator
    {
        [$utf8, $delimiter] = self::prepare('read', $path, $delimiter, $fallbackEncoding);
        $header = null;

        foreach (self::records('read', $path, $utf8, $delimiter, $fallbackEncoding, null, 0) as $row => [$fields, , $invalid]) {
            if ($invalid) {
                throw new UnexpectedValueException("Csv::read(): row {$row} of '{$path}' contains bytes that are not valid {$fallbackEncoding}.", self::ERROR_CORRUPT);
            }

            if ($header === null) {
                $header = self::header('read', $fields);

                continue;
            }

            if (self::isEmpty($fields)) {
                continue;
            }

            $fields = self::fit($fields, count($header))
                ?? throw new UnexpectedValueException("Csv::read(): row {$row} has ".count($fields).' fields, the header has '.count($header).'.');

            yield $row => array_combine($header, $fields);
        }

        if ($header === null) {
            throw new UnexpectedValueException("Csv::read(): file '{$path}' is empty (no header row).", self::ERROR_CORRUPT);
        }
    }

    /**
     * A CSV file written row by row: add() in a loop, followed by close().
     *
     * Without $append, rows are written to a temporary file, and $path only appears at close() (atomically).
     * With $append, rows are appended directly to $path (created if missing); for an existing file,
     * $header is required and must match the existing file header.
     *
     * @param  list<string>|null  $header  null = keys of the first row.
     *
     * @throws InvalidArgumentException If delimiter or header is invalid.
     * @throws RuntimeException If the file cannot be created.
     */
    public static function writer(string $path, ?array $header = null, string $delimiter = ';', bool $bom = true, string $dateFormat = 'Y-m-d H:i:s', bool $append = false): self
    {
        return self::create('writer', $path, $header, $delimiter, $bom, $dateFormat, $append);
    }

    /**
     * Writes all rows to a file (shorthand for writer() + add() + close()).
     *
     * @param  iterable<array<array-key, mixed>>  $rows  Associative rows (header = keys of first row) or lists (with $header).
     * @param  list<string>|null  $header
     *
     * @throws InvalidArgumentException If rows do not match the header or contain unsupported types.
     * @throws RuntimeException If the file cannot be written.
     */
    public static function write(string $path, iterable $rows, ?array $header = null, string $delimiter = ';', bool $bom = true, string $dateFormat = 'Y-m-d H:i:s'): void
    {
        $writer = self::create('write', $path, $header, $delimiter, $bom, $dateFormat, false);

        try {
            foreach ($rows as $row) {
                $writer->add($row);
            }
            $writer->close();
        } catch (Throwable $e) {
            $writer->abort();

            throw $e;
        }
    }

    /**
     * Streams rows to the browser as a downloadable file (HTTP headers + streamed content).
     *
     * @param  iterable<array<array-key, mixed>>  $rows
     * @param  list<string>|null  $header
     *
     * @throws LogicException If HTTP headers have already been sent.
     * @throws InvalidArgumentException If rows do not match the header or contain unsupported types.
     */
    public static function download(string $filename, iterable $rows, ?array $header = null, string $delimiter = ';', bool $bom = true, string $dateFormat = 'Y-m-d H:i:s'): void
    {
        self::checkDelimiter('download', $delimiter);

        if (headers_sent($file, $line)) {
            throw new LogicException("Csv::download(): headers already sent (output started at {$file}:{$line}).");
        }

        $ascii = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$ascii.'"; filename*=UTF-8\'\''.rawurlencode($filename));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');

        $output = fopen('php://output', 'wb');
        if ($output === false) {
            throw new RuntimeException('Csv::download(): cannot open php://output.');
        }

        $writer = new self('download', $output, 'php://output', null, $header, true, $delimiter, $dateFormat);
        if ($bom) {
            self::put('download', $output, self::BOM);
        }
        if ($header !== null) {
            $writer->writeHeader();
        }

        foreach ($rows as $row) {
            $writer->add($row);
        }
        $writer->close();
    }

    /**
     * Streams a pre-generated file from disk to the browser (e.g. generated via writer() across multiple requests),
     * without loading it into memory.
     *
     * @throws RuntimeException If the file does not exist or cannot be streamed.
     * @throws LogicException If HTTP headers have already been sent.
     */
    public static function downloadFile(string $path, ?string $filename = null): void
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Csv::downloadFile(): file '{$path}' does not exist or is not readable.");
        }

        if (headers_sent($file, $line)) {
            throw new LogicException("Csv::downloadFile(): headers already sent (output started at {$file}:{$line}).");
        }

        $filename ??= basename($path);
        $ascii = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$ascii.'"; filename*=UTF-8\'\''.rawurlencode($filename));
        header('Content-Length: '.filesize($path));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');

        if (self::capture(static fn (): int|false => readfile($path))[0] === false) {
            throw new RuntimeException("Csv::downloadFile(): cannot send '{$path}'.");
        }
    }

    /**
     * Adds a row: associative (keys = header, in same order) or list (in header order).
     *
     * @param  array<array-key, mixed>  $row
     *
     * @throws LogicException If the file is already closed.
     * @throws InvalidArgumentException If the row does not match the header or contains unsupported types.
     * @throws RuntimeException If writing fails.
     */
    public function add(array $row): void
    {
        if ($this->closed) {
            throw new LogicException("Csv::{$this->method}(): the file is already closed.");
        }

        $header = $this->header ??= array_map(strval(...), array_keys($row));
        if ($this->headerPending) {
            $this->writeHeader();
        }

        if (array_is_list($row)) {
            if (count($row) !== count($header)) {
                throw new InvalidArgumentException("Csv::{$this->method}(): row #{$this->written} has ".count($row).' values, the header has '.count($header).'.');
            }
            $values = $row;
        } else {
            if (array_map(strval(...), array_keys($row)) !== $header) {
                throw new InvalidArgumentException("Csv::{$this->method}(): the keys of row #{$this->written} do not match the header (same columns, same order).");
            }
            $values = array_values($row);
        }

        $fields = [];
        foreach ($values as $index => $value) {
            $fields[] = $this->field($this->method, $value, $this->dateFormat, $this->written, $header[$index]);
        }

        $this->putCsv($this->method, $this->handle, $fields, $this->delimiter);
        $this->written++;
    }

    /**
     * Closes the file; without append, the file only now appears at destination. Subsequent calls have no effect.
     *
     * @throws RuntimeException If the file cannot be moved to its destination.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        if ($this->headerPending && $this->header !== null) {
            $this->writeHeader();
        }

        $this->closed = true;
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }

        if ($this->temp !== null) {
            $temp = $this->temp;
            $path = $this->path;
            $this->applyMode($temp, $path);
            [$renamed, $warning] = self::capture(static fn (): bool => rename($temp, $path));
            if (! $renamed) {
                self::capture(static fn (): bool => unlink($temp));

                throw new RuntimeException("Csv::{$this->method}(): cannot write '{$path}': ".($warning ?? 'unknown error').'.');
            }
        }
    }

    /**
     * Number of added rows (excluding header).
     */
    public function count(): int
    {
        return $this->written;
    }

    /**
     * Imports one or more files validated and normalized according to schema, in batches.
     *
     * Each batch has at most $batchSize read rows and contains:
     *   rows    valid rows: ['file' => name, 'row' => number, 'data' => [column => value]]
     *   errors  errors found in the batch: ['file', 'row', 'column', 'code', 'message']
     *   files   cumulative statistics: [name => ['status' => ok|invalid|unreadable, 'rows', 'valid', 'errors']]
     *   cursor  position after batch (JSON-serializable array); passed back into $cursor to resume
     *   done    true on the last batch (which may have empty rows)
     *   valid   no errors encountered so far (on done: across the entire import)
     *
     * Parameter validation is performed immediately; files are only read as the generator progresses.
     *
     * @param  array<array-key, string>  $files  File paths; with string keys, the key is the display name
     *                                           (e.g. original uploaded file name).
     * @param  array<string, array<string, mixed>>  $schema
     * @param  array{delimiter?: string|null, fallbackEncoding?: string, allowExtraColumns?: bool, maxErrors?: int, uniqueFile?: string|null, maxUnique?: int}  $options
     *                                                                                                                                                                    maxErrors: errors listed per file (remaining are only counted);
     *                                                                                                                                                                    uniqueFile: file for tracking unique values (required when resuming if schema uses 'unique');
     *                                                                                                                                                                    maxUnique: limit of tracked unique values (OverflowException above it).
     * @param  array<string, mixed>|null  $cursor  Cursor of the last processed batch, to resume.
     * @return Generator<int, array{rows: list<array{file: string, row: int, data: array<string, mixed>}>, errors: list<array{file: string, row: int|null, column: string|null, code: string, message: string}>, files: array<string, array{status: string, rows: int, valid: int, errors: int}>, cursor: array<string, mixed>, done: bool, valid: bool}>
     *
     * @throws InvalidArgumentException If schema, options, file list, or cursor are invalid.
     */
    public static function import(array $files, array $schema, array $options = [], int $batchSize = 1000, ?array $cursor = null): Generator
    {
        if ($files === []) {
            throw new InvalidArgumentException('Csv::import(): no files given.');
        }

        if ($batchSize < 1) {
            throw new InvalidArgumentException("Csv::import(): batchSize must be >= 1, got {$batchSize}.");
        }

        $schema = self::schema('import', $schema);

        $names = [];
        $paths = [];
        foreach ($files as $key => $path) {
            $name = is_string($key) ? $key : basename($path);
            if (in_array($name, $names, true)) {
                throw new InvalidArgumentException("Csv::import(): duplicate file name '{$name}'.");
            }
            $names[] = $name;
            $paths[] = $path;
        }

        $settings = [
            'delimiter' => $options['delimiter'] ?? null,
            'fallback' => $options['fallbackEncoding'] ?? 'Windows-1250',
            'allowExtra' => $options['allowExtraColumns'] ?? false,
            'maxErrors' => $options['maxErrors'] ?? 100,
            'uniqueFile' => $options['uniqueFile'] ?? null,
            'maxUnique' => $options['maxUnique'] ?? self::MAX_UNIQUE,
        ];

        if ($settings['delimiter'] !== null) {
            self::checkDelimiter('import', $settings['delimiter']);
        }
        self::checkEncoding('import', $settings['fallback']);

        $uniqueColumns = array_keys(array_filter($schema, static fn (array $rule): bool => ($rule['unique'] ?? false) === true));
        if ($cursor !== null && $uniqueColumns !== [] && $settings['uniqueFile'] === null) {
            throw new InvalidArgumentException("Csv::import(): resuming an import with 'unique' columns requires the 'uniqueFile' option.");
        }

        $state = self::cursorState('import', $cursor, $names);

        return self::batches($names, $paths, $schema, $uniqueColumns, $settings, $batchSize, $state);
    }

    /**
     * Batch generator implementation backing import().
     *
     * @param  list<string>  $names
     * @param  list<string>  $paths
     * @param  array<string, array<string, mixed>>  $schema
     * @param  list<string>  $uniqueColumns
     * @param  array{delimiter: string|null, fallback: string, allowExtra: bool, maxErrors: int, uniqueFile: string|null, maxUnique: int}  $settings
     * @param  array{file: int, offset: int|null, row: int, utf8: bool|null, delimiter: string|null, files: array<string, array{status: string, rows: int, valid: int, errors: int}>}  $state
     * @return Generator<int, array{rows: list<array{file: string, row: int, data: array<string, mixed>}>, errors: list<array{file: string, row: int|null, column: string|null, code: string, message: string}>, files: array<string, array{status: string, rows: int, valid: int, errors: int}>, cursor: array<string, mixed>, done: bool, valid: bool}>
     */
    private static function batches(array $names, array $paths, array $schema, array $uniqueColumns, array $settings, int $batchSize, array $state): Generator
    {
        $seen = $uniqueColumns === [] ? [] : self::loadUnique($settings['uniqueFile'], $state['file'] > 0 || $state['offset'] !== null, $settings['maxUnique']);
        $pendingUnique = '';
        $stats = $state['files'];
        $rows = [];
        $errors = [];
        $read = 0;
        $batch = 0;
        $fingerprint = self::fingerprint($names);

        for ($f = $state['file']; $f < count($names); $f++) {
            $name = $names[$f];
            $path = $paths[$f];
            $resume = $f === $state['file'] && $state['offset'] !== null;
            $previous = $stats[$name] ?? ['status' => 'ok', 'rows' => 0, 'valid' => 0, 'errors' => 0];
            $status = $previous['status'];
            $dataRows = $previous['rows'];
            $validRows = $previous['valid'];
            $errorCount = $previous['errors'];

            $report = static function (?int $row, ?string $column, string $code, string $message) use (&$errors, &$status, &$errorCount, $name, $settings): void {
                $count = ++$errorCount;
                if ($status === 'ok') {
                    $status = 'invalid';
                }
                if ($count <= $settings['maxErrors']) {
                    $errors[] = ['file' => $name, 'row' => $row, 'column' => $column, 'code' => $code, 'message' => $message];
                } elseif ($count === $settings['maxErrors'] + 1) {
                    $errors[] = ['file' => $name, 'row' => null, 'column' => null, 'code' => 'too_many_errors', 'message' => "More than {$settings['maxErrors']} errors; the rest are not listed."];
                }
            };

            try {
                [$utf8, $delimiter] = $resume && $state['utf8'] !== null && $state['delimiter'] !== null
                    ? [$state['utf8'], $state['delimiter']]
                    : self::prepare('import', $path, $settings['delimiter'], $settings['fallback']);

                $mapping = null;
                $records = self::records('import', $path, $utf8, $delimiter, $settings['fallback'], $resume ? $state['offset'] : null, $resume ? $state['row'] : 0);

                foreach ($records as $row => [$fields, $next, $invalid]) {
                    if ($mapping === null) {
                        $mapping = self::mapHeader(self::header('import', $fields), array_keys($schema), $settings['allowExtra'], $report);
                        if ($mapping === false) {
                            break;
                        }

                        continue;
                    }

                    if ($invalid) {
                        $dataRows++;
                        $report($row, null, 'encoding', "The row contains bytes that are not valid {$settings['fallback']}.");
                    } elseif (! self::isEmpty($fields)) {
                        $dataRows++;
                        [$data, $valid] = self::validateRow($row, $fields, $mapping, $schema, $report);

                        // Duplicatele se raporteaza si pe randurile cu alte erori (valoarea invalida este null).
                        foreach ($uniqueColumns as $column) {
                            if (($data[$column] ?? null) === null) {
                                continue;
                            }
                            $hash = self::uniqueHash($column, $data[$column]);
                            if (isset($seen[$hash]) && $seen[$hash] !== (($f << 32) | $row)) {
                                $report($row, $column, 'unique', "Duplicate value (first seen in '".($names[$seen[$hash] >> 32] ?? '?')."', row ".($seen[$hash] & 0xFFFFFFFF).').');
                                $valid = false;
                            }
                        }

                        if ($valid) {
                            foreach ($uniqueColumns as $column) {
                                if ($data[$column] !== null) {
                                    $hash = self::uniqueHash($column, $data[$column]);
                                    if (! isset($seen[$hash])) {
                                        if (count($seen) >= $settings['maxUnique']) {
                                            throw new OverflowException("Csv::import(): more than {$settings['maxUnique']} unique values; raise 'maxUnique' or check uniqueness in the database.");
                                        }
                                        $seen[$hash] = ($f << 32) | $row;
                                        $pendingUnique .= pack('qJ', $hash, $seen[$hash]);
                                    }
                                }
                            }
                            $validRows++;
                            $rows[] = ['file' => $name, 'row' => $row, 'data' => $data];
                        }
                    }

                    if (++$read >= $batchSize) {
                        $pendingUnique = self::flushUnique($settings['uniqueFile'], $pendingUnique);
                        $stats[$name] = ['status' => $status, 'rows' => $dataRows, 'valid' => $validRows, 'errors' => $errorCount];
                        $cursor = ['version' => self::CURSOR_VERSION, 'files' => $fingerprint, 'file' => $f, 'offset' => $next, 'row' => $row, 'utf8' => $utf8, 'delimiter' => $delimiter, 'stats' => $stats];

                        yield $batch++ => ['rows' => $rows, 'errors' => $errors, 'files' => $stats, 'cursor' => $cursor, 'done' => false, 'valid' => self::allValid($stats)];
                        $rows = [];
                        $errors = [];
                        $read = 0;
                    }
                }

                if ($mapping === null) {
                    $report(null, null, 'empty', 'The file is empty (no header row).');
                } elseif ($mapping !== false && $dataRows === 0) {
                    $report(null, null, 'empty', 'The file has no data rows.');
                }
            } catch (RuntimeException $e) {
                $code = match ($e->getCode()) {
                    self::ERROR_UNREADABLE => 'unreadable',
                    self::ERROR_NOT_TEXT => 'not_text',
                    self::ERROR_CORRUPT => 'corrupt',
                    default => throw $e,
                };
                $report(null, null, $code, self::fileMessage($e));
                $status = $code === 'corrupt' ? 'invalid' : 'unreadable';
            }

            $stats[$name] = ['status' => $status, 'rows' => $dataRows, 'valid' => $validRows, 'errors' => $errorCount];
        }

        self::flushUnique($settings['uniqueFile'], $pendingUnique);
        $cursor = ['version' => self::CURSOR_VERSION, 'files' => $fingerprint, 'file' => count($names), 'offset' => null, 'row' => 0, 'utf8' => null, 'delimiter' => null, 'stats' => $stats];

        yield $batch => ['rows' => $rows, 'errors' => $errors, 'files' => $stats, 'cursor' => $cursor, 'done' => true, 'valid' => self::allValid($stats)];
    }

    /**
     * Validates a row against the schema (errors are reported); invalid values are set to null.
     *
     * @param  list<string>  $fields
     * @param  array{columns: array<string, int>, width: int}  $mapping
     * @param  array<string, array<string, mixed>>  $schema
     * @param  Closure(int|null, string|null, string, string): void  $report
     * @return array{0: array<string, mixed>, 1: bool} [normalized values, whether the row is valid]
     */
    private static function validateRow(int $row, array $fields, array $mapping, array $schema, Closure $report): array
    {
        $fields = self::fit($fields, $mapping['width']);
        if ($fields === null) {
            $report($row, null, 'field_count', "The row does not have {$mapping['width']} fields like the header.");

            return [[], false];
        }

        $data = [];
        $valid = true;
        foreach ($schema as $column => $rule) {
            [$code, $value] = self::normalize($rule, $fields[$mapping['columns'][$column]]);
            if ($code !== null) {
                $report($row, $column, $code, self::ruleMessage($code, $rule));
                $valid = false;
            }
            $data[$column] = $value;
        }

        return [$data, $valid];
    }

    /**
     * Starting state: from beginning or from validated cursor.
     *
     * @param  array<string, mixed>|null  $cursor
     * @param  list<string>  $names
     * @return array{file: int, offset: int|null, row: int, utf8: bool|null, delimiter: string|null, files: array<string, array{status: string, rows: int, valid: int, errors: int}>}
     *
     * @throws InvalidArgumentException If cursor is invalid or belongs to another import.
     */
    private static function cursorState(string $method, ?array $cursor, array $names): array
    {
        if ($cursor === null) {
            return ['file' => 0, 'offset' => null, 'row' => 0, 'utf8' => null, 'delimiter' => null, 'files' => []];
        }

        $valid = ($cursor['version'] ?? null) === self::CURSOR_VERSION
            && is_int($cursor['file'] ?? null) && $cursor['file'] >= 0 && $cursor['file'] <= count($names)
            && (($cursor['offset'] ?? null) === null || (is_int($cursor['offset']) && $cursor['offset'] >= 0))
            && is_int($cursor['row'] ?? null) && $cursor['row'] >= 0
            && (($cursor['utf8'] ?? null) === null || is_bool($cursor['utf8']))
            && (($cursor['delimiter'] ?? null) === null || (is_string($cursor['delimiter']) && strlen($cursor['delimiter']) === 1))
            && is_array($cursor['stats'] ?? null);

        if (! $valid) {
            throw new InvalidArgumentException("Csv::{$method}(): invalid cursor.");
        }

        if (($cursor['files'] ?? null) !== self::fingerprint($names)) {
            throw new InvalidArgumentException("Csv::{$method}(): the cursor belongs to a different list of files.");
        }

        $files = [];
        foreach ($cursor['stats'] as $name => $stats) {
            if (! is_string($name) || ! is_array($stats) || ! is_string($stats['status'] ?? null) || ! is_int($stats['rows'] ?? null) || ! is_int($stats['valid'] ?? null) || ! is_int($stats['errors'] ?? null)) {
                throw new InvalidArgumentException("Csv::{$method}(): invalid cursor.");
            }
            $files[$name] = ['status' => $stats['status'], 'rows' => $stats['rows'], 'valid' => $stats['valid'], 'errors' => $stats['errors']];
        }

        return [
            'file' => $cursor['file'],
            'offset' => is_int($cursor['offset']) ? $cursor['offset'] : null,
            'row' => $cursor['row'],
            'utf8' => is_bool($cursor['utf8']) ? $cursor['utf8'] : null,
            'delimiter' => is_string($cursor['delimiter']) ? $cursor['delimiter'] : null,
            'files' => $files,
        ];
    }

    /**
     * @param  list<string>  $names
     */
    private static function fingerprint(array $names): string
    {
        return hash('sha256', implode("\0", $names));
    }

    /**
     * @param  array<string, array{status: string, rows: int, valid: int, errors: int}>  $stats
     */
    private static function allValid(array $stats): bool
    {
        foreach ($stats as $file) {
            if ($file['status'] !== 'ok' || $file['errors'] > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * 64-bit hash of a unique value (includes column name: same value across different columns is not a duplicate).
     */
    private static function uniqueHash(string $column, mixed $value): int
    {
        $unpacked = unpack('q', hash('fnv1a64', $column."\0".self::scalarKey($value), true));

        return is_array($unpacked) && is_int($unpacked[1] ?? null) ? $unpacked[1] : 0;
    }

    /**
     * Unique values tracked in previous runs (or truncates the file for a fresh import).
     *
     * @return array<int, int> hash => position of first occurrence (file << 32 | row)
     *
     * @throws RuntimeException If the file cannot be read or written.
     * @throws OverflowException If values exceed $maxUnique.
     */
    private static function loadUnique(?string $file, bool $resume, int $maxUnique): array
    {
        if ($file === null) {
            return [];
        }

        if (! $resume || ! is_file($file)) {
            if (self::capture(static fn (): int|false => file_put_contents($file, ''))[0] === false) {
                throw new RuntimeException("Csv::import(): cannot write the unique values file '{$file}'.");
            }

            return [];
        }

        $data = self::capture(static fn (): string|false => file_get_contents($file))[0];
        if (! is_string($data) || strlen($data) % 16 !== 0) {
            throw new RuntimeException("Csv::import(): the unique values file '{$file}' is unreadable or damaged.");
        }

        $count = intdiv(strlen($data), 16);
        if ($count > $maxUnique) {
            throw new OverflowException("Csv::import(): more than {$maxUnique} unique values; raise 'maxUnique' or check uniqueness in the database.");
        }

        $seen = [];
        for ($i = 0; $i < $count; $i++) {
            $record = unpack('qhash/Jposition', $data, $i * 16);
            if (is_array($record) && is_int($record['hash']) && is_int($record['position'])) {
                $seen[$record['hash']] ??= $record['position'];
            }
        }

        return $seen;
    }

    /**
     * Appends new unique values to the file; returns empty buffer.
     *
     * @throws RuntimeException If the file cannot be written.
     */
    private static function flushUnique(?string $file, string $pending): string
    {
        if ($file === null || $pending === '') {
            return '';
        }

        if (self::capture(static fn (): int|false => file_put_contents($file, $pending, FILE_APPEND | LOCK_EX))[0] !== strlen($pending)) {
            throw new RuntimeException("Csv::import(): cannot write the unique values file '{$file}'.");
        }

        return '';
    }

    /**
     * Inspects the file to determine encoding (UTF-8 or fallback) and delimiter.
     *
     * @return array{0: bool, 1: string} [is UTF-8, delimiter]
     *
     * @throws InvalidArgumentException If delimiter or encoding is invalid.
     * @throws RuntimeException If the file cannot be read.
     * @throws UnexpectedValueException If the file is not text.
     */
    private static function prepare(string $method, string $path, ?string $delimiter, string $fallbackEncoding): array
    {
        if ($delimiter !== null) {
            self::checkDelimiter($method, $delimiter);
        }
        self::checkEncoding($method, $fallbackEncoding);

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Csv::{$method}(): file '{$path}' does not exist or is not readable.", self::ERROR_UNREADABLE);
        }

        $utf8 = self::inspect($method, $path);

        if ($delimiter === null) {
            $handle = self::open($method, $path, $path);
            try {
                $first = self::capture(static fn (): string|false => fgets($handle))[0];
            } finally {
                fclose($handle);
            }
            $delimiter = self::detectDelimiter(is_string($first) ? $first : '');
        }

        return [$utf8, $delimiter];
    }

    /**
     * File records: row number => [UTF-8 fields, position of next record, true if row has invalid bytes in fallback encoding].
     * Header (row 1) is always yielded first; with $offset, reading continues from that position (resuming without re-reading).
     *
     * @return Generator<int, array{0: list<string>, 1: int, 2: bool}>
     *
     * @throws RuntimeException If the file cannot be read.
     */
    private static function records(string $method, string $path, bool $utf8, string $delimiter, string $fallbackEncoding, ?int $offset, int $row): Generator
    {
        $handle = self::open($method, $path, $path);
        $header = true;

        try {
            while (true) {
                [$fields, $warning] = self::capture(static fn (): array|false => fgetcsv($handle, null, $delimiter, '"', ''));

                if ($warning !== null) {
                    throw new RuntimeException("Csv::{$method}(): cannot read '{$path}': {$warning}", self::ERROR_UNREADABLE);
                }

                if ($fields === false) {
                    break;
                }

                $position = ftell($handle);
                if ($position === false) {
                    throw new RuntimeException("Csv::{$method}(): cannot read the position in '{$path}'.", self::ERROR_UNREADABLE);
                }

                $fields = array_map(static fn (?string $field): string => $field ?? '', $fields);
                $invalid = false;

                if ($header && str_starts_with($fields[0], self::BOM)) {
                    $fields[0] = substr($fields[0], strlen(self::BOM));
                }

                if (! $utf8) {
                    foreach ($fields as $index => $field) {
                        // Decided per field: a UTF-8 file with one stray byte keeps its valid UTF-8 fields
                        // as they are, instead of turning every 'ș' in the file into 'Č™'.
                        if (mb_check_encoding($field, 'UTF-8')) {
                            continue;
                        }
                        $converted = self::capture(static fn (): string|false => iconv($fallbackEncoding, 'UTF-8', $field))[0];
                        if ($converted === false) {
                            $invalid = true;

                            break;
                        }
                        $fields[$index] = $converted;
                    }
                }

                if ($header) {
                    $header = false;
                    yield 1 => [$fields, $position, $invalid];

                    if ($offset !== null) {
                        if (fseek($handle, $offset) !== 0) {
                            throw new RuntimeException("Csv::{$method}(): cannot resume '{$path}' at byte {$offset}.", self::ERROR_UNREADABLE);
                        }
                    } else {
                        $row = 1;
                    }

                    continue;
                }

                yield ++$row => [$fields, $position, $invalid];
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  list<string>|null  $header
     *
     * @throws InvalidArgumentException If delimiter or header is invalid.
     * @throws RuntimeException If the file cannot be created.
     */
    private static function create(string $method, string $path, ?array $header, string $delimiter, bool $bom, string $dateFormat, bool $append): self
    {
        self::checkDelimiter($method, $delimiter);

        $directory = dirname($path);
        if (! is_dir($directory)) {
            throw new RuntimeException("Csv::{$method}(): directory '{$directory}' does not exist.");
        }

        if ($append && is_file($path) && filesize($path) > 0) {
            if ($header === null) {
                throw new InvalidArgumentException("Csv::{$method}(): appending to an existing file requires the header.");
            }

            $existing = self::existingHeader($method, $path, $delimiter);
            if ($existing !== $header) {
                throw new InvalidArgumentException("Csv::{$method}(): the header of '{$path}' is different from the given header.");
            }

            return new self($method, self::openForWrite($method, $path, 'ab'), $path, null, $header, false, $delimiter, $dateFormat);
        }

        $temp = null;
        if (! $append) {
            [$temp, $warning] = self::capture(static fn (): string|false => tempnam($directory, '.csv'));
            if ($temp === false) {
                throw new RuntimeException("Csv::{$method}(): cannot create a temporary file in '{$directory}': ".($warning ?? 'unknown error').'.');
            }
        }

        $handle = self::openForWrite($method, $temp ?? $path, $append ? 'ab' : 'wb');
        $writer = new self($method, $handle, $path, $temp, $header, true, $delimiter, $dateFormat);

        if ($bom) {
            self::put($method, $handle, self::BOM);
        }
        if ($header !== null) {
            $writer->writeHeader();
        }

        return $writer;
    }

    /**
     * Existing file header (without BOM) for append verification.
     *
     * @return list<string>
     *
     * @throws RuntimeException If the file cannot be read.
     */
    private static function existingHeader(string $method, string $path, string $delimiter): array
    {
        $handle = self::open($method, $path, $path);

        try {
            $fields = self::capture(static fn (): array|false => fgetcsv($handle, null, $delimiter, '"', ''))[0];
        } finally {
            fclose($handle);
        }

        $fields = is_array($fields) ? array_map(static fn (?string $field): string => $field ?? '', $fields) : [];
        if ($fields !== [] && str_starts_with($fields[0], self::BOM)) {
            $fields[0] = substr($fields[0], strlen(self::BOM));
        }

        return $fields;
    }

    /**
     * @return resource
     *
     * @throws RuntimeException If the file cannot be opened.
     */
    private static function openForWrite(string $method, string $path, string $mode): mixed
    {
        [$handle, $warning] = self::capture(static fn (): mixed => fopen($path, $mode));

        if (! is_resource($handle)) {
            throw new RuntimeException("Csv::{$method}(): cannot open '{$path}' for writing: ".($warning ?? 'unknown error').'.');
        }

        return $handle;
    }

    /**
     * @throws RuntimeException If writing fails.
     */
    private function writeHeader(): void
    {
        if ($this->header !== null) {
            $this->putCsv($this->method, $this->handle, $this->header, $this->delimiter);
            $this->headerPending = false;
        }
    }

    /**
     * Aborts the file: closes and removes the temporary file (destination remains untouched).
     */
    private function abort(): void
    {
        $this->closed = true;
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }

        $temp = $this->temp;
        if ($temp !== null && is_file($temp)) {
            self::capture(static fn (): bool => unlink($temp));
        }
    }

    /**
     * @throws InvalidArgumentException If delimiter is not a single valid byte.
     */
    private static function checkDelimiter(string $method, string $delimiter): void
    {
        if (strlen($delimiter) !== 1 || in_array($delimiter, ['"', "\r", "\n"], true)) {
            throw new InvalidArgumentException("Csv::{$method}(): delimiter must be a single byte other than quote or newline.");
        }
    }

    /**
     * @throws InvalidArgumentException If iconv does not support the encoding.
     */
    private static function checkEncoding(string $method, string $encoding): void
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/D', $encoding) !== 1 || self::capture(static fn (): string|false => iconv($encoding, 'UTF-8', 'a'))[0] === false) {
            throw new InvalidArgumentException("Csv::{$method}(): unsupported fallback encoding '{$encoding}'.");
        }
    }

    /**
     * Inspects the file before reading: rejects binary files (XLSX, XLS, PDF...) and determines whether it is UTF-8.
     * Reads in chunks, so memory usage is independent of file size.
     *
     * @throws RuntimeException If the file cannot be read.
     * @throws UnexpectedValueException If the file is not text.
     */
    private static function inspect(string $method, string $path): bool
    {
        $handle = self::open($method, $path, $path);
        $utf8 = true;
        $carry = '';
        $first = true;
        $quoted = false;
        $record = 0;

        try {
            while (! feof($handle)) {
                $chunk = fread($handle, 1 << 20);
                if ($chunk === false) {
                    throw new RuntimeException("Csv::{$method}(): cannot read '{$path}'.", self::ERROR_UNREADABLE);
                }

                // Record length, following quotes: fgetcsv() keeps reading an unclosed "..." field until the end
                // of the file, so one stray quote would load the whole file into memory. The scan goes line by
                // line: an odd number of quotes on a line flips the "inside quotes" state (counted in C by
                // substr_count), and a new line outside quotes ends the record.
                $length = strlen($chunk);
                for ($offset = 0; $offset < $length; $offset = $end) {
                    $newline = strpos($chunk, "\n", $offset);
                    $end = $newline === false ? $length : $newline + 1;

                    if (substr_count($chunk, '"', $offset, $end - $offset) % 2 === 1) {
                        $quoted = ! $quoted;
                    }

                    $record += $end - $offset;
                    if ($record > self::MAX_RECORD_BYTES) {
                        throw new UnexpectedValueException(sprintf(
                            "Csv::%s(): file '%s' has a record longer than %d MB (probably an unclosed quote).",
                            $method,
                            $path,
                            self::MAX_RECORD_BYTES >> 20,
                        ), self::ERROR_CORRUPT);
                    }

                    if ($newline !== false && ! $quoted) {
                        $record = 0;
                    }
                }

                if ($first && (str_starts_with($chunk, "PK\x03\x04") || str_starts_with($chunk, "\xD0\xCF\x11\xE0") || str_starts_with($chunk, '%PDF'))) {
                    throw new UnexpectedValueException("Csv::{$method}(): file '{$path}' is not a CSV text file (it looks like an XLSX, XLS or PDF file).", self::ERROR_NOT_TEXT);
                }
                $first = false;

                if (str_contains($chunk, "\0")) {
                    throw new UnexpectedValueException("Csv::{$method}(): file '{$path}' is not a CSV text file (it contains binary data).", self::ERROR_NOT_TEXT);
                }

                if ($utf8) {
                    // O secventa UTF-8 taiata la capatul bucatii se verifica impreuna cu bucata urmatoare.
                    $data = $carry.$chunk;
                    $cut = self::incompleteTail($data);
                    $carry = substr($data, strlen($data) - $cut);
                    $utf8 = mb_check_encoding(substr($data, 0, strlen($data) - $cut), 'UTF-8');
                }
            }
        } finally {
            fclose($handle);
        }

        return $utf8 && $carry === '';
    }

    /**
     * Number of trailing bytes forming an incomplete UTF-8 sequence (0 - 3).
     */
    private static function incompleteTail(string $data): int
    {
        $length = strlen($data);

        for ($i = 1; $i <= min(3, $length); $i++) {
            $byte = ord($data[$length - $i]);
            if (($byte & 0xC0) !== 0x80) {
                $needed = match (true) {
                    $byte >= 0xF0 => 4,
                    $byte >= 0xE0 => 3,
                    $byte >= 0xC0 => 2,
                    default => 1,
                };

                return $needed > $i ? $i : 0;
            }
        }

        return 0;
    }

    /**
     * Most frequent unquoted delimiter in the header line; defaults to ',' if none found.
     */
    private static function detectDelimiter(string $line): string
    {
        $counts = array_fill_keys(self::DELIMITERS, 0);
        $quoted = false;

        foreach (str_split($line) as $char) {
            if ($char === '"') {
                $quoted = ! $quoted;
            } elseif (! $quoted && isset($counts[$char])) {
                $counts[$char]++;
            }
        }

        $best = max($counts);

        return $best === 0 ? ',' : (string) array_search($best, $counts, true);
    }

    /**
     * Validated header: no empty names or duplicates; trailing empty columns are stripped.
     *
     * @param  list<string>  $fields
     * @return non-empty-list<string>
     *
     * @throws UnexpectedValueException If the header is invalid.
     */
    private static function header(string $method, array $fields): array
    {
        $header = array_map(trim(...), $fields);
        while ($header !== [] && end($header) === '') {
            array_pop($header);
        }

        if ($header === []) {
            throw new UnexpectedValueException("Csv::{$method}(): the header row is empty.", self::ERROR_CORRUPT);
        }

        $seen = [];
        foreach ($header as $index => $name) {
            if ($name === '') {
                throw new UnexpectedValueException("Csv::{$method}(): header column ".($index + 1).' has no name.', self::ERROR_CORRUPT);
            }
            $lower = mb_strtolower($name);
            if (isset($seen[$lower])) {
                throw new UnexpectedValueException("Csv::{$method}(): duplicate header column '{$name}'.", self::ERROR_CORRUPT);
            }
            $seen[$lower] = true;
        }

        return $header;
    }

    /**
     * Maps file header columns to schema columns (case-insensitively).
     *
     * @param  list<string>  $header
     * @param  list<string>  $expected
     * @param  Closure(int|null, string|null, string, string): void  $report
     * @return array{columns: array<string, int>, width: int}|false
     */
    private static function mapHeader(array $header, array $expected, bool $allowExtra, Closure $report): array|false
    {
        $positions = [];
        foreach ($header as $index => $name) {
            $positions[mb_strtolower($name)] = $index;
        }

        $columns = [];
        $missing = [];
        foreach ($expected as $column) {
            $lower = mb_strtolower($column);
            if (isset($positions[$lower])) {
                $columns[$column] = $positions[$lower];
                unset($positions[$lower]);
            } else {
                $missing[] = $column;
            }
        }

        $valid = true;
        if ($missing !== []) {
            $report(1, null, 'missing_columns', "Missing columns: '".implode("', '", $missing)."'.");
            $valid = false;
        }
        if ($positions !== [] && ! $allowExtra) {
            $extra = array_map(static fn (int $index): string => $header[$index], array_values($positions));
            $report(1, null, 'extra_columns', "Unexpected columns: '".implode("', '", $extra)."'.");
            $valid = false;
        }

        return $valid ? ['columns' => $columns, 'width' => count($header)] : false;
    }

    /**
     * Normalizes field count to $width: excess trailing empty fields are removed, missing fields are not padded.
     *
     * @param  list<string>  $fields
     * @return list<string>|null Null if field count does not match.
     */
    private static function fit(array $fields, int $width): ?array
    {
        while (count($fields) > $width && end($fields) === '') {
            array_pop($fields);
        }

        return count($fields) === $width ? $fields : null;
    }

    /**
     * @param  list<string>  $fields
     */
    private static function isEmpty(array $fields): bool
    {
        return implode('', array_map(trim(...), $fields)) === '';
    }

    /**
     * Formats value as CSV text; strings that could be interpreted as formulas are prefixed with '.
     *
     * @throws InvalidArgumentException If the type is not supported.
     */
    private function field(string $method, mixed $value, string $dateFormat, int $position, string $column): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            is_int($value) => (string) $value,
            is_float($value) => is_finite($value)
                ? rtrim(rtrim(sprintf('%.15F', $value), '0'), '.')
                : throw new InvalidArgumentException("Csv::{$method}(): row #{$position}, column '{$column}': number must be finite."),
            is_string($value) => preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value,
            $value instanceof DateTimeInterface => $value->format($dateFormat),
            default => throw new InvalidArgumentException("Csv::{$method}(): row #{$position}, column '{$column}': unsupported type ".get_debug_type($value).'.'),
        };
    }

    /**
     * @param  resource  $handle
     * @param  list<string>  $fields
     *
     * @throws RuntimeException If writing fails.
     */
    private function putCsv(string $method, mixed $handle, array $fields, string $delimiter): void
    {
        // Ca fputcsv() fara caracter de escape, dar cu CRLF si pe PHP < 8.1 (al 6-lea parametru lipseste):
        // valoarea intre ghilimele daca contine separatorul, ghilimele, spatii sau linii noi; " devine "".
        $line = [];
        foreach ($fields as $field) {
            $line[] = strpbrk($field, $delimiter."\"\n\r\t ") === false ? $field : '"'.str_replace('"', '""', $field).'"';
        }

        self::put($method, $handle, implode($delimiter, $line)."\r\n");
    }

    /**
     * @param  resource  $handle
     *
     * @throws RuntimeException If writing fails.
     */
    private static function put(string $method, mixed $handle, string $data): void
    {
        [$written, $warning] = self::capture(static fn (): int|false => fwrite($handle, $data));

        if ($written !== strlen($data)) {
            throw new RuntimeException("Csv::{$method}(): write failed: ".($warning ?? 'unknown error').'.');
        }
    }

    /**
     * @return resource
     *
     * @throws RuntimeException If the file cannot be opened.
     */
    private static function open(string $method, string $uri, string $path): mixed
    {
        [$handle, $warning] = self::capture(static fn (): mixed => fopen($uri, 'rb'));

        // Un filtru de conversie care nu poate fi creat produce doar un warning, iar fisierul s-ar citi neconvertit.
        if (is_resource($handle) && $warning !== null) {
            fclose($handle);
            $handle = false;
        }

        if (! is_resource($handle)) {
            throw new RuntimeException("Csv::{$method}(): cannot open '{$path}': ".($warning ?? 'unknown error').'.', self::ERROR_UNREADABLE);
        }

        return $handle;
    }

    /**
     * Validated schema with default values populated.
     *
     * @param  array<string, array<string, mixed>>  $schema
     * @return array<string, array<string, mixed>>
     *
     * @throws InvalidArgumentException If the schema is invalid.
     */
    private static function schema(string $method, array $schema): array
    {
        if ($schema === []) {
            throw new InvalidArgumentException("Csv::{$method}(): schema must not be empty.");
        }

        foreach ($schema as $column => $rule) {
            if ($column === '') {
                throw new InvalidArgumentException("Csv::{$method}(): schema column names must not be empty.");
            }

            $unknown = array_diff(array_keys($rule), self::RULES);
            if ($unknown !== []) {
                throw new InvalidArgumentException("Csv::{$method}(): unknown rule '".implode("', '", $unknown)."' for column '{$column}'.");
            }

            $type = $rule['type'] ?? 'string';
            if (! in_array($type, self::TYPES, true)) {
                throw new InvalidArgumentException("Csv::{$method}(): invalid type for column '{$column}' (allowed: ".implode(', ', self::TYPES).').');
            }
            $rule['type'] = $type;

            if ($type === 'date') {
                $formats = $rule['format'] ?? ['d.m.Y', 'Y-m-d'];
                $formats = is_string($formats) ? [$formats] : $formats;
                if (! is_array($formats) || $formats === [] || array_filter($formats, static fn (mixed $format): bool => ! is_string($format) || $format === '') !== []) {
                    throw new InvalidArgumentException("Csv::{$method}(): 'format' for column '{$column}' must be a date format or a list of formats.");
                }
                $rule['format'] = array_values($formats);
            }

            if ($type === 'decimal') {
                $rule['scale'] ??= 2;
                $rule['decimal'] ??= ',';
                if (! is_int($rule['scale']) || $rule['scale'] < 0 || ! in_array($rule['decimal'], [',', '.'], true)) {
                    throw new InvalidArgumentException("Csv::{$method}(): 'scale' must be an int >= 0 and 'decimal' ',' or '.' for column '{$column}'.");
                }
            }

            if (isset($rule['max']) && (! is_int($rule['max']) || $rule['max'] < 1)) {
                throw new InvalidArgumentException("Csv::{$method}(): 'max' for column '{$column}' must be an int >= 1.");
            }

            if (isset($rule['pattern'])) {
                $pattern = $rule['pattern'];
                if (! is_string($pattern) || self::capture(static fn (): int|false => preg_match($pattern, ''))[0] === false) {
                    throw new InvalidArgumentException("Csv::{$method}(): 'pattern' for column '{$column}' is not a valid regular expression.");
                }
            }

            if (isset($rule['in']) && (! is_array($rule['in']) || $rule['in'] === [])) {
                throw new InvalidArgumentException("Csv::{$method}(): 'in' for column '{$column}' must be a non-empty list.");
            }

            $schema[$column] = $rule;
        }

        return $schema;
    }

    /**
     * Validates and normalizes a value according to column rule.
     *
     * @param  array<string, mixed>  $rule
     * @return array{0: string|null, 1: mixed} [error code or null, normalized value]
     */
    private static function normalize(array $rule, mixed $value): array
    {
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                $value = null;
            }
        }

        if ($value === null) {
            return [($rule['required'] ?? false) === true ? 'required' : null, null];
        }

        $normalized = match ($rule['type']) {
            'int' => self::toInt($value),
            'decimal' => self::toDecimal($value, $rule),
            'date' => self::toDate($value, $rule),
            'bool' => self::toBool($value),
            default => self::toText($value),
        };

        if (is_array($normalized)) {
            return [$normalized[0], null];
        }

        if (isset($rule['max']) && is_string($normalized) && mb_strlen($normalized) > $rule['max']) {
            return ['max', null];
        }

        if (isset($rule['pattern']) && is_string($rule['pattern']) && preg_match($rule['pattern'], self::scalarKey($normalized)) !== 1) {
            return ['pattern', null];
        }

        if (isset($rule['in']) && is_array($rule['in']) && ! in_array(self::scalarKey($normalized), array_map(self::scalarKey(...), $rule['in']), true)) {
            return ['in', null];
        }

        return [null, $normalized];
    }

    /**
     * @return string|array{0: string}
     */
    private static function toText(mixed $value): string|array
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value) => (string) $value,
            is_float($value) => rtrim(rtrim(sprintf('%.15F', $value), '0'), '.'),
            default => ['type'],
        };
    }

    /**
     * @return int|array{0: string}
     */
    private static function toInt(mixed $value): int|array
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < 2 ** 53) {
            return (int) $value;
        }

        if (is_string($value) && preg_match('/^-?\d{1,18}$/D', $value) === 1) {
            return (int) $value;
        }

        return ['type'];
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return string|array{0: string}
     */
    private static function toDecimal(mixed $value, array $rule): string|array
    {
        $scale = is_int($rule['scale']) ? $rule['scale'] : 2;

        if (is_int($value) || is_float($value)) {
            if (! is_finite((float) $value)) {
                return ['type'];
            }
            // Un numar binar (ex: 0.1 + 0.2) are erori minuscule; peste ele, zecimalele in plus sunt eroare.
            if (abs(round((float) $value, $scale) - $value) > 1e-9) {
                return ['scale'];
            }

            return sprintf('%.'.$scale.'F', round((float) $value, $scale));
        }

        if (! is_string($value)) {
            return ['type'];
        }

        $decimal = $rule['decimal'] === '.' ? '.' : ',';
        $thousands = $decimal === ',' ? '.' : ',';
        // the first group of a grouped number must contain a non-zero digit: '0.500' is not 500
        $pattern = '/^(-?)((?=\d{0,2}[1-9])\d{1,3}(?:'.preg_quote($thousands, '/').'\d{3})+|\d+)(?:'.preg_quote($decimal, '/').'(\d+))?$/D';

        if (preg_match($pattern, $value, $match) !== 1) {
            return ['type'];
        }

        $fraction = $match[3] ?? '';
        if (strlen($fraction) > $scale) {
            return ['scale'];
        }

        $integer = ltrim(str_replace($thousands, '', $match[2]), '0');
        $result = ($integer === '' ? '0' : $integer).($scale > 0 ? '.'.str_pad($fraction, $scale, '0') : '');

        return trim($result, '0.') === '' ? $result : $match[1].$result;
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return string|array{0: string}
     */
    private static function toDate(mixed $value, array $rule): string|array
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (! is_string($value)) {
            return ['type'];
        }

        foreach (is_array($rule['format']) ? $rule['format'] : [] as $format) {
            if (! is_string($format)) {
                continue;
            }
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || $errors['warning_count'] + $errors['error_count'] === 0) && (int) $date->format('Y') >= 1000) {
                return $date->format('Y-m-d');
            }
        }

        return ['format'];
    }

    /**
     * @return bool|array{0: string}
     */
    private static function toBool(mixed $value): bool|array
    {
        if (is_bool($value)) {
            return $value;
        }

        $key = is_int($value) ? (string) $value : (is_string($value) ? mb_strtolower($value) : null);

        return $key !== null && isset(self::BOOLEANS[$key]) ? self::BOOLEANS[$key] : ['type'];
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private static function ruleMessage(string $code, array $rule): string
    {
        $type = is_string($rule['type']) ? $rule['type'] : 'string';
        $formats = is_array($rule['format'] ?? null) ? implode("' or '", array_filter($rule['format'], is_string(...))) : '';
        $scale = is_int($rule['scale'] ?? null) ? $rule['scale'] : 2;
        $max = is_int($rule['max'] ?? null) ? $rule['max'] : 0;

        return match ($code) {
            'required' => 'A value is required.',
            'type' => match ($type) {
                'int' => 'Expected a whole number.',
                'decimal' => "Expected a number with '".(($rule['decimal'] ?? ',') === '.' ? '.' : ',')."' as decimal separator.",
                'bool' => 'Expected yes/no (1/0, da/nu, true/false).',
                default => "Expected a value of type {$type}.",
            },
            'format' => "Expected a date in the format '{$formats}'.",
            'scale' => "More than {$scale} decimals.",
            'max' => "Longer than {$max} characters.",
            'pattern' => 'The value does not have the expected format.',
            'in' => 'The value is not one of the allowed values.',
            default => 'Invalid value.',
        };
    }

    /**
     * Extracts file exception message without the Class::method() prefix.
     */
    private static function fileMessage(Throwable $e): string
    {
        return ucfirst((string) preg_replace('/^Csv::\w+\(\): /', '', $e->getMessage()));
    }

    private static function scalarKey(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value), is_string($value) => (string) $value,
            default => '',
        };
    }

    /**
     * tempnam() creates 0600 files: before the rename, give the export the permissions of the file it replaces,
     * or the umask default, so the web server and other processes can still read it. No effect on Windows.
     */
    private function applyMode(string $temp, string $target): void
    {
        $mode = is_file($target) ? (int) fileperms($target) & 0777 : 0666 & ~umask();
        self::capture(static fn (): bool => chmod($temp, $mode));
    }

    /**
     * Executes a native function and captures any emitted warning instead of displaying it.
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return array{0: T, 1: string|null} [result, warning message or null]
     */
    private static function capture(Closure $operation): array
    {
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            return [$operation(), $warning];
        } finally {
            restore_error_handler();
        }
    }
}
