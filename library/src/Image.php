<?php

declare(strict_types=1);

namespace Foundry;

use Closure;
use GdImage;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use UnexpectedValueException;

/**
 * Image Class
 *
 * Image information, validation, and resizing (JPEG, PNG, GIF, WebP) using the gd extension.
 *
 * Natively, getimagesize() and imagecreatefrom*() return false with a warning, and a small image
 * on disk can declare huge dimensions and exhaust gigabytes of memory upon decoding
 * ("decompression bomb"); validate() verifies pixel counts before decoding, and resize() / thumbnail()
 * also check that the decoded image fits in the remaining memory_limit (~5 bytes per pixel), throwing an
 * exception instead of PHP's uncatchable "Allowed memory size exhausted" fatal error.
 *
 * resize() and thumbnail() re-encode images, thereby stripping EXIF metadata (GPS, camera model).
 * For animated GIFs, only the first frame is retained.
 *
 * Usage:
 *   require_once 'Image.php';
 *   use Foundry\Image;
 *
 *   $info = Image::validate($path);                       // ['width' => 1920, 'height' => 1080, 'mimeType' => 'image/jpeg']
 *   Image::resize($path, $dir.'/large.jpg', 1600, 1600);  // fits in 1600x1600, no upscaling
 *   Image::thumbnail($path, $dir.'/thumb.webp', 200, 200);// exact 200x200, center-cropped
 *
 * @category Utilities
 *
 * @version  1.2
 *
 * @since    PHP 8.3
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
final class Image
{
    /** @var list<string> Default image types accepted by validate(). */
    public const array DEFAULT_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** @var int Default maximum pixel count (40 MP; typical phone photos are 12-50 MP). */
    public const int DEFAULT_MAX_PIXELS = 40_000_000;

    /** @var int Estimated memory per decoded pixel (4 bytes truecolor + gd/libjpeg working buffers). */
    private const int BYTES_PER_PIXEL = 5;

    /** @var array<string, string> Target file extension => saved MIME type. */
    private const array OUTPUT_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];

    private function __construct() {}

    /**
     * Image dimensions and type read from headers (without decoding image data).
     *
     * @return array{width: int, height: int, mimeType: string}
     *
     * @throws RuntimeException If the file does not exist.
     * @throws UnexpectedValueException If the file is not a recognized image.
     */
    public static function info(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Image::info(): file '{$path}' does not exist.");
        }

        [$size] = self::capture(static fn (): array|false => getimagesize($path));

        if ($size === false || $size[0] <= 0 || $size[1] <= 0) {
            throw new UnexpectedValueException("Image::info(): '{$path}' is not a valid image.");
        }

        return ['width' => $size[0], 'height' => $size[1], 'mimeType' => $size['mime']];
    }

    /**
     * Verifies that the file is an allowed image type within reasonable dimensions.
     *
     * @param  string  $path  The image file path.
     * @param  list<string>  $allowedTypes  Allowed MIME types.
     * @param  int  $maxPixels  Maximum pixel count (width x height).
     * @param  int|null  $maxWidth  Maximum width; null = no limit.
     * @param  int|null  $maxHeight  Maximum height; null = no limit.
     * @return array{width: int, height: int, mimeType: string}
     *
     * @throws RuntimeException If the file does not exist.
     * @throws UnexpectedValueException If the image is invalid or exceeds dimension limits.
     */
    public static function validate(
        string $path,
        array $allowedTypes = self::DEFAULT_TYPES,
        int $maxPixels = self::DEFAULT_MAX_PIXELS,
        ?int $maxWidth = null,
        ?int $maxHeight = null,
    ): array {
        $info = self::info($path);

        if (! in_array($info['mimeType'], $allowedTypes, true)) {
            throw new UnexpectedValueException(sprintf(
                "Image::validate(): image type '%s' is not allowed (allowed: %s).",
                $info['mimeType'],
                implode(', ', $allowedTypes),
            ));
        }

        if ($maxPixels < $info['width'] * $info['height']) {
            throw new UnexpectedValueException(sprintf(
                'Image::validate(): image is too large (%dx%d pixels, maximum %d pixels).',
                $info['width'],
                $info['height'],
                $maxPixels,
            ));
        }

        if (($maxWidth !== null && $info['width'] > $maxWidth) || ($maxHeight !== null && $info['height'] > $maxHeight)) {
            throw new UnexpectedValueException(sprintf(
                'Image::validate(): image dimensions %dx%d exceed the maximum of %sx%s.',
                $info['width'],
                $info['height'],
                $maxWidth ?? 'any',
                $maxHeight ?? 'any',
            ));
        }

        return $info;
    }

    /**
     * Resizes an image to fit within $maxWidth x $maxHeight while preserving aspect ratio.
     * Smaller images are not upscaled. Target format is inferred from the extension of $target.
     *
     * @param  string  $source  Source image path.
     * @param  string  $target  Destination image path (.jpg, .jpeg, .png, .gif, .webp); overwritten.
     * @param  int  $maxWidth  Maximum width.
     * @param  int  $maxHeight  Maximum height.
     * @param  int  $quality  JPEG/WebP quality (0-100).
     * @return array{width: int, height: int} Resulting dimensions.
     *
     * @throws InvalidArgumentException On invalid parameters.
     * @throws UnexpectedValueException If the source is not a valid image.
     * @throws RuntimeException If the image cannot be processed or saved.
     */
    public static function resize(string $source, string $target, int $maxWidth, int $maxHeight, int $quality = 85): array
    {
        self::assertDimensions('resize', $maxWidth, $maxHeight, $quality);
        $outputType = self::outputType('resize', $target);
        $info = self::validate($source);

        $scale = min(1.0, $maxWidth / $info['width'], $maxHeight / $info['height']);
        $width = max(1, (int) round($info['width'] * $scale));
        $height = max(1, (int) round($info['height'] * $scale));

        self::assertMemory('resize', $info['width'] * $info['height'] + $width * $height);
        $image = self::load('resize', $source, $info['mimeType']);
        $canvas = self::canvas('resize', $width, $height, $outputType);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $width, $height, $info['width'], $info['height']);

        self::save('resize', $canvas, $target, $outputType, $quality);

        return ['width' => $width, 'height' => $height];
    }

    /**
     * Creates a thumbnail of exactly $width x $height: the image is scaled to cover
     * the area and center-cropped (like "object-fit: cover").
     *
     * @param  string  $source  Source image path.
     * @param  string  $target  Destination image path (.jpg, .jpeg, .png, .gif, .webp); overwritten.
     * @param  int  $width  Exact width.
     * @param  int  $height  Exact height.
     * @param  int  $quality  JPEG/WebP quality (0-100).
     *
     * @throws InvalidArgumentException On invalid parameters.
     * @throws UnexpectedValueException If the source is not a valid image.
     * @throws RuntimeException If the image cannot be processed or saved.
     */
    public static function thumbnail(string $source, string $target, int $width, int $height, int $quality = 85): void
    {
        self::assertDimensions('thumbnail', $width, $height, $quality);
        $outputType = self::outputType('thumbnail', $target);
        $info = self::validate($source);

        // Zona din sursa, cu proportiile miniaturii, centrata.
        $ratio = $width / $height;
        $cropWidth = $info['width'];
        $cropHeight = (int) round($cropWidth / $ratio);
        if ($cropHeight > $info['height']) {
            $cropHeight = $info['height'];
            $cropWidth = (int) round($cropHeight * $ratio);
        }
        $cropX = intdiv($info['width'] - $cropWidth, 2);
        $cropY = intdiv($info['height'] - $cropHeight, 2);

        self::assertMemory('thumbnail', $info['width'] * $info['height'] + $width * $height);
        $image = self::load('thumbnail', $source, $info['mimeType']);
        $canvas = self::canvas('thumbnail', $width, $height, $outputType);
        imagecopyresampled($canvas, $image, 0, 0, $cropX, $cropY, $width, $height, $cropWidth, $cropHeight);

        self::save('thumbnail', $canvas, $target, $outputType, $quality);
    }

    /**
     * Checks, before decoding, that the remaining memory_limit can hold the decoded images: gd needs about
     * 4 bytes per pixel plus working buffers, and running out of memory is a fatal error no code can catch.
     *
     * @param  int  $pixels  Source pixels + output pixels.
     *
     * @throws RuntimeException If the images would not fit in the available memory.
     */
    private static function assertMemory(string $method, int $pixels): void
    {
        $limit = self::bytes(ini_get('memory_limit'));
        if ($limit <= 0) {
            return; // -1: no limit
        }

        $needed = $pixels * self::BYTES_PER_PIXEL;
        $available = $limit - memory_get_usage();

        if ($needed > $available) {
            throw new RuntimeException(sprintf(
                'Image::%s(): processing this image needs about %d MB, but only %d MB of memory_limit is available; raise memory_limit or lower maxPixels.',
                $method,
                (int) ceil($needed / 1048576),
                max(0, intdiv($available, 1048576)),
            ));
        }
    }

    /** php.ini size notation ("128M", "1G", "-1") to bytes. */
    private static function bytes(string $value): int
    {
        $value = trim($value);
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * @phpstan-assert int<1, max> $width
     * @phpstan-assert int<1, max> $height
     *
     * @throws InvalidArgumentException On invalid dimensions or quality.
     */
    private static function assertDimensions(string $method, int $width, int $height, int $quality): void
    {
        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException("Image::{$method}(): width and height must be at least 1.");
        }

        if ($quality < 0 || $quality > 100) {
            throw new InvalidArgumentException("Image::{$method}(): quality must be between 0 and 100.");
        }
    }

    /**
     * Output MIME type inferred from the target filename extension.
     *
     * @throws InvalidArgumentException If the extension is not supported or directory does not exist.
     */
    private static function outputType(string $method, string $target): string
    {
        $extension = strtolower(pathinfo($target, PATHINFO_EXTENSION));

        if (! isset(self::OUTPUT_TYPES[$extension])) {
            throw new InvalidArgumentException("Image::{$method}(): unsupported target extension '{$extension}' (allowed: ".implode(', ', array_keys(self::OUTPUT_TYPES)).').');
        }

        if (! is_dir(dirname($target))) {
            throw new InvalidArgumentException("Image::{$method}(): directory '".dirname($target)."' does not exist.");
        }

        return self::OUTPUT_TYPES[$extension];
    }

    /**
     * Untyped GdImage return: on PHP 7.4 (dist/php74) gd functions return resources.
     *
     * @return GdImage
     *
     * @throws LogicException If the gd extension or format support is missing.
     * @throws RuntimeException If the image cannot be decoded.
     */
    private static function load(string $method, string $path, string $mimeType)
    {
        $loader = match ($mimeType) {
            'image/jpeg' => 'imagecreatefromjpeg',
            'image/png' => 'imagecreatefrompng',
            'image/gif' => 'imagecreatefromgif',
            'image/webp' => 'imagecreatefromwebp',
            default => throw new UnexpectedValueException("Image::{$method}(): cannot process images of type '{$mimeType}'."),
        };

        if (! function_exists($loader)) {
            throw new LogicException("Image::{$method}(): the PHP gd extension (with {$mimeType} support) is not enabled.");
        }

        /** @var array{GdImage|false, ?string} $loaded */
        $loaded = self::capture(static fn (): mixed => $loader($path));
        [$image, $warning] = $loaded;

        if ($image === false) {
            throw new RuntimeException("Image::{$method}(): cannot decode '{$path}'".($warning === null ? '.' : ": {$warning}."));
        }

        return $image;
    }

    /**
     * New image canvas with transparent background (PNG/GIF/WebP) or white background (JPEG).
     *
     * @param  int<1, max>  $width
     * @param  int<1, max>  $height
     * @return GdImage
     */
    private static function canvas(string $method, int $width, int $height, string $outputType)
    {
        if (! function_exists('imagecreatetruecolor')) {
            throw new LogicException("Image::{$method}(): the PHP gd extension is not enabled.");
        }

        $canvas = imagecreatetruecolor($width, $height);
        if ($canvas === false) {
            throw new RuntimeException("Image::{$method}(): cannot create a {$width}x{$height} image.");
        }

        if ($outputType === 'image/jpeg') {
            imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        } else {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefill($canvas, 0, 0, (int) imagecolorallocatealpha($canvas, 0, 0, 0, 127));
            imagealphablending($canvas, true);
        }

        return $canvas;
    }

    /**
     * @param  GdImage  $image
     *
     * @throws RuntimeException If the image cannot be saved.
     */
    private static function save(string $method, $image, string $target, string $outputType, int $quality): void
    {
        imagesavealpha($image, true);

        [$saved, $warning] = self::capture(static fn (): bool => match ($outputType) {
            'image/jpeg' => imagejpeg($image, $target, $quality),
            'image/png' => imagepng($image, $target, 6),
            'image/gif' => imagegif($image, $target),
            default => imagewebp($image, $target, $quality),
        });

        if (! $saved) {
            throw new RuntimeException("Image::{$method}(): cannot write '{$target}'".($warning === null ? '.' : ": {$warning}."));
        }
    }

    /**
     * Executes a native function and captures any emitted warning instead of displaying it.
     *
     * @template T
     *
     * @param  Closure(): T  $operation  The operation to execute.
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
