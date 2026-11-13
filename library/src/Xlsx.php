<?php

declare(strict_types=1);

namespace Foundry;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Generator;
use InvalidArgumentException;
use LogicException;
use OverflowException;
use RuntimeException;
use Throwable;
use UnexpectedValueException;
use XMLReader;
use XMLWriter;
use ZipArchive;

/**
 * Xlsx Class
 *
 * Reading, writing, and importing Excel .xlsx files without external libraries
 * (a .xlsx file is a ZIP archive containing XML files; requires zip, xmlreader, and xmlwriter extensions).
 * Designed for large volumes: completely streaming-based, inside the consumer's loop, with constant memory usage.
 *
 * Reading (read / sheets): sheets are traversed as streams; shared strings for large workbooks
 * are stored on disk. The first row is the header (no empty names or duplicates). Values retain their
 * Excel types: int, float, string, bool, DateTimeImmutable (cells formatted as dates), null (empty cell).
 * Error cells (#N/A, #DIV/0!...) are read as text. Formulas: reads the pre-calculated cached value.
 * Legacy .xls format is not supported (it is binary); save as .xlsx first.
 *
 * Writing: writer() receives rows one by one in the consumer's loop; using a .zip path,
 * the export is automatically split into multiple .xlsx files (Excel limit: 1,048,576 rows per sheet).
 * writeSheets() / download() write multiple sheets at once. Bold and frozen header, auto-filter,
 * dates as Excel date cells (dd.mm.yyyy); final file is written atomically; Excel limits are checked.
 * Text is never interpreted as a formula. downloadFile() streams a pre-written export.
 *
 * Importing (import): one or more files validated against a schema, delivered in batches with a resume
 * cursor; schema, normalization, batches, and reporting are identical to Csv::import(). In addition:
 * error codes 'not_xlsx' (file is not .xlsx), 'sheet' (requested sheet is missing), and 'cell_error'
 * (Excel cell error).
 *
 * Fast resume (stage): a .xlsx file is compressed and cannot be read from an arbitrary byte offset,
 * so without staging every resume re-parses the sheet up to the cursor. stage() converts the sheet once
 * into a working file (one row per line with types preserved); with the 'staged' option, import reads
 * from it and jumps directly to the cursor, just like CSV. The working file records source size, mtime,
 * and sheet name: if the source has changed, import rejects it.
 *
 * Usage:
 *   require_once 'Xlsx.php';
 *   use Foundry\Xlsx;
 *
 *   foreach (Xlsx::read('customers.xlsx') as $row => $values) { ... }        // first sheet
 *   foreach (Xlsx::read('customers.xlsx', 'Legal Entities') as $values) { ... }
 *
 *   $out = Xlsx::writer('/tmp/export.zip');                // .zip: multiple .xlsx files exceeding Excel row limit
 *   foreach ($db->cursor('SELECT ...') as $row) {
 *       $out->add($row);
 *   }
 *   $out->close();
 *   Xlsx::downloadFile('/tmp/export.zip', 'policies.zip');
 *
 *   Xlsx::writeSheets('report.xlsx', ['Policies' => $policies, 'Claims' => $claims]);
 *
 *   foreach (Xlsx::import($files, $schema, batchSize: 5000, cursor: $cursor) as $batch) {
 *       $db->insertMany('customers', array_column($batch['rows'], 'data'));
 *       $cursor = $batch['cursor'];
 *   }
 *
 *   // In web requests: first request stages the files, subsequent requests import in chunks.
 *   $total = Xlsx::stage($path, $jobDir.'/january.stage');        // number of rows (progress)
 *   foreach (Xlsx::import(['january.xlsx' => $path], $schema, ['staged' => ['january.xlsx' => $jobDir.'/january.stage'],
 *       'uniqueFile' => $jobDir.'/unique.bin'], 5000, $cursor) as $batch) { ... }
 *
 * @category Utilities
 *
 * @version  1.6
 *
 * @since    PHP 8.3
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
final class Xlsx
{
    public const int MAX_ROWS = 1_048_576;

    public const int MAX_COLUMNS = 16_384;

    public const int MAX_CELL_LENGTH = 32_767;

    /** @var int File exception codes used by import() for reporting. */
    private const int ERROR_NOT_XLSX = 1;

    private const int ERROR_CORRUPT = 2;

    private const int ERROR_SHEET = 3;

    private const int ERROR_UNREADABLE = 4;

    /** @var int Import cursor structure version. */
    private const int CURSOR_VERSION = 1;

    /** @var int Default maximum tracked unique values (~200 MB memory). */
    private const int MAX_UNIQUE = 5_000_000;

    private const string NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const string NS_RELATIONSHIPS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** @var int Maximum uncompressed size of XML files loaded entirely into memory (workbook, styles). */
    private const int MAX_XML_BYTES = 32 * 1024 * 1024;

    /** @var int Maximum number of relevant elements kept from such a file (Excel allows 64,000 cell formats). */
    private const int MAX_ELEMENTS = 300_000;

    /** @var int Above this number of shared strings (or MEMORY_STRINGS_BYTES), they are moved to a temporary file. */
    private const int MEMORY_STRINGS = 100_000;

    private const int MEMORY_STRINGS_BYTES = 8 * 1024 * 1024;

    /** @var int Shared strings read from disk kept in memory cache (strings frequently repeat across rows). */
    private const int STRING_CACHE = 10_000;

    /** @var list<int> Built-in number format IDs that represent dates or times. */
    private const array DATE_FORMAT_IDS = [14, 15, 16, 17, 18, 19, 20, 21, 22, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 45, 46, 47, 50, 51, 52, 53, 54, 55, 56, 57, 58];

    /** @var int Written styles: 0 normal, 1 header, 2 date, 3 datetime. */
    private const int STYLE_HEADER = 1;

    private const int STYLE_DATE = 2;

    private const int STYLE_DATETIME = 3;

    /** @var list<string> Accepted rules in the import schema. */
    private const array RULES = ['required', 'type', 'format', 'scale', 'decimal', 'max', 'pattern', 'in', 'unique'];

    /** @var list<string> */
    private const array TYPES = ['string', 'int', 'decimal', 'date', 'bool'];

    /** @var array<int|string, bool> Accepted string values for 'bool'. */
    private const array BOOLEANS = ['1' => true, '0' => false, 'true' => true, 'false' => false, 'da' => true, 'nu' => false, 'yes' => true, 'no' => false];

    /** @var XMLWriter|null Sheet currently being written. */
    private ?XMLWriter $xml = null;

    /** @var string|null Temporary file holding the XML of the sheet in progress. */
    private ?string $part = null;

    /** @var int Last row number written in the current sheet (header is row 1). */
    private int $rowNumber = 0;

    private int $written = 0;

    /** @var list<string> Completed temporary .xlsx files (next to destination). */
    private array $files = [];

    /** @var string|null Temporary .zip archive under construction. */
    private ?string $archive = null;

    private bool $closed = false;

    /**
     * @param  list<string>|null  $header
     */
    private function __construct(private readonly string $path, private readonly bool $zip, private readonly string $sheet, private readonly int $maxRows, private ?array $header) {}

    /**
     * An abandoned export (without close(), e.g. after an exception) does not appear at destination.
     */
    public function __destruct()
    {
        if (! $this->closed) {
            $this->abort();
        }
    }

    /**
     * Sheet names in file order.
     *
     * @return list<string>
     *
     * @throws RuntimeException If the file cannot be read.
     * @throws UnexpectedValueException If the file is not a valid .xlsx file.
     */
    public static function sheets(string $path): array
    {
        $book = self::book('sheets', $path);
        $book['zip']->close();

        return array_column($book['sheets'], 'name');
    }

    /**
     * Rows of a sheet as associative arrays, with header from the first row.
     * The generator key is the Excel row number.
     *
     * @param  string|int|null  $sheet  Sheet name, 0-based index, or null for the first sheet.
     * @return Generator<int, array<string, mixed>>
     *
     * @throws RuntimeException If the file cannot be read.
     * @throws UnexpectedValueException If the file is not valid .xlsx, sheet is missing, header is invalid,
     *                                  or a row has values outside the header columns.
     */
    public static function read(string $path, string|int|null $sheet = null): Generator
    {
        $header = null;

        foreach (self::records('read', $path, $sheet) as $row => [$values]) {
            if ($header === null) {
                $header = self::header('read', $values);

                continue;
            }

            $values = self::fit($values, count($header))
                ?? throw new UnexpectedValueException("Xlsx::read(): row {$row} has values outside the ".count($header).' header columns.');

            yield $row => array_combine($header, $values);
        }

        if ($header === null) {
            throw new UnexpectedValueException("Xlsx::read(): the sheet in '{$path}' is empty (no header row).");
        }
    }

    /**
     * Writes rows to a single sheet.
     *
     * @param  iterable<array<array-key, mixed>>  $rows  Associative rows (header = keys of first row) or lists (with $header).
     * @param  list<string>|null  $header
     *
     * @throws InvalidArgumentException If data exceeds Excel limits or contains unsupported types.
     * @throws RuntimeException If the file cannot be written.
     */
    public static function write(string $path, iterable $rows, ?array $header = null, string $sheet = 'Sheet1'): void
    {
        self::build('write', $path, [['name' => $sheet, 'rows' => $rows, 'header' => $header]]);
    }

    /**
     * Writes multiple sheets: ['Sheet name' => associative rows].
     *
     * @param  array<string, iterable<array<string, mixed>>>  $sheets
     *
     * @throws InvalidArgumentException If sheets are empty, names are invalid, or data exceeds Excel limits.
     * @throws RuntimeException If the file cannot be written.
     */
    public static function writeSheets(string $path, array $sheets): void
    {
        self::build('writeSheets', $path, self::sheetList('writeSheets', $sheets));
    }

    /**
     * Streams a .xlsx with one or more sheets to the browser: ['Sheet name' => associative rows].
     *
     * @param  array<string, iterable<array<string, mixed>>>  $sheets
     *
     * @throws LogicException If HTTP headers have already been sent.
     * @throws InvalidArgumentException If sheets or data are invalid.
     * @throws RuntimeException If the temporary file cannot be written.
     */
    public static function download(string $filename, array $sheets): void
    {
        if (headers_sent($file, $line)) {
            throw new LogicException("Xlsx::download(): headers already sent (output started at {$file}:{$line}).");
        }

        $temp = self::tempFile('download', sys_get_temp_dir());

        try {
            self::build('download', $temp, self::sheetList('download', $sheets));

            $ascii = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="'.$ascii.'"; filename*=UTF-8\'\''.rawurlencode($filename));
            header('Content-Length: '.filesize($temp));
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');

            if (self::capture(static fn (): int|false => readfile($temp))[0] === false) {
                throw new RuntimeException('Xlsx::download(): cannot send the generated file.');
            }
        } finally {
            self::capture(static fn (): bool => unlink($temp));
        }
    }

    /**
     * A row-by-row streaming export: add() in a loop, followed by close().
     *
     * With a .xlsx path, produces a single file (exceeding $maxRowsPerFile rows throws OverflowException).
     * With a .zip path, the export is chunked into files of $maxRowsPerFile rows each
     * (name_1.xlsx, name_2.xlsx...; a single file is named name.xlsx), each containing a header.
     * The destination file appears only at close(), atomically.
     *
     * @param  list<string>|null  $header  null = keys of the first row.
     *
     * @throws InvalidArgumentException If extension, sheet name, or limits are invalid.
     * @throws RuntimeException If directory does not exist.
     */
    public static function writer(string $path, ?array $header = null, string $sheet = 'Sheet1', int $maxRowsPerFile = self::MAX_ROWS - 1): self
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension !== 'xlsx' && $extension !== 'zip') {
            throw new InvalidArgumentException('Xlsx::writer(): the path must end with .xlsx (one file) or .zip (several .xlsx files when the export exceeds the row limit).');
        }

        self::checkSheetName('writer', $sheet);

        if ($maxRowsPerFile < 1 || $maxRowsPerFile > self::MAX_ROWS - 1) {
            throw new InvalidArgumentException('Xlsx::writer(): maxRowsPerFile must be between 1 and '.(self::MAX_ROWS - 1).", got {$maxRowsPerFile}.");
        }

        $directory = dirname($path);
        if (! is_dir($directory)) {
            throw new RuntimeException("Xlsx::writer(): directory '{$directory}' does not exist.");
        }

        return new self($path, $extension === 'zip', $sheet, $maxRowsPerFile, $header);
    }

    /**
     * Adds a row: associative (keys = header, in same order) or list (in header order).
     *
     * @param  array<array-key, mixed>  $row
     *
     * @throws LogicException If the export is already closed.
     * @throws OverflowException If a .xlsx path exceeds the row limit.
     * @throws InvalidArgumentException If row does not match the header or contains invalid values.
     * @throws RuntimeException If temporary files cannot be written.
     */
    public function add(array $row): void
    {
        if ($this->closed) {
            throw new LogicException('Xlsx::add(): the export is already closed.');
        }

        $header = $this->header ??= array_map(strval(...), array_keys($row));
        $xml = $this->xml ?? $this->startPart();

        if ($this->rowNumber - 1 >= $this->maxRows) {
            if (! $this->zip) {
                throw new OverflowException("Xlsx::add(): more than {$this->maxRows} rows; use a .zip path to split the export into several .xlsx files.");
            }
            $this->finishPart();
            $xml = $this->startPart();
        }

        self::addRow('add', $xml, $this->sheet, ++$this->rowNumber, $row, $header, $this->written);
        $this->written++;
    }

    /**
     * Finalizes the export: final sheet, packaging (.zip if needed), and moving to destination.
     * Subsequent calls have no effect.
     *
     * @throws RuntimeException If file cannot be written.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        try {
            if (! $this->xml instanceof XMLWriter) {
                $this->startPart();
            }
            $this->finishPart();

            $result = $this->zip ? $this->zipParts() : $this->files[0];
            $target = $this->path;
            self::applyMode($result, $target);
            [$renamed, $warning] = self::capture(static fn (): bool => rename($result, $target));
            if (! $renamed) {
                throw new RuntimeException("Xlsx::close(): cannot write '{$target}': ".($warning ?? 'unknown error').'.');
            }
        } finally {
            $this->abort();
        }
    }

    /**
     * Number of added rows (excluding headers).
     */
    public function count(): int
    {
        return $this->written;
    }

    /**
     * Streams a pre-generated file from disk (.xlsx or .zip) to the browser without loading it into memory.
     *
     * @throws RuntimeException If file does not exist or cannot be streamed.
     * @throws LogicException If HTTP headers have already been sent.
     */
    public static function downloadFile(string $path, ?string $filename = null): void
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Xlsx::downloadFile(): file '{$path}' does not exist or is not readable.");
        }

        if (headers_sent($file, $line)) {
            throw new LogicException("Xlsx::downloadFile(): headers already sent (output started at {$file}:{$line}).");
        }

        $filename ??= basename($path);
        $ascii = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename);
        $type = str_ends_with(strtolower($filename), '.zip') ? 'application/zip' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        header('Content-Type: '.$type);
        header('Content-Disposition: attachment; filename="'.$ascii.'"; filename*=UTF-8\'\''.rawurlencode($filename));
        header('Content-Length: '.filesize($path));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');

        if (self::capture(static fn (): int|false => readfile($path))[0] === false) {
            throw new RuntimeException("Xlsx::downloadFile(): cannot send '{$path}'.");
        }
    }

    /**
     * Starts a new sheet (first part or subsequent part of a .zip export).
     *
     * @throws RuntimeException If the temporary file cannot be created.
     */
    private function startPart(): XMLWriter
    {
        $this->part = self::tempFile('add', sys_get_temp_dir());
        $xml = self::openSheet('add', $this->part);
        $header = $this->header ?? [];
        self::startSheetData('add', $xml, $this->sheet, $header);
        $this->rowNumber = $header === [] ? 0 : 1;

        return $this->xml = $xml;
    }

    /**
     * Closes the current sheet and packages it as a temporary .xlsx next to destination.
     *
     * @throws RuntimeException If files cannot be written.
     */
    private function finishPart(): void
    {
        if (! $this->xml instanceof XMLWriter || $this->part === null) {
            return;
        }

        self::finishSheet('close', $this->xml, $this->part, $this->header, $this->rowNumber);
        $this->xml = null;

        $archive = self::tempFile('close', dirname($this->path));
        $this->files[] = $archive;
        self::assemble('close', $archive, [['name' => $this->sheet, 'part' => $this->part]]);

        $part = $this->part;
        $this->part = null;
        self::capture(static fn (): bool => unlink($part));
    }

    /**
     * Builds .zip archive with export parts (without recompression: .xlsx is already compressed).
     *
     * @throws RuntimeException If archive cannot be written.
     */
    private function zipParts(): string
    {
        $this->archive = self::tempFile('close', dirname($this->path));
        $zip = new ZipArchive;
        if (self::capture(fn (): bool|int => $zip->open($this->archive, ZipArchive::CREATE | ZipArchive::OVERWRITE))[0] !== true) {
            throw new RuntimeException("Xlsx::close(): cannot create '{$this->archive}'.");
        }

        $base = pathinfo($this->path, PATHINFO_FILENAME);
        $count = count($this->files);
        foreach ($this->files as $index => $file) {
            $name = $count === 1 ? $base.'.xlsx' : $base.'_'.($index + 1).'.xlsx';
            $zip->addFile($file, $name);
            $zip->setCompressionName($name, ZipArchive::CM_STORE);
        }

        [$closed, $warning] = self::capture(static fn (): bool => $zip->close());
        if (! $closed) {
            throw new RuntimeException("Xlsx::close(): cannot write '{$this->archive}': ".($warning ?? 'zip error').'.');
        }

        return $this->archive;
    }

    /**
     * Aborts the export: closes sheet and removes all temporary files (destination remains untouched).
     */
    private function abort(): void
    {
        $this->closed = true;
        $this->xml = null;

        foreach ([$this->part, $this->archive, ...$this->files] as $file) {
            if ($file !== null && is_file($file)) {
                self::capture(static fn (): bool => unlink($file));
            }
        }

        $this->part = null;
        $this->archive = null;
        $this->files = [];
    }

    /**
     * Prepares a sheet for chunked import across multiple requests: converts it once into a working
     * file (one row per line, types preserved) from which import() with the 'staged' option resumes
     * directly at the cursor. Returns data row count (excluding header), useful for progress tracking.
     * The working file appears only when fully written. Duration: roughly the time of one sheet read.
     *
     * If stage() throws an exception (e.g. file is not .xlsx), importing without 'staged' reports
     * the issue normally (not_xlsx, corrupt), as for any other file.
     *
     * @throws RuntimeException If file cannot be read or working file cannot be written.
     * @throws UnexpectedValueException If file is not valid .xlsx or sheet is missing.
     */
    public static function stage(string $path, string $workFile, string|int|null $sheet = null): int
    {
        $directory = dirname($workFile);
        if (! is_dir($directory)) {
            throw new RuntimeException("Xlsx::stage(): directory '{$directory}' does not exist.");
        }

        $meta = self::sourceMeta('stage', $path, $sheet);
        $temp = self::tempFile('stage', $directory);
        $rows = 0;

        try {
            [$handle, $warning] = self::capture(static fn (): mixed => fopen($temp, 'wb'));
            if (! is_resource($handle)) {
                throw new RuntimeException("Xlsx::stage(): cannot write '{$temp}': ".($warning ?? 'unknown error').'.');
            }

            try {
                self::writeStaged($handle, $temp, self::jsonLine($meta));
                foreach (self::records('stage', $path, $sheet) as $row => [$values, $cellErrors]) {
                    $encoded = array_map(static fn (mixed $value): mixed => $value instanceof DateTimeInterface ? ['@' => $value->format('Y-m-d H:i:s')] : $value, $values);
                    self::writeStaged($handle, $temp, self::jsonLine([$row, $encoded, array_keys($cellErrors)]));
                    $rows++;
                }
            } finally {
                fclose($handle);
            }

            [$renamed, $warning] = self::capture(static fn (): bool => rename($temp, $workFile));
            if (! $renamed) {
                throw new RuntimeException("Xlsx::stage(): cannot write '{$workFile}': ".($warning ?? 'unknown error').'.');
            }
        } catch (Throwable $e) {
            if (is_file($temp)) {
                self::capture(static fn (): bool => unlink($temp));
            }

            throw $e;
        }

        return max(0, $rows - 1);
    }

    /**
     * Imports one or more files validated and normalized according to schema, in batches
     * (same schema, batch format, and cursor as Csv::import()).
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
     * @param  array<array-key, string>  $files  File paths; with string keys, key is the display name.
     * @param  array<string, array<string, mixed>>  $schema
     * @param  array{sheet?: string|int|null, allowExtraColumns?: bool, maxErrors?: int, uniqueFile?: string|null, maxUnique?: int, staged?: array<string, string>}  $options
     *                                                                                                                                                                         staged: [file name => working file created with stage()]
     * @param  array<string, mixed>|null  $cursor  Cursor of the last processed batch, to resume.
     * @return Generator<int, array{rows: list<array{file: string, row: int, data: array<string, mixed>}>, errors: list<array{file: string, row: int|null, column: string|null, code: string, message: string}>, files: array<string, array{status: string, rows: int, valid: int, errors: int}>, cursor: array<string, mixed>, done: bool, valid: bool}>
     *
     * @throws InvalidArgumentException If schema, options, file list, or cursor are invalid.
     */
    public static function import(array $files, array $schema, array $options = [], int $batchSize = 1000, ?array $cursor = null): Generator
    {
        if ($files === []) {
            throw new InvalidArgumentException('Xlsx::import(): no files given.');
        }

        if ($batchSize < 1) {
            throw new InvalidArgumentException("Xlsx::import(): batchSize must be >= 1, got {$batchSize}.");
        }

        $schema = self::schema('import', $schema);

        $names = [];
        $paths = [];
        foreach ($files as $key => $path) {
            $name = is_string($key) ? $key : basename($path);
            if (in_array($name, $names, true)) {
                throw new InvalidArgumentException("Xlsx::import(): duplicate file name '{$name}'.");
            }
            $names[] = $name;
            $paths[] = $path;
        }

        $settings = [
            'sheet' => $options['sheet'] ?? null,
            'allowExtra' => $options['allowExtraColumns'] ?? false,
            'maxErrors' => $options['maxErrors'] ?? 100,
            'uniqueFile' => $options['uniqueFile'] ?? null,
            'maxUnique' => $options['maxUnique'] ?? self::MAX_UNIQUE,
            'staged' => $options['staged'] ?? [],
        ];

        foreach (array_keys($settings['staged']) as $name) {
            if (! in_array($name, $names, true)) {
                throw new InvalidArgumentException("Xlsx::import(): staged file '{$name}' is not in the list of files.");
            }
        }

        $uniqueColumns = array_keys(array_filter($schema, static fn (array $rule): bool => ($rule['unique'] ?? false) === true));
        if ($cursor !== null && $uniqueColumns !== [] && $settings['uniqueFile'] === null) {
            throw new InvalidArgumentException("Xlsx::import(): resuming an import with 'unique' columns requires the 'uniqueFile' option.");
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
     * @param  array{sheet: string|int|null, allowExtra: bool, maxErrors: int, uniqueFile: string|null, maxUnique: int, staged: array<string, string>}  $settings
     * @param  array{file: int, row: int, offset: int|null, files: array<string, array{status: string, rows: int, valid: int, errors: int}>}  $state
     * @return Generator<int, array{rows: list<array{file: string, row: int, data: array<string, mixed>}>, errors: list<array{file: string, row: int|null, column: string|null, code: string, message: string}>, files: array<string, array{status: string, rows: int, valid: int, errors: int}>, cursor: array<string, mixed>, done: bool, valid: bool}>
     */
    private static function batches(array $names, array $paths, array $schema, array $uniqueColumns, array $settings, int $batchSize, array $state): Generator
    {
        $seen = $uniqueColumns === [] ? [] : self::loadUnique($settings['uniqueFile'], $state['file'] > 0 || $state['row'] > 0, $settings['maxUnique']);
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
            $skip = $f === $state['file'] ? $state['row'] : 0;
            $offset = $f === $state['file'] ? $state['offset'] : null;
            $staged = $settings['staged'][$name] ?? null;
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
                $mapping = null;

                $source = $staged === null
                    ? self::records('import', $path, $settings['sheet'], $skip)
                    : self::stagedRecords('import', $staged, $path, $settings['sheet'], $skip, $offset);

                foreach ($source as $row => [$values, $cellErrors, $next]) {
                    if ($mapping === null) {
                        $mapping = self::mapHeader(self::header('import', $values), array_keys($schema), $settings['allowExtra'], $report);
                        if ($mapping === false) {
                            break;
                        }

                        continue;
                    }

                    $dataRows++;
                    [$data, $valid] = self::validateRow($row, $values, $cellErrors, $mapping, $schema, $report);

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
                                        throw new OverflowException("Xlsx::import(): more than {$settings['maxUnique']} unique values; raise 'maxUnique' or check uniqueness in the database.");
                                    }
                                    $seen[$hash] = ($f << 32) | $row;
                                    $pendingUnique .= pack('qJ', $hash, $seen[$hash]);
                                }
                            }
                        }
                        $validRows++;
                        $rows[] = ['file' => $name, 'row' => $row, 'data' => $data];
                    }

                    if (++$read >= $batchSize) {
                        $pendingUnique = self::flushUnique($settings['uniqueFile'], $pendingUnique);
                        $stats[$name] = ['status' => $status, 'rows' => $dataRows, 'valid' => $validRows, 'errors' => $errorCount];
                        $cursor = ['version' => self::CURSOR_VERSION, 'files' => $fingerprint, 'file' => $f, 'row' => $row, 'offset' => $next, 'stats' => $stats];

                        yield $batch++ => ['rows' => $rows, 'errors' => $errors, 'files' => $stats, 'cursor' => $cursor, 'done' => false, 'valid' => self::allValid($stats)];
                        $rows = [];
                        $errors = [];
                        $read = 0;
                    }
                }

                if ($mapping === null) {
                    $report(null, null, 'empty', 'The sheet is empty (no header row).');
                } elseif ($mapping !== false && $dataRows === 0) {
                    $report(null, null, 'empty', 'The sheet has no data rows.');
                }
            } catch (RuntimeException $e) {
                $code = match ($e->getCode()) {
                    self::ERROR_NOT_XLSX => 'not_xlsx',
                    self::ERROR_SHEET => 'sheet',
                    self::ERROR_CORRUPT => 'corrupt',
                    self::ERROR_UNREADABLE => 'unreadable',
                    default => throw $e,
                };
                $report(null, null, $code, self::fileMessage($e));
                $status = $code === 'sheet' ? 'invalid' : 'unreadable';
            }

            $stats[$name] = ['status' => $status, 'rows' => $dataRows, 'valid' => $validRows, 'errors' => $errorCount];
        }

        self::flushUnique($settings['uniqueFile'], $pendingUnique);
        $cursor = ['version' => self::CURSOR_VERSION, 'files' => $fingerprint, 'file' => count($names), 'row' => 0, 'offset' => null, 'stats' => $stats];

        yield $batch => ['rows' => $rows, 'errors' => $errors, 'files' => $stats, 'cursor' => $cursor, 'done' => true, 'valid' => self::allValid($stats)];
    }

    /**
     * Validates a row against the schema (errors are reported); invalid values are set to null.
     *
     * @param  list<mixed>  $values
     * @param  array<int, true>  $cellErrors
     * @param  array{columns: array<string, int>, width: int}  $mapping
     * @param  array<string, array<string, mixed>>  $schema
     * @param  Closure(int|null, string|null, string, string): void  $report
     * @return array{0: array<string, mixed>, 1: bool} [normalized values, whether the row is valid]
     */
    private static function validateRow(int $row, array $values, array $cellErrors, array $mapping, array $schema, Closure $report): array
    {
        $values = self::fit($values, $mapping['width']);
        if ($values === null) {
            $report($row, null, 'field_count', "The row has values outside the {$mapping['width']} header columns.");

            return [[], false];
        }

        $data = [];
        $valid = true;
        foreach ($schema as $column => $rule) {
            $index = $mapping['columns'][$column];
            if (isset($cellErrors[$index])) {
                $report($row, $column, 'cell_error', 'The cell contains an Excel error ('.self::scalarKey($values[$index]).').');
                $valid = false;
                $data[$column] = null;

                continue;
            }

            [$code, $value] = self::normalize($rule, $values[$index]);
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
     * @return array{file: int, row: int, offset: int|null, files: array<string, array{status: string, rows: int, valid: int, errors: int}>}
     *
     * @throws InvalidArgumentException If cursor is invalid or belongs to another import.
     */
    private static function cursorState(string $method, ?array $cursor, array $names): array
    {
        if ($cursor === null) {
            return ['file' => 0, 'row' => 0, 'offset' => null, 'files' => []];
        }

        $valid = ($cursor['version'] ?? null) === self::CURSOR_VERSION
            && is_int($cursor['file'] ?? null) && $cursor['file'] >= 0 && $cursor['file'] <= count($names)
            && is_int($cursor['row'] ?? null) && $cursor['row'] >= 0
            && (($cursor['offset'] ?? null) === null || (is_int($cursor['offset']) && $cursor['offset'] >= 0))
            && is_array($cursor['stats'] ?? null);

        if (! $valid) {
            throw new InvalidArgumentException("Xlsx::{$method}(): invalid cursor.");
        }

        if (($cursor['files'] ?? null) !== self::fingerprint($names)) {
            throw new InvalidArgumentException("Xlsx::{$method}(): the cursor belongs to a different list of files.");
        }

        $files = [];
        foreach ($cursor['stats'] as $name => $stats) {
            if (! is_string($name) || ! is_array($stats) || ! is_string($stats['status'] ?? null) || ! is_int($stats['rows'] ?? null) || ! is_int($stats['valid'] ?? null) || ! is_int($stats['errors'] ?? null)) {
                throw new InvalidArgumentException("Xlsx::{$method}(): invalid cursor.");
            }
            $files[$name] = ['status' => $stats['status'], 'rows' => $stats['rows'], 'valid' => $stats['valid'], 'errors' => $stats['errors']];
        }

        return ['file' => $cursor['file'], 'row' => $cursor['row'], 'offset' => is_int($cursor['offset'] ?? null) ? $cursor['offset'] : null, 'files' => $files];
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
                throw new RuntimeException("Xlsx::import(): cannot write the unique values file '{$file}'.");
            }

            return [];
        }

        $data = self::capture(static fn (): string|false => file_get_contents($file))[0];
        if (! is_string($data) || strlen($data) % 16 !== 0) {
            throw new RuntimeException("Xlsx::import(): the unique values file '{$file}' is unreadable or damaged.");
        }

        $count = intdiv(strlen($data), 16);
        if ($count > $maxUnique) {
            throw new OverflowException("Xlsx::import(): more than {$maxUnique} unique values; raise 'maxUnique' or check uniqueness in the database.");
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
            throw new RuntimeException("Xlsx::import(): cannot write the unique values file '{$file}'.");
        }

        return '';
    }

    // ---------------------------------------------------------------- citire

    /**
     * Non-empty rows of the sheet: row number => [values by position, positions of error cells].
     * First row (header) is always returned; after that, rows with number <= $skip are skipped without decoding.
     *
     * @return Generator<int, array{0: list<mixed>, 1: array<int, true>, 2: null}>
     *
     * @throws RuntimeException If the file cannot be read.
     * @throws UnexpectedValueException If the file is not valid .xlsx or sheet is missing.
     */
    private static function records(string $method, string $path, string|int|null $sheet, int $skip = 0): Generator
    {
        $book = self::book($method, $path);
        $book['zip']->close();

        $entry = self::sheetEntry($method, $book['sheets'], $sheet, $path);
        [$string, $closeStrings] = $book['hasStrings'] ? self::sharedStrings($method, $path) : [static fn (int $index): ?string => null, static function (): void {}];

        $reader = new XMLReader;
        [$opened] = self::capture(static fn (): bool => $reader->open('zip://'.$path.'#'.$entry, null, LIBXML_NONET | LIBXML_COMPACT));
        if (! $opened) {
            $closeStrings();

            throw new UnexpectedValueException("Xlsx::{$method}(): file '{$path}' is damaged (cannot open '{$entry}').", self::ERROR_CORRUPT);
        }

        try {
            $previous = 0;
            $skipUntil = 0;
            $positioned = false;
            while (true) {
                // Erorile XML sunt captate doar cat se citeste un rand; intre randuri, controlul este al apelantului.
                [$row, $warning] = self::capture(static function () use ($reader, $book, $string, &$previous, $skipUntil, &$positioned): ?array {
                    return self::nextRow($reader, $book, $string, $previous, $skipUntil, $positioned);
                });

                if ($warning !== null) {
                    throw new UnexpectedValueException("Xlsx::{$method}(): file '{$path}' is damaged ('{$entry}' is not valid XML).", self::ERROR_CORRUPT);
                }

                if ($row === null) {
                    break;
                }

                [$number, $values, $cellErrors] = $row;
                if (array_filter($values, static fn (mixed $value): bool => $value !== null && $value !== '') !== []) {
                    yield $number => [$values, $cellErrors, null];
                    $skipUntil = $skip;
                }
            }
        } finally {
            $reader->close();
            $closeStrings();
        }
    }

    /**
     * Rows from a working file created with stage(), in the same format as records(), plus the position
     * of the next row. Header always comes first; then reading continues from $offset (direct jump)
     * or, without $offset, skips rows with number <= $skip.
     *
     * @return Generator<int, array{0: list<mixed>, 1: array<int, true>, 2: int}>
     *
     * @throws InvalidArgumentException If working file does not match source or sheet.
     * @throws RuntimeException If working file cannot be read.
     * @throws UnexpectedValueException If working file is damaged.
     */
    private static function stagedRecords(string $method, string $workFile, string $source, string|int|null $sheet, int $skip, ?int $offset): Generator
    {
        [$handle, $warning] = self::capture(static fn (): mixed => fopen($workFile, 'rb'));
        if (! is_resource($handle)) {
            throw new RuntimeException("Xlsx::{$method}(): cannot read the staged file '{$workFile}': ".($warning ?? 'unknown error').'.', self::ERROR_UNREADABLE);
        }

        try {
            $meta = self::stagedLine($method, $workFile, fgets($handle));
            if ($meta !== self::sourceMeta($method, $source, $sheet)) {
                throw new InvalidArgumentException("Xlsx::{$method}(): the staged file '{$workFile}' does not match '{$source}' (the source or the sheet changed); run stage() again.");
            }

            $header = true;
            while (($line = fgets($handle)) !== false) {
                $record = self::stagedLine($method, $workFile, $line);
                if (! is_int($record[0] ?? null) || ! is_array($record[1] ?? null) || ! is_array($record[2] ?? null)) {
                    throw new UnexpectedValueException("Xlsx::{$method}(): the staged file '{$workFile}' is damaged.", self::ERROR_CORRUPT);
                }

                $number = $record[0];
                if (! $header && $offset === null && $number <= $skip) {
                    continue;
                }

                $values = [];
                foreach (array_values($record[1]) as $value) {
                    if (is_array($value) && is_string($value['@'] ?? null)) {
                        $value = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value['@']);
                        if ($value === false) {
                            throw new UnexpectedValueException("Xlsx::{$method}(): the staged file '{$workFile}' is damaged.", self::ERROR_CORRUPT);
                        }
                    }

                    $values[] = $value;
                }

                $cellErrors = [];
                foreach ($record[2] as $index) {
                    if (is_int($index)) {
                        $cellErrors[$index] = true;
                    }
                }

                $position = ftell($handle);
                if ($position === false) {
                    throw new RuntimeException("Xlsx::{$method}(): cannot read the position in '{$workFile}'.", self::ERROR_UNREADABLE);
                }

                yield $number => [$values, $cellErrors, $position];

                if ($header) {
                    $header = false;
                    if ($offset !== null && fseek($handle, $offset) !== 0) {
                        throw new RuntimeException("Xlsx::{$method}(): cannot resume '{$workFile}' at byte {$offset}.", self::ERROR_UNREADABLE);
                    }
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * A JSON line from the working file.
     *
     * @return array<array-key, mixed>
     *
     * @throws UnexpectedValueException If line is not valid JSON.
     */
    private static function stagedLine(string $method, string $workFile, string|false $line): array
    {
        $decoded = is_string($line) ? json_decode($line, true, 512, JSON_BIGINT_AS_STRING) : null;

        if (! is_array($decoded)) {
            throw new UnexpectedValueException("Xlsx::{$method}(): the staged file '{$workFile}' is damaged.", self::ERROR_CORRUPT);
        }

        return $decoded;
    }

    /**
     * Source identity for the working file: size, modification time, requested sheet.
     *
     * @return array{stage: int, size: int, mtime: int, sheet: string|int|null}
     *
     * @throws RuntimeException If source cannot be read.
     */
    private static function sourceMeta(string $method, string $path, string|int|null $sheet): array
    {
        clearstatcache(true, $path);
        $size = is_file($path) ? filesize($path) : false;
        $mtime = is_file($path) ? filemtime($path) : false;

        if ($size === false || $mtime === false) {
            throw new RuntimeException("Xlsx::{$method}(): file '{$path}' does not exist or is not readable.", self::ERROR_UNREADABLE);
        }

        return ['stage' => 1, 'size' => $size, 'mtime' => $mtime, 'sheet' => $sheet];
    }

    /**
     * A JSON line (without embedded newline characters); 1.0 remains float, strings remain UTF-8.
     *
     * @param  array<array-key, mixed>  $value
     *
     * @throws UnexpectedValueException If the value cannot be encoded.
     */
    private static function jsonLine(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

        if ($json === false) {
            throw new UnexpectedValueException('Xlsx::stage(): a row cannot be encoded: '.json_last_error_msg().'.', self::ERROR_CORRUPT);
        }

        return $json."\n";
    }

    /**
     * @param  resource  $handle
     *
     * @throws RuntimeException If writing fails.
     */
    private static function writeStaged(mixed $handle, string $file, string $line): void
    {
        if (self::capture(static fn (): int|false => fwrite($handle, $line))[0] !== strlen($line)) {
            throw new RuntimeException("Xlsx::stage(): cannot write '{$file}'.");
        }
    }

    /**
     * Reads the next <row> element of the sheet.
     *
     * @param  array{dates: array<int, bool>, date1904: bool}  $book
     * @param  Closure(int): ?string  $string  Shared string with given index.
     * @param  int  $previous  Last read row number (updated).
     * @param  int  $skip  Rows with number <= $skip are skipped without decoding.
     * @param  bool  $positioned  Reader is already on next node (after a next() skip).
     * @return array{0: int, 1: list<mixed>, 2: array<int, true>}|null
     *
     * @throws UnexpectedValueException If sheet structure is invalid.
     */
    private static function nextRow(XMLReader $reader, array $book, Closure $string, int &$previous, int $skip, bool &$positioned): ?array
    {
        while (true) {
            if ($positioned) {
                $positioned = false;
            } elseif (! $reader->read()) {
                return null;
            }

            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                continue;
            }

            $number = self::intAttribute($reader, 'r') ?? $previous + 1;
            if ($number < 1 || $number > self::MAX_ROWS) {
                throw new UnexpectedValueException('invalid row number', self::ERROR_CORRUPT);
            }
            $previous = $number;

            if ($number <= $skip) {
                // next() sare peste continutul randului in libxml (fara cod PHP per celula)
                // si lasa cititorul pe nodul urmator.
                $reader->next();
                $positioned = true;

                continue;
            }

            $cells = [];
            $errors = [];
            if (! $reader->isEmptyElement) {
                $column = -1;
                while ($reader->read() && ($reader->nodeType !== XMLReader::END_ELEMENT || $reader->localName !== 'row')) {
                    if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'c') {
                        continue;
                    }

                    $reference = $reader->getAttribute('r');
                    $column = $reference === null ? $column + 1 : self::columnIndex($reference);
                    // Cells without a reference count up from the previous one: cap them like referenced cells.
                    if ($column >= self::MAX_COLUMNS) {
                        throw new UnexpectedValueException('invalid cell reference', self::ERROR_CORRUPT);
                    }
                    [$value, $isError] = self::cell($reader, $book, $string);
                    $cells[$column] = $value;
                    if ($isError) {
                        $errors[$column] = true;
                    }
                }
            }

            $values = [];
            $width = $cells === [] ? 0 : max(array_keys($cells)) + 1;
            for ($i = 0; $i < $width; $i++) {
                $values[] = $cells[$i] ?? null;
            }

            return [$number, $values, $errors];
        }
    }

    /**
     * The value of a <c> cell; reader stops at the end of the element.
     *
     * @param  array{dates: array<int, bool>, date1904: bool}  $book
     * @param  Closure(int): ?string  $string
     * @return array{0: mixed, 1: bool} [value, is Excel error]
     *
     * @throws UnexpectedValueException If the cell is invalid.
     */
    private static function cell(XMLReader $reader, array $book, Closure $string): array
    {
        $type = $reader->getAttribute('t') ?? 'n';
        $style = self::intAttribute($reader, 's') ?? 0;
        $raw = null;

        if (! $reader->isEmptyElement) {
            while ($reader->read() && ($reader->nodeType !== XMLReader::END_ELEMENT || $reader->localName !== 'c')) {
                if ($reader->nodeType === XMLReader::ELEMENT && ($reader->localName === 'v' || $reader->localName === 'is')) {
                    $raw = $reader->readString();
                }
            }
        }

        return match ($type) {
            's' => [self::sharedString($string, $raw), false],
            'inlineStr', 'str' => [$raw === null ? null : self::unescape($raw), false],
            'b' => [$raw === null ? null : $raw === '1', false],
            'e' => [$raw, true],
            'd' => [$raw === null || $raw === '' ? null : self::isoDate($raw), false],
            default => [self::number($raw, $book['dates'][$style] ?? false, $book['date1904']), false],
        };
    }

    /**
     * @param  Closure(int): ?string  $string
     *
     * @throws UnexpectedValueException If the index does not exist.
     */
    private static function sharedString(Closure $string, ?string $raw): string
    {
        $text = $raw !== null && preg_match('/^\d{1,9}$/D', $raw) === 1 ? $string((int) $raw) : null;

        return $text ?? throw new UnexpectedValueException('invalid shared string index', self::ERROR_CORRUPT);
    }

    /**
     * A number from the cell; if the style is a date style, the day count is converted to a date.
     *
     * @throws UnexpectedValueException If the value is not numeric.
     */
    private static function number(?string $raw, bool $isDate, bool $date1904): int|float|DateTimeImmutable|null
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_numeric($raw)) {
            throw new UnexpectedValueException('invalid numeric cell', self::ERROR_CORRUPT);
        }

        if ($isDate && (float) $raw >= 0) {
            return self::fromSerial((float) $raw, $date1904);
        }

        if (preg_match('/^-?\d{1,18}$/D', $raw) === 1) {
            return (int) $raw;
        }

        return (float) $raw;
    }

    /**
     * Excel serial day number to date (in the default timezone, without timezone conversions).
     */
    private static function fromSerial(float $serial, bool $date1904): DateTimeImmutable
    {
        $days = (int) floor($serial);
        $seconds = (int) round(($serial - $days) * 86400);
        if ($seconds >= 86400) {
            $days++;
            $seconds -= 86400;
        }

        // Sistemul 1900 include ziua inexistenta 29.02.1900 (serial 60, preluata din Lotus 1-2-3);
        // ea este citita ca 01.03.1900, iar datele de dupa ea au baza 30.12.1899.
        $base = match (true) {
            $date1904 => '1904-01-01',
            $days < 61 => '1899-12-31',
            default => '1899-12-30',
        };

        return (new DateTimeImmutable($base))->modify("+{$days} days")->setTime(0, 0, $seconds);
    }

    /**
     * @throws UnexpectedValueException If the ISO date is invalid.
     */
    private static function isoDate(string $raw): DateTimeImmutable
    {
        foreach (['!Y-m-d\TH:i:s', '!Y-m-d\TH:i:s.u', '!Y-m-d'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, rtrim($raw, 'Z'));
            if ($date !== false) {
                return $date;
            }
        }

        throw new UnexpectedValueException('invalid ISO date cell', self::ERROR_CORRUPT);
    }

    /**
     * Text with decoded Excel _xHHHH_ sequences (e.g. _x000D_ = CR).
     */
    private static function unescape(string $text): string
    {
        return (string) preg_replace_callback('/_x([0-9A-Fa-f]{4})_/', static fn (array $match): string => mb_chr((int) hexdec($match[1]), 'UTF-8') ?: '', $text);
    }

    /**
     * Workbook structure: sheets, whether shared strings exist, date styles, date system.
     *
     * @return array{zip: ZipArchive, sheets: list<array{name: string, entry: string}>, hasStrings: bool, dates: array<int, bool>, date1904: bool}
     *
     * @throws RuntimeException If the file cannot be read.
     * @throws UnexpectedValueException If the file is not a valid .xlsx file.
     */
    private static function book(string $method, string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Xlsx::{$method}(): file '{$path}' does not exist or is not readable.", self::ERROR_UNREADABLE);
        }

        $zip = new ZipArchive;
        $code = self::capture(static fn (): bool|int => $zip->open($path, ZipArchive::RDONLY))[0];

        if ($code !== true) {
            $head = (string) self::capture(static fn (): string|false => file_get_contents($path, false, null, 0, 8))[0];
            $reason = str_starts_with($head, "\xD0\xCF\x11\xE0")
                ? 'it is an old .xls file; save it as .xlsx'
                : 'it is not an .xlsx file (not a ZIP archive)';

            throw new UnexpectedValueException("Xlsx::{$method}(): cannot read '{$path}': {$reason}.", self::ERROR_NOT_XLSX);
        }

        try {
            $declared = [];
            $date1904 = false;
            self::elements($method, $zip, 'xl/workbook.xml', $path, true, ['workbookPr', 'sheet'], static function (string $name, string $parent, array $attributes) use (&$declared, &$date1904): void {
                if ($name === 'workbookPr') {
                    $date1904 = in_array($attributes['date1904'] ?? '', ['1', 'true'], true);
                } else {
                    $declared[] = [$attributes['name'] ?? null, $attributes['id'] ?? ''];
                }
            });

            $targets = [];
            self::elements($method, $zip, 'xl/_rels/workbook.xml.rels', $path, true, ['Relationship'], static function (string $name, string $parent, array $attributes) use (&$targets): void {
                if (isset($attributes['Id'], $attributes['Target'])) {
                    $target = $attributes['Target'];
                    $targets[$attributes['Id']] = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
                }
            });

            $sheets = [];
            foreach ($declared as [$sheetName, $id]) {
                if ($sheetName === null || ! isset($targets[$id])) {
                    throw new UnexpectedValueException("Xlsx::{$method}(): file '{$path}' is damaged (sheet without target).", self::ERROR_CORRUPT);
                }
                $sheets[] = ['name' => $sheetName, 'entry' => $targets[$id]];
            }

            if ($sheets === []) {
                throw new UnexpectedValueException("Xlsx::{$method}(): file '{$path}' is damaged (no sheets).", self::ERROR_CORRUPT);
            }

            // Only what dateStyles() needs: custom formats and one int per cell format (not every attribute).
            $formats = [];
            $cellFormats = [];
            self::elements($method, $zip, 'xl/styles.xml', $path, false, ['numFmt', 'xf'], static function (string $name, string $parent, array $attributes) use (&$formats, &$cellFormats): void {
                if ($name === 'numFmt' && isset($attributes['numFmtId'], $attributes['formatCode'])) {
                    $formats[(int) $attributes['numFmtId']] = $attributes['formatCode'];
                } elseif ($name === 'xf' && $parent === 'cellXfs') {
                    $cellFormats[] = (int) ($attributes['numFmtId'] ?? 0);
                }
            });

            return ['zip' => $zip, 'sheets' => $sheets, 'hasStrings' => $zip->statName('xl/sharedStrings.xml') !== false, 'dates' => self::dateStyles($formats, $cellFormats), 'date1904' => $date1904];
        } catch (Throwable $e) {
            $zip->close();

            throw $e;
        }
    }

    /**
     * Streams the elements named in $keep of a small XML file within the archive to $visit(local name, parent,
     * attributes). Nothing is accumulated here and at most MAX_ELEMENTS elements are visited, so a crafted
     * file with millions of elements cannot turn into gigabytes of PHP arrays.
     *
     * @param  list<string>  $keep  Local names of the elements the caller needs.
     * @param  Closure(string, string, array<string, string>): void  $visit
     *
     * @throws UnexpectedValueException If the required file is missing, the XML is invalid or has too many elements.
     */
    private static function elements(string $method, ZipArchive $zip, string $entry, string $path, bool $required, array $keep, Closure $visit): void
    {
        $xml = self::entry($method, $zip, $entry, $path, $required);
        if ($xml === null) {
            return;
        }

        $keep = array_fill_keys($keep, true);

        // 'ok', 'invalid' (cannot be parsed) or 'too-many' (more than MAX_ELEMENTS relevant elements).
        $parse = static function () use ($xml, $keep, $visit): string {
            // Pe instanta: in PHP 7.4 XMLReader::XML() nu este statica.
            $reader = new XMLReader;
            if (! $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
                return 'invalid';
            }

            $visited = 0;
            $stack = [];
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::END_ELEMENT) {
                    array_pop($stack);
                } elseif ($reader->nodeType === XMLReader::ELEMENT) {
                    if (isset($keep[$reader->localName])) {
                        if (++$visited > self::MAX_ELEMENTS) {
                            $reader->close();

                            return 'too-many';
                        }

                        $attributes = [];
                        if ($reader->moveToFirstAttribute()) {
                            do {
                                $attributes[$reader->localName] = $reader->value;
                            } while ($reader->moveToNextAttribute());
                            $reader->moveToElement();
                        }
                        $visit($reader->localName, end($stack) === false ? '' : end($stack), $attributes);
                    }
                    if (! $reader->isEmptyElement) {
                        $stack[] = $reader->localName;
                    }
                }
            }
            $reader->close();

            return 'ok';
        };

        [$status, $warning] = self::capture($parse);
        if ($status === 'too-many') {
            throw new UnexpectedValueException("Xlsx::{$method}(): file '{$path}' is damaged ('{$entry}' has more than ".self::MAX_ELEMENTS.' elements).', self::ERROR_CORRUPT);
        }

        if ($warning !== null || $status !== 'ok') {
            throw new UnexpectedValueException("Xlsx::{$method}(): file '{$path}' is damaged ('{$entry}' is not valid XML).", self::ERROR_CORRUPT);
        }
    }

    /**
     * Shared strings (xl/sharedStrings.xml), excluding phonetic texts (rPh), streamed.
     * A small number of strings remain in memory; beyond MEMORY_STRINGS they are moved to a temporary file,
     * with an 8-byte index per string, so memory does not depend on workbook size.
     *
     * @return array{0: Closure(int): ?string, 1: Closure(): void} [string with given index, resource cleanup]
     *
     * @throws RuntimeException If the temporary file cannot be written.
     * @throws UnexpectedValueException If the file is invalid.
     */
    private static function sharedStrings(string $method, string $path): array
    {
        $memory = [];
        $bytes = 0;
        $file = null;
        $offsets = '';
        $position = 0;

        $store = static function (string $text) use (&$memory, &$bytes, &$file, &$offsets, &$position, $method): void {
            if (! is_resource($file)) {
                $memory[] = $text;
                $bytes += strlen($text);
                if (count($memory) <= self::MEMORY_STRINGS && $bytes <= self::MEMORY_STRINGS_BYTES) {
                    return;
                }

                $file = tmpfile();
                if ($file === false) {
                    throw new RuntimeException("Xlsx::{$method}(): cannot create a temporary file for the shared strings.");
                }
                $pending = $memory;
                $memory = [];
            } else {
                $pending = [$text];
            }

            foreach ($pending as $item) {
                if (fwrite($file, $item) !== strlen($item)) {
                    throw new RuntimeException("Xlsx::{$method}(): cannot write the shared strings to a temporary file.");
                }
                $offsets .= pack('J', $position);
                $position += strlen($item);
            }
        };

        $parse = static function () use ($path, $store, $method): bool {
            $reader = new XMLReader;
            if (! $reader->open('zip://'.$path.'#xl/sharedStrings.xml', null, LIBXML_NONET | LIBXML_COMPACT)) {
                return false;
            }

            $current = null;
            $phonetic = 0;
            while ($reader->read()) {
                $isElement = $reader->nodeType === XMLReader::ELEMENT;
                $isEnd = $reader->nodeType === XMLReader::END_ELEMENT;

                if ($reader->localName === 'si' && $isElement) {
                    $current = '';
                    if ($reader->isEmptyElement) {
                        $store('');
                        $current = null;
                    }
                } elseif ($reader->localName === 'si' && $isEnd) {
                    $store(self::unescape((string) $current));
                    $current = null;
                } elseif ($reader->localName === 'rPh' && $isElement && ! $reader->isEmptyElement) {
                    $phonetic++;
                } elseif ($reader->localName === 'rPh' && $isEnd) {
                    $phonetic--;
                } elseif ($reader->localName === 't' && $isElement && $current !== null && $phonetic === 0) {
                    $current .= $reader->readString();
                    // A string made of many <t> runs is still one cell: Excel caps it at 32,767 characters
                    // (at most 4 bytes each in UTF-8, plus room for escapes such as _x000D_).
                    if (strlen($current) > self::MAX_CELL_LENGTH * 4 * 2) {
                        throw new UnexpectedValueException("Xlsx::{$method}(): file '{$path}' is damaged (a shared string is longer than ".self::MAX_CELL_LENGTH.' characters).', self::ERROR_CORRUPT);
                    }
                }
            }
            $reader->close();

            return true;
        };

        $close = static function () use (&$file): void {
            if (is_resource($file)) {
                fclose($file);
            }
        };

        try {
            [$parsed, $warning] = self::capture($parse);
        } catch (Throwable $e) {
            $close();

            throw $e;
        }

        if (! $parsed || $warning !== null) {
            $close();

            throw new UnexpectedValueException("Xlsx::{$method}(): file '{$path}' is damaged ('xl/sharedStrings.xml' is not valid XML).", self::ERROR_CORRUPT);
        }

        if (! is_resource($file)) {
            return [static fn (int $index): ?string => $memory[$index] ?? null, $close];
        }

        $offsets .= pack('J', $position);
        $count = intdiv(strlen($offsets), 8) - 1;
        $cache = [];

        $lookup = static function (int $index) use ($file, $offsets, $count, &$cache): ?string {
            if (isset($cache[$index])) {
                return $cache[$index];
            }
            if ($index < 0 || $index >= $count) {
                return null;
            }

            $range = unpack('Jstart/Jend', $offsets, $index * 8);
            if ($range === false || ! is_int($range['start']) || ! is_int($range['end'])) {
                return null;
            }

            $length = $range['end'] - $range['start'];
            $text = $length === 0 ? '' : stream_get_contents($file, $length, $range['start']);
            if (! is_string($text)) {
                return null;
            }

            if (count($cache) >= self::STRING_CACHE) {
                $cache = [];
            }

            return $cache[$index] = $text;
        };

        return [$lookup, $close];
    }

    /**
     * Cell styles (position in cellXfs) that format dates or times; only date styles are listed.
     *
     * @param  array<int, string>  $formats  Custom number formats: numFmtId => format code.
     * @param  list<int>  $cellFormats  numFmtId of each cellXfs entry, in order.
     * @return array<int, bool>
     */
    private static function dateStyles(array $formats, array $cellFormats): array
    {
        $isDate = [];
        $dates = [];
        foreach ($cellFormats as $index => $id) {
            $isDate[$id] ??= isset($formats[$id]) ? self::isDateFormat($formats[$id]) : in_array($id, self::DATE_FORMAT_IDS, true);
            if ($isDate[$id]) {
                $dates[$index] = true;
            }
        }

        return $dates;
    }

    /**
     * A custom numeric format is a date format if, stripped of literals, escapes, and [...] sections, it contains d, m, y, h, or s.
     */
    private static function isDateFormat(string $code): bool
    {
        $clean = (string) preg_replace(['/"[^"]*"/', '/\\\\./', '/\[[^\]]*\]/', '/[_*]./'], '', $code);

        return preg_match('/[dmyhs]/i', $clean) === 1;
    }

    /**
     * Content of a file in the archive (null if missing and not required).
     *
     * @throws UnexpectedValueException If the required file is missing or too large.
     */
    private static function entry(string $method, ZipArchive $zip, string $entry, string $path, bool $required): ?string
    {
        $stat = $zip->statName($entry);
        if ($stat === false) {
            if ($required) {
                throw new UnexpectedValueException("Xlsx::{$method}(): file '{$path}' is damaged ('{$entry}' is missing).", self::ERROR_CORRUPT);
            }

            return null;
        }

        if ($stat['size'] > self::MAX_XML_BYTES) {
            throw new UnexpectedValueException("Xlsx::{$method}(): '{$entry}' in '{$path}' is larger than ".(self::MAX_XML_BYTES >> 20).' MB.', self::ERROR_CORRUPT);
        }

        $content = $zip->getFromName($entry);
        if ($content === false) {
            throw new UnexpectedValueException("Xlsx::{$method}(): file '{$path}' is damaged (cannot extract '{$entry}').", self::ERROR_CORRUPT);
        }

        return $content;
    }

    /**
     * @param  list<array{name: string, entry: string}>  $sheets
     *
     * @throws UnexpectedValueException If the sheet does not exist.
     */
    private static function sheetEntry(string $method, array $sheets, string|int|null $sheet, string $path): string
    {
        foreach ($sheets as $index => $candidate) {
            if ($sheet === null || $sheet === $index || $sheet === $candidate['name']) {
                return $candidate['entry'];
            }
        }

        $names = implode("', '", array_column($sheets, 'name'));

        throw new UnexpectedValueException("Xlsx::{$method}(): sheet '{$sheet}' does not exist in '{$path}' (sheets: '{$names}').", self::ERROR_SHEET);
    }

    /**
     * 'A1' -> 0, 'AB12' -> 27.
     *
     * @throws UnexpectedValueException If the reference is invalid.
     */
    private static function columnIndex(string $reference): int
    {
        if (preg_match('/^([A-Z]{1,3})\d*$/D', $reference, $match) !== 1) {
            throw new UnexpectedValueException('invalid cell reference', self::ERROR_CORRUPT);
        }

        $index = 0;
        foreach (str_split($match[1]) as $letter) {
            $index = $index * 26 + ord($letter) - 64;
        }

        if ($index > self::MAX_COLUMNS) {
            throw new UnexpectedValueException('invalid cell reference', self::ERROR_CORRUPT);
        }

        return $index - 1;
    }

    private static function intAttribute(XMLReader $reader, string $name): ?int
    {
        $value = $reader->getAttribute($name);

        return $value !== null && preg_match('/^\d{1,9}$/D', $value) === 1 ? (int) $value : null;
    }

    // ---------------------------------------------------------------- scriere

    /**
     * @param  array<string, iterable<array<string, mixed>>>  $sheets
     * @return list<array{name: string, rows: iterable<array<array-key, mixed>>, header: list<string>|null}>
     *
     * @throws InvalidArgumentException If there are no sheets.
     */
    private static function sheetList(string $method, array $sheets): array
    {
        if ($sheets === []) {
            throw new InvalidArgumentException("Xlsx::{$method}(): at least one sheet is required.");
        }

        $list = [];
        foreach ($sheets as $name => $rows) {
            $list[] = ['name' => (string) $name, 'rows' => $rows, 'header' => null];
        }

        return $list;
    }

    /**
     * Builds the .xlsx file: sheets into temporary files, then the archive, then atomic rename.
     *
     * @param  list<array{name: string, rows: iterable<array<array-key, mixed>>, header: list<string>|null}>  $sheets
     *
     * @throws InvalidArgumentException If sheet names or data are invalid.
     * @throws RuntimeException If the file cannot be written.
     */
    private static function build(string $method, string $path, array $sheets): void
    {
        $seen = [];
        foreach ($sheets as $sheet) {
            self::checkSheetName($method, $sheet['name']);
            $lower = mb_strtolower($sheet['name']);
            if (isset($seen[$lower])) {
                throw new InvalidArgumentException("Xlsx::{$method}(): duplicate sheet name '{$sheet['name']}'.");
            }
            $seen[$lower] = true;
        }

        $directory = dirname($path);
        if (! is_dir($directory)) {
            throw new RuntimeException("Xlsx::{$method}(): directory '{$directory}' does not exist.");
        }

        $parts = [];
        $archive = null;

        try {
            foreach ($sheets as $sheet) {
                $part = self::tempFile($method, sys_get_temp_dir());
                $parts[] = ['name' => $sheet['name'], 'part' => $part];
                self::writeSheet($method, $part, $sheet['name'], $sheet['rows'], $sheet['header']);
            }

            $archive = self::tempFile($method, $directory);
            self::assemble($method, $archive, $parts);

            self::applyMode($archive, $path);
            [$renamed, $warning] = self::capture(static fn (): bool => rename($archive, $path));
            if (! $renamed) {
                throw new RuntimeException("Xlsx::{$method}(): cannot write '{$path}': ".($warning ?? 'unknown error').'.');
            }
            $archive = null;
        } finally {
            foreach ([...array_column($parts, 'part'), $archive] as $file) {
                if ($file !== null && is_file($file)) {
                    self::capture(static fn (): bool => unlink($file));
                }
            }
        }
    }

    /**
     * @throws InvalidArgumentException If the sheet name is not accepted by Excel.
     */
    private static function checkSheetName(string $method, string $name): void
    {
        if ($name === '' || mb_strlen($name) > 31 || preg_match('/[\[\]:*?\/\\\\]/', $name) === 1 || str_starts_with($name, "'") || str_ends_with($name, "'")) {
            throw new InvalidArgumentException("Xlsx::{$method}(): invalid sheet name '{$name}' (1-31 characters, without [ ] : * ? / \\ and not starting or ending with ').");
        }
    }

    /**
     * Assembles the .xlsx archive (in $archive, overwritten) from sheets already written to temporary XML files.
     *
     * @param  list<array{name: string, part: string}>  $sheets
     *
     * @throws RuntimeException If the archive cannot be written.
     */
    private static function assemble(string $method, string $archive, array $sheets): void
    {
        $zip = new ZipArchive;
        if (self::capture(static fn (): bool|int => $zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE))[0] !== true) {
            throw new RuntimeException("Xlsx::{$method}(): cannot create '{$archive}'.");
        }

        $zip->addFromString('[Content_Types].xml', self::contentTypes(count($sheets)));
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', self::workbookXml(array_column($sheets, 'name')));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRelations(count($sheets)));
        $zip->addFromString('xl/styles.xml', self::stylesXml());
        foreach ($sheets as $index => $sheet) {
            $zip->addFile($sheet['part'], 'xl/worksheets/sheet'.($index + 1).'.xml');
        }

        [$closed, $warning] = self::capture(static fn (): bool => $zip->close());
        if (! $closed) {
            throw new RuntimeException("Xlsx::{$method}(): cannot write '{$archive}': ".($warning ?? 'zip error').'.');
        }
    }

    /**
     * Streams worksheet XML (memory does not scale with row count).
     *
     * @param  iterable<array<array-key, mixed>>  $rows
     * @param  list<string>|null  $header
     *
     * @throws InvalidArgumentException If data is invalid or exceeds Excel limits.
     * @throws RuntimeException If the temporary file cannot be written.
     */
    private static function writeSheet(string $method, string $file, string $sheet, iterable $rows, ?array $header): void
    {
        $writer = self::openSheet($method, $file);
        $rowNumber = 0;
        $position = 0;
        $started = false;

        foreach ($rows as $row) {
            if (! $started) {
                $header ??= array_map(strval(...), array_keys($row));
                self::startSheetData($method, $writer, $sheet, $header);
                $started = true;
                $rowNumber = 1;
            }

            if (++$rowNumber > self::MAX_ROWS) {
                throw new InvalidArgumentException("Xlsx::{$method}(): sheet '{$sheet}' has more than ".self::MAX_ROWS.' rows (Excel limit); use Xlsx::writer() with a .zip path.');
            }

            self::addRow($method, $writer, $sheet, $rowNumber, $row, $header, $position++);
        }

        if (! $started) {
            self::startSheetData($method, $writer, $sheet, $header ?? []);
            $rowNumber = $header === null || $header === [] ? 0 : 1;
        }

        self::finishSheet($method, $writer, $file, $header, $rowNumber);
    }

    /**
     * @throws RuntimeException If the temporary file cannot be opened.
     */
    private static function openSheet(string $method, string $file): XMLWriter
    {
        $writer = new XMLWriter;
        if (! $writer->openUri($file)) {
            throw new RuntimeException("Xlsx::{$method}(): cannot write the temporary file '{$file}'.");
        }

        $writer->startDocument('1.0', 'UTF-8', 'yes');
        $writer->startElement('worksheet');
        $writer->writeAttribute('xmlns', self::NS_MAIN);
        $writer->writeAttribute('xmlns:r', self::NS_RELATIONSHIPS);

        return $writer;
    }

    /**
     * Validates the row against the header and writes it.
     *
     * @param  array<array-key, mixed>  $row
     * @param  list<string>  $header
     *
     * @throws InvalidArgumentException If the row does not match the header or contains invalid values.
     */
    private static function addRow(string $method, XMLWriter $writer, string $sheet, int $rowNumber, array $row, array $header, int $position): void
    {
        if (array_is_list($row)) {
            if (count($row) !== count($header)) {
                throw new InvalidArgumentException("Xlsx::{$method}(): sheet '{$sheet}', row #{$position} has ".count($row).' values, the header has '.count($header).'.');
            }
            $values = $row;
        } else {
            if (array_map(strval(...), array_keys($row)) !== $header) {
                throw new InvalidArgumentException("Xlsx::{$method}(): sheet '{$sheet}', the keys of row #{$position} do not match the header (same columns, same order).");
            }
            $values = array_values($row);
        }

        self::writeRow($method, $writer, $sheet, $rowNumber, $values, $header, false);

        if ($rowNumber % 1000 === 0) {
            $writer->flush();
        }
    }

    /**
     * Closes the worksheet XML: ends data, writes auto-filter, and flushes to disk.
     *
     * @param  list<string>|null  $header
     *
     * @throws RuntimeException If the temporary file could not be written.
     */
    private static function finishSheet(string $method, XMLWriter $writer, string $file, ?array $header, int $rowNumber): void
    {
        $writer->endElement(); // sheetData

        if ($header !== null && $header !== []) {
            $writer->startElement('autoFilter');
            $writer->writeAttribute('ref', 'A1:'.self::columnName(count($header) - 1).max(1, $rowNumber));
            $writer->endElement();
        }

        $writer->endElement(); // worksheet
        $writer->endDocument();
        $writer->flush();
        clearstatcache(true, $file);

        if (! is_file($file) || filesize($file) === 0) {
            throw new RuntimeException("Xlsx::{$method}(): cannot write the temporary file '{$file}'.");
        }
    }

    /**
     * Worksheet header: frozen pane, column widths, bold header row.
     *
     * @param  list<string>  $header
     *
     * @throws InvalidArgumentException If the header has too many columns or invalid values.
     */
    private static function startSheetData(string $method, XMLWriter $writer, string $sheet, array $header): void
    {
        if (count($header) > self::MAX_COLUMNS) {
            throw new InvalidArgumentException("Xlsx::{$method}(): sheet '{$sheet}' has more than ".self::MAX_COLUMNS.' columns (Excel limit).');
        }

        if ($header !== []) {
            $writer->startElement('sheetViews');
            $writer->startElement('sheetView');
            $writer->writeAttribute('workbookViewId', '0');
            $writer->startElement('pane');
            $writer->writeAttribute('ySplit', '1');
            $writer->writeAttribute('topLeftCell', 'A2');
            $writer->writeAttribute('activePane', 'bottomLeft');
            $writer->writeAttribute('state', 'frozen');
            $writer->endElement();
            $writer->endElement();
            $writer->endElement();

            $writer->startElement('cols');
            foreach ($header as $index => $name) {
                $writer->startElement('col');
                $writer->writeAttribute('min', (string) ($index + 1));
                $writer->writeAttribute('max', (string) ($index + 1));
                $writer->writeAttribute('width', (string) min(60, max(10, mb_strlen($name) + 4)));
                $writer->writeAttribute('customWidth', '1');
                $writer->endElement();
            }
            $writer->endElement();
        }

        $writer->startElement('sheetData');
        if ($header !== []) {
            self::writeRow($method, $writer, $sheet, 1, $header, $header, true);
        }
    }

    /**
     * @param  list<mixed>  $values
     * @param  list<string>  $header
     *
     * @throws InvalidArgumentException If a value is invalid.
     */
    private static function writeRow(string $method, XMLWriter $writer, string $sheet, int $rowNumber, array $values, array $header, bool $isHeader): void
    {
        $writer->startElement('row');
        $writer->writeAttribute('r', (string) $rowNumber);

        foreach ($values as $index => $value) {
            if ($value === null) {
                continue;
            }

            $where = "sheet '{$sheet}', row {$rowNumber}, column '".($header[$index] ?? $index)."'";
            $writer->startElement('c');
            $writer->writeAttribute('r', self::columnName($index).$rowNumber);

            if ($isHeader) {
                $writer->writeAttribute('s', (string) self::STYLE_HEADER);
            }

            if (is_string($value)) {
                $writer->writeAttribute('t', 'inlineStr');
                $writer->startElement('is');
                $writer->startElement('t');
                $text = self::text($method, $value, $where);
                if ($text !== trim($text)) {
                    $writer->writeAttribute('xml:space', 'preserve');
                }
                $writer->text($text);
                $writer->endElement();
                $writer->endElement();
            } elseif (is_bool($value)) {
                $writer->writeAttribute('t', 'b');
                $writer->writeElement('v', $value ? '1' : '0');
            } elseif (is_int($value)) {
                $writer->writeElement('v', (string) $value);
            } elseif (is_float($value)) {
                if (! is_finite($value)) {
                    throw new InvalidArgumentException("Xlsx::{$method}(): {$where}: number must be finite.");
                }
                // var_export() scrie cea mai scurta reprezentare exacta; (string) ar rotunji la 14 cifre.
                $writer->writeElement('v', var_export($value, true));
            } elseif ($value instanceof DateTimeInterface) {
                [$serial, $hasTime] = self::toSerial($method, $value, $where);
                $writer->writeAttribute('s', (string) ($hasTime ? self::STYLE_DATETIME : self::STYLE_DATE));
                $writer->writeElement('v', $serial);
            } else {
                throw new InvalidArgumentException("Xlsx::{$method}(): {$where}: unsupported type ".get_debug_type($value).'.');
            }

            $writer->endElement();
        }

        $writer->endElement();
    }

    /**
     * Prepares text for a cell: valid UTF-8, no prohibited XML characters, within Excel limits;
     * literal '_xHHHH_' sequences are escaped so Excel does not decode them.
     *
     * @throws InvalidArgumentException If the text is invalid.
     */
    private static function text(string $method, string $value, string $where): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException("Xlsx::{$method}(): {$where}: text is not valid UTF-8.");
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x{FFFE}\x{FFFF}]/u', $value) === 1) {
            throw new InvalidArgumentException("Xlsx::{$method}(): {$where}: text contains control characters not allowed in Excel.");
        }

        if (mb_strlen($value) > self::MAX_CELL_LENGTH) {
            throw new InvalidArgumentException("Xlsx::{$method}(): {$where}: text is longer than ".self::MAX_CELL_LENGTH.' characters (Excel limit).');
        }

        return (string) preg_replace('/_(x[0-9A-Fa-f]{4}_)/', '_x005F_$1', $value);
    }

    /**
     * Date -> Excel day count (1900 date system) and whether it includes time.
     *
     * @return array{0: string, 1: bool}
     *
     * @throws InvalidArgumentException If the date is before 01.03.1900.
     */
    private static function toSerial(string $method, DateTimeInterface $date, string $where): array
    {
        $day = new DateTimeImmutable($date->format('Y-m-d'), new DateTimeZone('UTC'));
        $base = new DateTimeImmutable('1899-12-30', new DateTimeZone('UTC'));

        if ($day < new DateTimeImmutable('1900-03-01', new DateTimeZone('UTC'))) {
            throw new InvalidArgumentException("Xlsx::{$method}(): {$where}: dates before 01.03.1900 are not supported.");
        }

        $days = intdiv($day->getTimestamp() - $base->getTimestamp(), 86400);
        $seconds = (int) $date->format('G') * 3600 + (int) $date->format('i') * 60 + (int) $date->format('s');

        if ($seconds === 0) {
            return [(string) $days, false];
        }

        return [rtrim(rtrim(sprintf('%.10F', $days + $seconds / 86400), '0'), '.'), true];
    }

    /**
     * 0 -> 'A', 27 -> 'AB'.
     */
    private static function columnName(int $index): string
    {
        $name = '';
        for ($index++; $index > 0; $index = intdiv($index - 1, 26)) {
            $name = chr(65 + ($index - 1) % 26).$name;
        }

        return $name;
    }

    private static function contentTypes(int $sheets): string
    {
        $overrides = '';
        for ($i = 1; $i <= $sheets; $i++) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .$overrides
            .'</Types>';
    }

    /**
     * @param  list<string>  $names
     */
    private static function workbookXml(array $names): string
    {
        $sheets = '';
        foreach ($names as $index => $name) {
            $sheets .= '<sheet name="'.htmlspecialchars($name, ENT_XML1 | ENT_QUOTES, 'UTF-8').'" sheetId="'.($index + 1).'" r:id="rId'.($index + 1).'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="'.self::NS_MAIN.'" xmlns:r="'.self::NS_RELATIONSHIPS.'">'
            .'<bookViews><workbookView/></bookViews><sheets>'.$sheets.'</sheets></workbook>';
    }

    private static function workbookRelations(int $sheets): string
    {
        $relations = '';
        for ($i = 1; $i <= $sheets; $i++) {
            $relations .= '<Relationship Id="rId'.$i.'" Type="'.self::NS_RELATIONSHIPS.'/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
        }
        $relations .= '<Relationship Id="rId'.($sheets + 1).'" Type="'.self::NS_RELATIONSHIPS.'/styles" Target="styles.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relations.'</Relationships>';
    }

    private static function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="'.self::NS_MAIN.'">'
            .'<numFmts count="2"><numFmt numFmtId="164" formatCode="dd.mm.yyyy"/><numFmt numFmtId="165" formatCode="dd.mm.yyyy hh:mm"/></numFmts>'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="4">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    /**
     * @throws RuntimeException If the temporary file cannot be created.
     */
    private static function tempFile(string $method, string $directory): string
    {
        [$file, $warning] = self::capture(static fn (): string|false => tempnam($directory, '.xlsx'));

        if ($file === false) {
            throw new RuntimeException("Xlsx::{$method}(): cannot create a temporary file in '{$directory}': ".($warning ?? 'unknown error').'.');
        }

        return $file;
    }

    // ---------------------------------------------------------------- antet si schema (identice cu Csv)

    /**
     * Validated header: no empty or duplicate names; trailing empty columns are removed.
     *
     * @param  list<mixed>  $values
     * @return non-empty-list<string>
     *
     * @throws UnexpectedValueException If the header is invalid.
     */
    private static function header(string $method, array $values): array
    {
        $header = [];
        foreach ($values as $index => $value) {
            if ($value !== null && ! is_string($value) && ! is_int($value) && ! is_float($value)) {
                throw new UnexpectedValueException("Xlsx::{$method}(): header column ".($index + 1).' must be text.', self::ERROR_CORRUPT);
            }
            $header[] = trim(self::scalarKey($value));
        }

        while ($header !== [] && end($header) === '') {
            array_pop($header);
        }

        if ($header === []) {
            throw new UnexpectedValueException("Xlsx::{$method}(): the header row is empty.", self::ERROR_CORRUPT);
        }

        $seen = [];
        foreach ($header as $index => $name) {
            if ($name === '') {
                throw new UnexpectedValueException("Xlsx::{$method}(): header column ".($index + 1).' has no name.', self::ERROR_CORRUPT);
            }
            $lower = mb_strtolower($name);
            if (isset($seen[$lower])) {
                throw new UnexpectedValueException("Xlsx::{$method}(): duplicate header column '{$name}'.", self::ERROR_CORRUPT);
            }
            $seen[$lower] = true;
        }

        return $header;
    }

    /**
     * Matches the file header with schema columns (case-insensitive).
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
     * Values fitted to $width: extra empty cells are trimmed, missing cells are padded with null.
     *
     * @param  list<mixed>  $values
     * @return list<mixed>|null null if there are values beyond header columns.
     */
    private static function fit(array $values, int $width): ?array
    {
        while (count($values) > $width && (end($values) === null || end($values) === '')) {
            array_pop($values);
        }

        if (count($values) > $width) {
            return null;
        }

        return array_pad($values, $width, null);
    }

    /**
     * Validated schema with defaults populated.
     *
     * @param  array<string, array<string, mixed>>  $schema
     * @return array<string, array<string, mixed>>
     *
     * @throws InvalidArgumentException If the schema is invalid.
     */
    private static function schema(string $method, array $schema): array
    {
        if ($schema === []) {
            throw new InvalidArgumentException("Xlsx::{$method}(): schema must not be empty.");
        }

        foreach ($schema as $column => $rule) {
            if ($column === '') {
                throw new InvalidArgumentException("Xlsx::{$method}(): schema column names must not be empty.");
            }

            $unknown = array_diff(array_keys($rule), self::RULES);
            if ($unknown !== []) {
                throw new InvalidArgumentException("Xlsx::{$method}(): unknown rule '".implode("', '", $unknown)."' for column '{$column}'.");
            }

            $type = $rule['type'] ?? 'string';
            if (! in_array($type, self::TYPES, true)) {
                throw new InvalidArgumentException("Xlsx::{$method}(): invalid type for column '{$column}' (allowed: ".implode(', ', self::TYPES).').');
            }
            $rule['type'] = $type;

            if ($type === 'date') {
                $formats = $rule['format'] ?? ['d.m.Y', 'Y-m-d'];
                $formats = is_string($formats) ? [$formats] : $formats;
                if (! is_array($formats) || $formats === [] || array_filter($formats, static fn (mixed $format): bool => ! is_string($format) || $format === '') !== []) {
                    throw new InvalidArgumentException("Xlsx::{$method}(): 'format' for column '{$column}' must be a date format or a list of formats.");
                }
                $rule['format'] = array_values($formats);
            }

            if ($type === 'decimal') {
                $rule['scale'] ??= 2;
                $rule['decimal'] ??= ',';
                if (! is_int($rule['scale']) || $rule['scale'] < 0 || ! in_array($rule['decimal'], [',', '.'], true)) {
                    throw new InvalidArgumentException("Xlsx::{$method}(): 'scale' must be an int >= 0 and 'decimal' ',' or '.' for column '{$column}'.");
                }
            }

            if (isset($rule['max']) && (! is_int($rule['max']) || $rule['max'] < 1)) {
                throw new InvalidArgumentException("Xlsx::{$method}(): 'max' for column '{$column}' must be an int >= 1.");
            }

            if (isset($rule['pattern'])) {
                $pattern = $rule['pattern'];
                if (! is_string($pattern) || self::capture(static fn (): int|false => preg_match($pattern, ''))[0] === false) {
                    throw new InvalidArgumentException("Xlsx::{$method}(): 'pattern' for column '{$column}' is not a valid regular expression.");
                }
            }

            if (isset($rule['in']) && (! is_array($rule['in']) || $rule['in'] === [])) {
                throw new InvalidArgumentException("Xlsx::{$method}(): 'in' for column '{$column}' must be a non-empty list.");
            }

            $schema[$column] = $rule;
        }

        return $schema;
    }

    /**
     * Validates and normalizes a value according to the column rule.
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
     * File exception message without Class::method() prefix.
     */
    private static function fileMessage(Throwable $e): string
    {
        return ucfirst((string) preg_replace('/^Xlsx::\w+\(\): /', '', $e->getMessage()));
    }

    private static function scalarKey(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? '1' : '0',
            is_float($value) => rtrim(rtrim(sprintf('%.15F', $value), '0'), '.'),
            is_int($value), is_string($value) => (string) $value,
            default => '',
        };
    }

    /**
     * tempnam() creates 0600 files: before the rename, give the export the permissions of the file it replaces,
     * or the umask default, so the web server and other processes can still read it. No effect on Windows.
     */
    private static function applyMode(string $temp, string $target): void
    {
        $mode = is_file($target) ? (int) fileperms($target) & 0777 : 0666 & ~umask();
        self::capture(static fn (): bool => chmod($temp, $mode));
    }

    /**
     * Runs a native function and captures any emitted warning instead of displaying it.
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
