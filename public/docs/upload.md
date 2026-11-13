# Upload

> Secure file uploads: MIME detection from file inspection, whitelist-checked extension, cryptographically random names.

Canonical: https://foundry.insightdevhub.ro/docs/upload/  
Namespace: `Foundry\Upload` · File: `Upload.php` · Version 1.0 · PHP 8.3+ · Extensions: mbstring, fileinfo

Validation and storage of files uploaded through forms ($_FILES).

## Security rules

- file MIME type is detected from content (finfo), not from $_FILES['type'] (sent by client)
- the saved file extension comes from the allowed types list, NOT from the client-provided name (a "invoice.php.jpg" or PHP script disguised as a PDF cannot reach disk with an executable extension)
- the saved file name is randomly generated (or explicitly provided and validated); nothing is overwritten
- only files genuinely uploaded via HTTP POST are accepted (is_uploaded_file).

## Exceptions

- InvalidArgumentException: user file is rejected (missing, too large, disallowed type); the message can be translated and displayed to the user
- RuntimeException:         server issue (missing temporary directory, write failure)
- LogicException:           programming error (invalid $_FILES structure, invalid configuration).

## Usage

```php
require_once 'Upload.php';
use Foundry\Upload;

$stored = Upload::store($_FILES['document'], __DIR__.'/storage/uploads', [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
], maxSize: 5 * 1024 * 1024);

// $stored['path'], $stored['name'], $stored['originalName'], $stored['size'], $stored['mimeType']

foreach (Upload::files($_FILES['documents']) as $file) { ... }   // <input type="file" multiple>
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Upload.php';

use Foundry\Upload;
```

## Methods

### store()

```php
public static function store(array $file, string $directory, array $allowed, int $maxSize, ?string $name = null): array
```

Validates the uploaded file and moves it to $directory.

Parameters:
- `$file` (`array<mixed>`, required): An element from $_FILES (e.g. $_FILES['document']).
- `$directory` (`string`, required): Destination directory; must exist and be writable.
- `$allowed` (`array<string, string>`, required): Allowed types: MIME type => saved file extension.
- `$maxSize` (`int`, required): Maximum allowed size in bytes.
- `$name` (`string|null`, default `null`): Saved file name without extension; null = randomly generated.

Returns `array{path: string, name: string, originalName: string, size: int, mimeType: string, extension: string}`.

Throws:
- `InvalidArgumentException`: If the user's file is rejected.
- `RuntimeException`: On server errors or if the target file already exists.
- `LogicException`: On invalid parameters.

### validate()

```php
public static function validate(array $file, array $allowed, int $maxSize): array
```

Validates the uploaded file without moving it: error code, size, actual MIME type. Does not check is_uploaded_file(); use store() to save files.

Parameters:
- `$file` (`array<mixed>`, required): An element from $_FILES.
- `$allowed` (`array<string, string>`, required): Allowed types: MIME type => extension.
- `$maxSize` (`int`, required): Maximum allowed size in bytes.

Returns `array{tmpName: string, originalName: string, size: int, mimeType: string, extension: string}`.

Throws:
- `InvalidArgumentException`: If the user's file is rejected.
- `RuntimeException`: On server errors.
- `LogicException`: On invalid parameters.

### files()

```php
public static function files(array $field): array
```

Converts a multi-file upload field (<input type="file" name="docs[]" multiple>) into a list of individual files, each in standard single-file format. Elements with UPLOAD_ERR_NO_FILE (unfilled fields) are removed.

Parameters:
- `$field` (`array<mixed>`, required): The $_FILES element (e.g. $_FILES['documents']).

Returns `list<array{name: string, type: string, tmp_name: string, error: int, size: int}>`.

Throws:
- `LogicException`: If the structure does not match a $_FILES field.

### maxServerSize()

```php
public static function maxServerSize(): int
```

Maximum upload size accepted by the server in bytes: minimum of upload_max_filesize and post_max_size (0 = unlimited).

Returns `int`.

