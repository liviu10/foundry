<?php

declare(strict_types=1);

namespace Foundry\Tests;

use Foundry\File;
use Foundry\Image;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

final class ImageTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/utilities-image-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDir($this->dir);
    }

    /**
     * Creeaza o imagine de test: jumatatea stanga rosie, dreapta albastra.
     *
     * @param  int<1, max>  $width
     * @param  int<1, max>  $height
     */
    private function image(string $name, int $width, int $height, bool $transparent = false): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, $height - 1, (int) imagecolorallocatealpha($image, 255, 0, 0, $transparent ? 127 : 0));
        imagefilledrectangle($image, intdiv($width, 2), 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 0, 0, 255));

        $path = $this->dir.'/'.$name;
        match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => imagepng($image, $path),
            'gif' => imagegif($image, $path),
            'webp' => imagewebp($image, $path),
            default => imagejpeg($image, $path, 95),
        };

        return $path;
    }

    // info / validate

    public function test_info(): void
    {
        $this->assertSame(['width' => 300, 'height' => 200, 'mimeType' => 'image/png'], Image::info($this->image('a.png', 300, 200)));
        $this->assertSame('image/jpeg', Image::info($this->image('a.jpg', 10, 10))['mimeType']);
        $this->assertSame('image/webp', Image::info($this->image('a.webp', 10, 10))['mimeType']);
    }

    public function test_info_type_comes_from_content_not_extension(): void
    {
        $png = $this->image('real.png', 10, 10);
        rename($png, $this->dir.'/fals.jpg');

        $this->assertSame('image/png', Image::info($this->dir.'/fals.jpg')['mimeType']);
    }

    public function test_info_rejects_non_images(): void
    {
        file_put_contents($this->dir.'/text.jpg', '<?php echo 1;');

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('is not a valid image');

        Image::info($this->dir.'/text.jpg');
    }

    public function test_info_missing_file(): void
    {
        $this->expectException(RuntimeException::class);

        Image::info($this->dir.'/lipsa.png');
    }

    public function test_validate_type(): void
    {
        $gif = $this->image('a.gif', 10, 10);

        $this->assertSame('image/gif', Image::validate($gif)['mimeType']);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("image type 'image/gif' is not allowed");

        Image::validate($gif, ['image/jpeg']);
    }

    public function test_validate_rejects_decompression_bomb_from_header(): void
    {
        $png = $this->image('mic.png', 100, 100);
        // Antetul PNG (IHDR) declara dimensiunile la octetii 16-23; le falsificam la 60000x60000.
        $content = (string) file_get_contents($png);
        $content = substr_replace($content, pack('NN', 60000, 60000), 16, 8);
        file_put_contents($png, $content);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('image is too large (60000x60000 pixels');

        Image::validate($png);
    }

    public function test_resize_refuses_images_that_do_not_fit_in_memory_limit(): void
    {
        // 5000x5000 = 25 MP: sub limita implicita de 40 MP, dar ~125 MB la decodare
        $png = $this->image('mare.png', 100, 100);
        $content = (string) file_get_contents($png);
        file_put_contents($png, substr_replace($content, pack('NN', 5000, 5000), 16, 8));

        $previous = ini_get('memory_limit');
        ini_set('memory_limit', (string) (memory_get_usage() + 32 * 1024 * 1024));

        try {
            Image::resize($png, $this->dir.'/out.jpg', 800, 800);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('of memory_limit is available; raise memory_limit or lower maxPixels', $e->getMessage());
        } finally {
            ini_set('memory_limit', $previous);
        }

        $this->assertFileDoesNotExist($this->dir.'/out.jpg');
    }

    public function test_validate_max_dimensions(): void
    {
        $png = $this->image('a.png', 300, 200);

        $this->assertSame(300, Image::validate($png, maxWidth: 300, maxHeight: 200)['width']);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('exceed the maximum of 299xany');

        Image::validate($png, maxWidth: 299);
    }

    // resize

    public function test_resize_keeps_aspect_ratio(): void
    {
        $result = Image::resize($this->image('a.jpg', 400, 200), $this->dir.'/out.jpg', 100, 100);

        $this->assertSame(['width' => 100, 'height' => 50], $result);
        $this->assertSame(['width' => 100, 'height' => 50, 'mimeType' => 'image/jpeg'], Image::info($this->dir.'/out.jpg'));
    }

    public function test_resize_does_not_upscale(): void
    {
        $result = Image::resize($this->image('a.png', 50, 40), $this->dir.'/out.png', 1000, 1000);

        $this->assertSame(['width' => 50, 'height' => 40], $result);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function conversionProvider(): iterable
    {
        yield 'png -> jpg' => ['a.png', 'image/jpeg'];
        yield 'jpg -> png' => ['a.jpg', 'image/png'];
        yield 'jpg -> webp' => ['a.jpg', 'image/webp'];
        yield 'png -> gif' => ['a.png', 'image/gif'];
    }

    #[DataProvider('conversionProvider')]
    public function test_resize_converts_by_target_extension(string $source, string $expectedType): void
    {
        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$expectedType];

        Image::resize($this->image($source, 40, 40), $this->dir.'/out.'.$extension, 20, 20);

        $this->assertSame($expectedType, Image::info($this->dir.'/out.'.$extension)['mimeType']);
    }

    public function test_resize_keeps_transparency_for_png(): void
    {
        Image::resize($this->image('t.png', 100, 100, transparent: true), $this->dir.'/out.png', 50, 50);

        $image = imagecreatefrompng($this->dir.'/out.png');
        $this->assertNotFalse($image);
        $alpha = (imagecolorat($image, 5, 25) >> 24) & 0x7F;

        $this->assertSame(127, $alpha, 'stanga transparenta ramane transparenta');
    }

    public function test_resize_jpg_from_transparent_png_uses_white_background(): void
    {
        Image::resize($this->image('t.png', 100, 100, transparent: true), $this->dir.'/out.jpg', 50, 50);

        $image = imagecreatefromjpeg($this->dir.'/out.jpg');
        $this->assertNotFalse($image);
        $rgb = imagecolorat($image, 5, 25);

        $this->assertGreaterThan(240, ($rgb >> 16) & 0xFF);
        $this->assertGreaterThan(240, $rgb & 0xFF);
    }

    public function test_resize_rejects_invalid_parameters(): void
    {
        $png = $this->image('a.png', 10, 10);

        foreach ([[0, 10, 85, 'out.png'], [10, 10, 101, 'out.png'], [10, 10, 85, 'out.bmp']] as [$w, $h, $q, $target]) {
            try {
                Image::resize($png, $this->dir.'/'.$target, $w, $h, $q);
                $this->fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_resize_requires_existing_target_directory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        Image::resize($this->image('a.png', 10, 10), $this->dir.'/lipsa/out.png', 5, 5);
    }

    public function test_resize_rejects_corrupted_image(): void
    {
        $jpg = $this->image('a.jpg', 100, 100);
        // Antet valid, continut trunchiat.
        file_put_contents($jpg, substr((string) file_get_contents($jpg), 0, 200));

        $this->expectException(RuntimeException::class);

        Image::resize($jpg, $this->dir.'/out.jpg', 50, 50);
    }

    // thumbnail

    public function test_thumbnail_has_exact_size_and_is_center_cropped(): void
    {
        // 400x100: rosu 0-199, albastru 200-399. Miniatura patrata = zona centrala 150-249.
        Image::thumbnail($this->image('lat.png', 400, 100), $this->dir.'/thumb.png', 50, 50);

        $this->assertSame(['width' => 50, 'height' => 50, 'mimeType' => 'image/png'], Image::info($this->dir.'/thumb.png'));

        $image = imagecreatefrompng($this->dir.'/thumb.png');
        $this->assertNotFalse($image);
        $this->assertSame(255, (imagecolorat($image, 2, 25) >> 16) & 0xFF, 'stanga rosie');
        $this->assertSame(255, imagecolorat($image, 47, 25) & 0xFF, 'dreapta albastra');
    }

    public function test_thumbnail_tall_image(): void
    {
        Image::thumbnail($this->image('inalt.jpg', 100, 400), $this->dir.'/thumb.webp', 80, 40);

        $this->assertSame(['width' => 80, 'height' => 40, 'mimeType' => 'image/webp'], Image::info($this->dir.'/thumb.webp'));
    }
}
