<?php

declare(strict_types=1);

namespace Foundry\Tests;

use Foundry\Upload;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * is_uploaded_file() / move_uploaded_file() functioneaza doar in cereri HTTP reale.
 * In CLI se testeaza complet validate() si faptul ca store() refuza fisierele neincarcate.
 */
final class UploadTest extends TestCase
{
    private const array ALLOWED = ['application/pdf' => 'pdf', 'image/png' => 'png'];

    private const string PDF = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/utilities-upload-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (array_diff(scandir($this->dir) ?: [], ['.', '..']) as $file) {
            unlink($this->dir.'/'.$file);
        }
        rmdir($this->dir);
    }

    /**
     * Simuleaza un element din $_FILES.
     *
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
     */
    private function upload(string $content, string $name = 'factura.pdf', int $error = UPLOAD_ERR_OK, string $type = 'application/pdf'): array
    {
        $tmp = $this->dir.'/php'.bin2hex(random_bytes(4)).'.tmp';
        file_put_contents($tmp, $content);

        return ['name' => $name, 'type' => $type, 'tmp_name' => $tmp, 'error' => $error, 'size' => strlen($content)];
    }

    // validate

    public function test_validate_accepts_allowed_file(): void
    {
        $file = $this->upload(self::PDF, 'C:\\Users\\ion\\Factura 2026.pdf');

        $result = Upload::validate($file, self::ALLOWED, 1024);

        $this->assertSame('application/pdf', $result['mimeType']);
        $this->assertSame('pdf', $result['extension']);
        $this->assertSame(strlen(self::PDF), $result['size']);
        $this->assertSame('Factura 2026.pdf', $result['originalName']);
    }

    public function test_extension_comes_from_detected_type_not_from_client_name(): void
    {
        $file = $this->upload(self::PDF, 'atac.php');

        $this->assertSame('pdf', Upload::validate($file, self::ALLOWED, 1024)['extension']);
    }

    public function test_php_disguised_as_pdf_is_rejected(): void
    {
        $file = $this->upload('<?php system($_GET["c"]);', 'factura.pdf', type: 'application/pdf');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not allowed (allowed: application/pdf, image/png)');

        Upload::validate($file, self::ALLOWED, 1024);
    }

    public function test_size_is_measured_on_disk_not_taken_from_request(): void
    {
        $file = $this->upload(self::PDF);
        $file['size'] = 10; // valoare falsa trimisa de client

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the file is too large');

        Upload::validate($file, self::ALLOWED, 50);
    }

    public function test_empty_file_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the uploaded file is empty');

        Upload::validate($this->upload(''), self::ALLOWED, 1024);
    }

    /**
     * @return iterable<string, array{int, class-string<\Throwable>, string}>
     */
    public static function uploadErrorProvider(): iterable
    {
        yield 'ini size' => [UPLOAD_ERR_INI_SIZE, InvalidArgumentException::class, 'upload_max_filesize'];
        yield 'form size' => [UPLOAD_ERR_FORM_SIZE, InvalidArgumentException::class, 'MAX_FILE_SIZE'];
        yield 'partial' => [UPLOAD_ERR_PARTIAL, InvalidArgumentException::class, 'partially uploaded'];
        yield 'no file' => [UPLOAD_ERR_NO_FILE, InvalidArgumentException::class, 'no file was uploaded'];
        yield 'no tmp dir' => [UPLOAD_ERR_NO_TMP_DIR, RuntimeException::class, 'temporary upload directory'];
        yield 'cant write' => [UPLOAD_ERR_CANT_WRITE, RuntimeException::class, 'could not write'];
        yield 'extension' => [UPLOAD_ERR_EXTENSION, RuntimeException::class, 'PHP extension'];
        yield 'necunoscut' => [99, InvalidArgumentException::class, 'unknown upload error code 99'];
    }

    /**
     * @param  class-string<\Throwable>  $exception
     */
    #[DataProvider('uploadErrorProvider')]
    public function test_upload_error_codes(int $error, string $exception, string $message): void
    {
        $this->expectException($exception);
        $this->expectExceptionMessage($message);

        Upload::validate($this->upload(self::PDF, error: $error), self::ALLOWED, 1024);
    }

    public function test_missing_temporary_file(): void
    {
        $file = $this->upload(self::PDF);
        unlink($file['tmp_name']);

        $this->expectException(RuntimeException::class);

        Upload::validate($file, self::ALLOWED, 1024);
    }

    /**
     * @return iterable<string, array{array<mixed>, int, string}>
     */
    public static function invalidConfigurationProvider(): iterable
    {
        yield 'lista goala' => [[], 1024, 'cannot be empty'];
        yield 'extensie cu punct' => [['application/pdf' => '.pdf'], 1024, "invalid extension '.pdf'"];
        yield 'extensie periculoasa ca format' => [['application/pdf' => 'p h p'], 1024, 'invalid extension'];
        yield 'dimensiune zero' => [['application/pdf' => 'pdf'], 0, '$maxSize must be greater than 0'];
    }

    /**
     * @param  array<string, string>  $allowed
     */
    #[DataProvider('invalidConfigurationProvider')]
    public function test_invalid_configuration_is_a_logic_error(array $allowed, int $maxSize, string $message): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($message);

        Upload::validate($this->upload(self::PDF), $allowed, $maxSize);
    }

    public function test_invalid_files_structure_is_a_logic_error(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("missing or invalid 'tmp_name'");

        Upload::validate(['name' => 'a.pdf', 'error' => 0, 'size' => 1], self::ALLOWED, 1024);
    }

    public function test_multiple_field_passed_to_validate_is_a_logic_error(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('use Upload::files()');

        Upload::validate(['name' => ['a.pdf'], 'tmp_name' => ['x'], 'error' => [0], 'size' => [1]], self::ALLOWED, 1024);
    }

    public function test_original_name_is_cleaned(): void
    {
        $file = $this->upload(self::PDF, "../../etc/pass\x00wd\x1F.pdf");

        $this->assertSame('passwd.pdf', Upload::validate($file, self::ALLOWED, 1024)['originalName']);
    }

    // store

    public function test_store_refuses_files_not_uploaded_via_post(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the file was not uploaded via HTTP POST');

        Upload::store($this->upload(self::PDF), $this->dir, self::ALLOWED, 1024);
    }

    public function test_store_validates_before_checking_upload_origin(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not allowed');

        Upload::store($this->upload('text simplu'), $this->dir, self::ALLOWED, 1024);
    }

    public function test_store_requires_existing_directory(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist or is not writable');

        Upload::store($this->upload(self::PDF), $this->dir.'/lipsa', self::ALLOWED, 1024);
    }

    public function test_store_rejects_unsafe_name(): void
    {
        $this->expectException(LogicException::class);

        Upload::store($this->upload(self::PDF), $this->dir, self::ALLOWED, 1024, name: '../factura');
    }

    // files

    public function test_files_normalizes_multiple_upload_and_skips_empty_inputs(): void
    {
        $a = $this->upload(self::PDF, 'a.pdf');
        $b = $this->upload(self::PDF, 'b.pdf');

        $field = [
            'name' => ['a.pdf', '', 'b.pdf'],
            'type' => ['application/pdf', '', 'application/pdf'],
            'tmp_name' => [$a['tmp_name'], '', $b['tmp_name']],
            'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE, UPLOAD_ERR_OK],
            'size' => [$a['size'], 0, $b['size']],
        ];

        $files = Upload::files($field);

        $this->assertCount(2, $files);
        $this->assertSame('a.pdf', $files[0]['name']);
        $this->assertSame($b['tmp_name'], $files[1]['tmp_name']);
        $this->assertSame('pdf', Upload::validate($files[1], self::ALLOWED, 1024)['extension']);
    }

    public function test_files_accepts_single_field(): void
    {
        $this->assertCount(1, Upload::files($this->upload(self::PDF)));
        $this->assertSame([], Upload::files($this->upload('', error: UPLOAD_ERR_NO_FILE)));
    }

    public function test_files_rejects_inconsistent_structure(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("missing 'tmp_name' for file #1");

        Upload::files(['name' => ['a', 'b'], 'type' => ['', ''], 'tmp_name' => ['x'], 'error' => [0, 0], 'size' => [1, 1]]);
    }

    // maxServerSize

    public function test_max_server_size_reads_php_ini(): void
    {
        $expected = min(array_filter([
            $this->bytes((string) ini_get('upload_max_filesize')),
            $this->bytes((string) ini_get('post_max_size')),
        ]) ?: [0]);

        $this->assertSame($expected, Upload::maxServerSize());
    }

    private function bytes(string $value): int
    {
        $units = ['K' => 1024, 'M' => 1024 ** 2, 'G' => 1024 ** 3];

        return (int) $value * ($units[strtoupper(substr($value, -1))] ?? 1);
    }
}
