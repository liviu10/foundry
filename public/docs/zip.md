# Zip

> Secure archive creation and extraction (protected against zip slip, symlink attacks, and zip bombs).

Canonical: https://foundry.insightdevhub.ro/docs/zip/  
Namespace: `Foundry\Zip` · File: `Zip.php` · Version 1.1 · PHP 8.3+ · Extensions: zip

Creation, listing, and extraction of ZIP archives wrapping ZipArchive.

Natively, ZipArchive::open() returns true OR an integer error code (which is truthy, so "if ($zip->open(...))" passes even on error), while addFile()/close() return false without details.

On extraction, all entries are validated before writing anything to disk:

- unsafe paths ("../", absolute paths, drive letters) -> exception ("zip slip" protection)
- symlinks (Unix archives) -> exception
- "zip bomb" protection: optional limits on the real extracted size ($maxBytes, counted while decompressing, never taken from the archive headers) and on the number of entries ($maxEntries); an entry that decompresses to more than it declares is always rejected.

## Usage

```php
require_once 'Zip.php';
use Foundry\Zip;

Zip::create(__DIR__.'/export.zip', [
    __DIR__.'/report.pdf',                       // in archive: report.pdf
    'attachments/contract.pdf' => $contractPath, // in archive: attachments/contract.pdf
    'documents' => __DIR__.'/storage/docs',      // directory, added recursively
]);

Zip::createFromStrings(__DIR__.'/data.zip', ['data.csv' => $csv, 'info.txt' => 'Automatically generated']);

$files = Zip::extract($uploadedZip, __DIR__.'/import', maxBytes: 100 * 1024 * 1024);
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Zip.php';

use Foundry\Zip;
```

## Methods

### create()

```php
public static function create(string $zipPath, array $files, bool $overwrite = false): void
```

Creates an archive from existing files and directories.

Parameters:
- `$zipPath` (`string`, required): Archive path; the directory must exist.
- `$files` (`array<int|string, string>`, required): Integer key: archive entry name = file basename; string key: archive entry name (path). Directories are added recursively.
- `$overwrite` (`bool`, default `false`): true: overwrites an existing archive.

Throws:
- `InvalidArgumentException`: If the file list is empty, a file is missing, or a name is unsafe.
- `RuntimeException`: If the archive already exists or cannot be written.

### createFromStrings()

```php
public static function createFromStrings(string $zipPath, array $entries, bool $overwrite = false): void
```

Creates an archive from in-memory string contents.

Parameters:
- `$zipPath` (`string`, required): Archive path; the directory must exist.
- `$entries` (`array<string, string>`, required): Archive entry name => content.
- `$overwrite` (`bool`, default `false`): true: overwrites an existing archive.

Throws:
- `InvalidArgumentException`: If the entry list is empty or a name is unsafe.
- `RuntimeException`: If the archive already exists or cannot be written.

### list()

```php
public static function list(string $zipPath): array
```

List of entries in the archive.

Parameters:
- `$zipPath` (`string`, required)

Returns `list<array{name: string, size: int, compressedSize: int}>`.

Throws:
- `RuntimeException`: If the archive does not exist or cannot be read.

### extract()

```php
public static function extract(string $zipPath, string $directory, ?int $maxBytes = null, ?int $maxEntries = null): array
```

Extracts the archive into $directory after validating ALL entries.

Sizes declared in the archive are controlled by whoever built it, so they are never trusted: every entry is decompressed through a stream and the bytes actually produced are counted. An entry that produces more data than it declares is rejected as tampered, and extraction stops as soon as the real total passes $maxBytes. Files written by a failed call are removed. Error messages identify entries by position (entry #3), never by name.

Parameters:
- `$zipPath` (`string`, required): Archive path.
- `$directory` (`string`, required): Destination directory; must exist.
- `$maxBytes` (`int|null`, default `null`): Maximum total uncompressed size; null = unlimited (only for trusted archives).
- `$maxEntries` (`int|null`, default `null`): Maximum number of entries (files and directories); null = unlimited.

Returns `list<string>`: Names of extracted entries (files and directories), as stored in the archive.

Throws:
- `UnexpectedValueException`: If the archive contains unsafe paths or symlinks, has entries larger than declared, or exceeds $maxBytes / $maxEntries.
- `RuntimeException`: If the archive cannot be read or extraction fails.

