# Image

> Image metadata, validation against decompression bombs, aspect-ratio resizing, thumbnail generation; requires gd.

Canonical: https://foundry.insightdevhub.ro/docs/image/  
Namespace: `Foundry\Image` · File: `Image.php` · Version 1.2 · PHP 8.3+ · Extensions: gd

Image information, validation, and resizing (JPEG, PNG, GIF, WebP) using the gd extension.

Natively, getimagesize() and imagecreatefrom*() return false with a warning, and a small image on disk can declare huge dimensions and exhaust gigabytes of memory upon decoding ("decompression bomb"); validate() verifies pixel counts before decoding, and resize() / thumbnail() also check that the decoded image fits in the remaining memory_limit (~5 bytes per pixel), throwing an exception instead of PHP's uncatchable "Allowed memory size exhausted" fatal error.

resize() and thumbnail() re-encode images, thereby stripping EXIF metadata (GPS, camera model). For animated GIFs, only the first frame is retained.

## Usage

```php
require_once 'Image.php';
use Foundry\Image;

$info = Image::validate($path);                       // ['width' => 1920, 'height' => 1080, 'mimeType' => 'image/jpeg']
Image::resize($path, $dir.'/large.jpg', 1600, 1600);  // fits in 1600x1600, no upscaling
Image::thumbnail($path, $dir.'/thumb.webp', 200, 200);// exact 200x200, center-cropped
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Image.php';

use Foundry\Image;
```

## Constants

| Constant | Type | Value | Description |
| --- | --- | --- | --- |
| `DEFAULT_TYPES` | `list<string>` | `['image/jpeg', 'image/png', 'image/gif', 'image/webp']` | Default image types accepted by validate(). |
| `DEFAULT_MAX_PIXELS` | `int` | `40_000_000` | Default maximum pixel count (40 MP; typical phone photos are 12-50 MP). |

## Methods

### info()

```php
public static function info(string $path): array
```

Image dimensions and type read from headers (without decoding image data).

Parameters:
- `$path` (`string`, required)

Returns `array{width: int, height: int, mimeType: string}`.

Throws:
- `RuntimeException`: If the file does not exist.
- `UnexpectedValueException`: If the file is not a recognized image.

### validate()

```php
public static function validate(
    string $path,
    array $allowedTypes = self::DEFAULT_TYPES,
    int $maxPixels = self::DEFAULT_MAX_PIXELS,
    ?int $maxWidth = null,
    ?int $maxHeight = null,
): array
```

Verifies that the file is an allowed image type within reasonable dimensions.

Parameters:
- `$path` (`string`, required): The image file path.
- `$allowedTypes` (`list<string>`, default `self::DEFAULT_TYPES`): Allowed MIME types.
- `$maxPixels` (`int`, default `self::DEFAULT_MAX_PIXELS`): Maximum pixel count (width x height).
- `$maxWidth` (`int|null`, default `null`): Maximum width; null = no limit.
- `$maxHeight` (`int|null`, default `null`): Maximum height; null = no limit.

Returns `array{width: int, height: int, mimeType: string}`.

Throws:
- `RuntimeException`: If the file does not exist.
- `UnexpectedValueException`: If the image is invalid or exceeds dimension limits.

### resize()

```php
public static function resize(string $source, string $target, int $maxWidth, int $maxHeight, int $quality = 85): array
```

Resizes an image to fit within $maxWidth x $maxHeight while preserving aspect ratio. Smaller images are not upscaled. Target format is inferred from the extension of $target.

Parameters:
- `$source` (`string`, required): Source image path.
- `$target` (`string`, required): Destination image path (.jpg, .jpeg, .png, .gif, .webp); overwritten.
- `$maxWidth` (`int`, required): Maximum width.
- `$maxHeight` (`int`, required): Maximum height.
- `$quality` (`int`, default `85`): JPEG/WebP quality (0-100).

Returns `array{width: int, height: int}`: Resulting dimensions.

Throws:
- `InvalidArgumentException`: On invalid parameters.
- `UnexpectedValueException`: If the source is not a valid image.
- `RuntimeException`: If the image cannot be processed or saved.

### thumbnail()

```php
public static function thumbnail(string $source, string $target, int $width, int $height, int $quality = 85): void
```

Creates a thumbnail of exactly $width x $height: the image is scaled to cover the area and center-cropped (like "object-fit: cover").

Parameters:
- `$source` (`string`, required): Source image path.
- `$target` (`string`, required): Destination image path (.jpg, .jpeg, .png, .gif, .webp); overwritten.
- `$width` (`int`, required): Exact width.
- `$height` (`int`, required): Exact height.
- `$quality` (`int`, default `85`): JPEG/WebP quality (0-100).

Throws:
- `InvalidArgumentException`: On invalid parameters.
- `UnexpectedValueException`: If the source is not a valid image.
- `RuntimeException`: If the image cannot be processed or saved.

