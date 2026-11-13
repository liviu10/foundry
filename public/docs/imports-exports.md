# Large imports and exports

> Import and export millions of CSV and Excel rows with constant memory - schema validation, batches, resumable cursors and streaming writers.

Canonical: https://foundry.insightdevhub.ro/docs/imports-exports/

`Csv` and `Xlsx` process millions of rows with constant memory. Everything is streamed inside your own loop: nothing is loaded into memory as a whole, and you decide when to write to the database and when to stop.

| Benchmark: 1,000,000 rows | Time | Peak memory |
| --- | --- | --- |
| CSV import | 8.1 s | 71 MB |
| `.xlsx` import | 13.5 s | 69 MB |

## Reading

```php
use Foundry\Csv;

foreach (Csv::read('customers.csv') as $row => $values) {
    // $values['name'], $values['email'] ...
}
```

`Csv::read()` takes the header from the first row, detects the delimiter, strips the BOM and converts legacy Windows-1250 files to UTF-8. Rows with the wrong number of fields are reported as errors instead of being silently shifted. `Xlsx::read()` works the same way for Excel files.

## Importing with a schema

`Csv::import()` and `Xlsx::import()` validate and normalize rows against a schema and deliver them in batches:

```php
$schema = [
    'cnp' => ['required' => true, 'pattern' => '/^\d{13}$/', 'unique' => true],
    'name' => ['required' => true, 'max' => 100],
    'birth_date' => ['type' => 'date', 'format' => 'd.m.Y'],
    'amount' => ['type' => 'decimal', 'scale' => 2, 'decimal' => ','],
    'type' => ['in' => ['PF', 'PJ']],
];
```

Each batch contains the valid rows (`rows`), the errors found (`errors`, with file, row, column and a stable code), per-file statistics (`files`), a resume `cursor`, and a `done` flag.

```php
$start = microtime(true);

foreach (Csv::import($files, $schema, batchSize: 5000, cursor: $cursor) as $batch) {
    $db->insertMany('customers', array_column($batch['rows'], 'data'));
    $errors = [...$errors, ...$batch['errors']];        // keep them for the import report
    $cursor = $batch['cursor'];                          // save it to resume later

    if ($batch['done'] || microtime(true) - $start > 20) {
        break;                                           // the next request resumes from $cursor
    }
}
```

## Resumable imports

The cursor is a JSON-serializable array. Store it (in the session, the database or a file) and pass it back on the next request: the import continues where it stopped, without re-reading processed files. This keeps each web request under its time limit, however large the upload.

For large `.xlsx` uploads, `Xlsx::stage()` prepares the workbook once so that later requests read from the staged work file.

Batch transactions and time limits stay in your code: the library never decides when to commit or when to stop.

## Writing

Writers receive rows one by one and create the final file atomically when you call `close()`:

```php
$out = Csv::writer('report.csv');                    // header from the first row's keys
foreach ($db->cursor('SELECT * FROM customers') as $row) {
    $out->add($row);
}
$out->close();
```

CSV files are written as UTF-8 with BOM, `;` as delimiter and CRLF line endings, so Excel opens them correctly. Values starting with `=`, `+`, `-` or `@` are prefixed to prevent formula execution (CSV injection). `Xlsx::writer()` splits very large exports into several files when a sheet would exceed the Excel row limit.
