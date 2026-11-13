<?php

declare(strict_types=1);

namespace Foundry\Tests;

use Foundry\File;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/utilities-file-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            File::deleteDir($this->dir);
        }
    }

    // read

    public function test_read(): void
    {
        file_put_contents($this->dir.'/a.txt', "linia 1\nlinia 2");

        $this->assertSame("linia 1\nlinia 2", File::read($this->dir.'/a.txt'));
    }

    public function test_read_empty_file_returns_empty_string(): void
    {
        touch($this->dir.'/empty.txt');

        $this->assertSame('', File::read($this->dir.'/empty.txt'));
    }

    public function test_read_throws_if_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("File::read() failed for '{$this->dir}/missing.txt': file does not exist.");

        File::read($this->dir.'/missing.txt');
    }

    public function test_read_throws_on_directory(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('path is a directory, not a file');

        File::read($this->dir);
    }

    public function test_empty_path_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('File::read(): path cannot be empty.');

        File::read(' ');
    }

    // lines

    public function test_lines_yields_numbered_lines_without_line_endings(): void
    {
        file_put_contents($this->dir.'/a.txt', "unu\r\ndoi\n\ntrei");

        $this->assertSame([1 => 'unu', 2 => 'doi', 3 => '', 4 => 'trei'], iterator_to_array(File::lines($this->dir.'/a.txt')));
    }

    public function test_lines_on_empty_file(): void
    {
        touch($this->dir.'/empty.txt');

        $this->assertSame([], iterator_to_array(File::lines($this->dir.'/empty.txt')));
    }

    public function test_lines_fails_immediately_not_on_first_iteration(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('file does not exist');

        File::lines($this->dir.'/missing.txt'); // fara iterare
    }

    // write

    public function test_write_creates_file(): void
    {
        File::write($this->dir.'/a.txt', 'continut');

        $this->assertSame('continut', file_get_contents($this->dir.'/a.txt'));
    }

    public function test_write_overwrites_existing_file(): void
    {
        file_put_contents($this->dir.'/a.txt', 'vechi si mai lung');

        File::write($this->dir.'/a.txt', 'nou');

        $this->assertSame('nou', file_get_contents($this->dir.'/a.txt'));
    }

    public function test_write_empty_content(): void
    {
        File::write($this->dir.'/a.txt', '');

        $this->assertSame('', file_get_contents($this->dir.'/a.txt'));
    }

    public function test_write_leaves_no_temporary_files(): void
    {
        File::write($this->dir.'/a.txt', 'x');
        File::write($this->dir.'/a.txt', 'y');

        $this->assertSame([$this->dir.DIRECTORY_SEPARATOR.'a.txt'], File::files($this->dir));
    }

    public function test_write_throws_if_directory_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("directory '{$this->dir}/missing' does not exist");

        File::write($this->dir.'/missing/a.txt', 'x');
    }

    public function test_write_throws_if_path_is_directory(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('path is a directory');

        File::write($this->dir, 'x');
    }

    // append

    public function test_append_creates_and_appends(): void
    {
        File::append($this->dir.'/log.txt', "a\n");
        File::append($this->dir.'/log.txt', "b\n");

        $this->assertSame("a\nb\n", file_get_contents($this->dir.'/log.txt'));
    }

    public function test_append_throws_if_directory_missing(): void
    {
        $this->expectException(RuntimeException::class);

        File::append($this->dir.'/missing/log.txt', 'x');
    }

    // delete / deleteIfExists

    public function test_delete(): void
    {
        touch($this->dir.'/a.txt');

        File::delete($this->dir.'/a.txt');

        $this->assertFileDoesNotExist($this->dir.'/a.txt');
    }

    public function test_delete_throws_if_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('file does not exist');

        File::delete($this->dir.'/missing.txt');
    }

    public function test_delete_refuses_directories(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('path is a directory');

        File::delete($this->dir);
    }

    public function test_delete_if_exists(): void
    {
        touch($this->dir.'/a.txt');

        $this->assertTrue(File::deleteIfExists($this->dir.'/a.txt'));
        $this->assertFalse(File::deleteIfExists($this->dir.'/a.txt'));
    }

    // copy / move

    public function test_copy(): void
    {
        file_put_contents($this->dir.'/a.txt', 'x');

        File::copy($this->dir.'/a.txt', $this->dir.'/b.txt');

        $this->assertSame('x', file_get_contents($this->dir.'/a.txt'));
        $this->assertSame('x', file_get_contents($this->dir.'/b.txt'));
    }

    public function test_copy_throws_if_source_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('File::copy()');

        File::copy($this->dir.'/missing.txt', $this->dir.'/b.txt');
    }

    public function test_copy_throws_if_target_directory_missing(): void
    {
        touch($this->dir.'/a.txt');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        File::copy($this->dir.'/a.txt', $this->dir.'/missing/b.txt');
    }

    public function test_move_overwrites_target(): void
    {
        file_put_contents($this->dir.'/a.txt', 'nou');
        file_put_contents($this->dir.'/b.txt', 'vechi');

        File::move($this->dir.'/a.txt', $this->dir.'/b.txt');

        $this->assertFileDoesNotExist($this->dir.'/a.txt');
        $this->assertSame('nou', file_get_contents($this->dir.'/b.txt'));
    }

    public function test_move_throws_if_source_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('File::move()');

        File::move($this->dir.'/missing.txt', $this->dir.'/b.txt');
    }

    // ensureDir / deleteDir

    public function test_ensure_dir_creates_nested_directories_and_is_idempotent(): void
    {
        File::ensureDir($this->dir.'/a/b/c');
        File::ensureDir($this->dir.'/a/b/c');

        $this->assertDirectoryExists($this->dir.'/a/b/c');
    }

    public function test_ensure_dir_throws_if_file_with_same_name_exists(): void
    {
        touch($this->dir.'/a');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('path exists but is not a directory');

        File::ensureDir($this->dir.'/a');
    }

    public function test_delete_dir_removes_everything(): void
    {
        mkdir($this->dir.'/sub/deep', 0777, true);
        touch($this->dir.'/sub/a.txt');
        touch($this->dir.'/sub/deep/b.txt');

        File::deleteDir($this->dir.'/sub');

        $this->assertDirectoryDoesNotExist($this->dir.'/sub');
    }

    public function test_delete_dir_throws_if_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('directory does not exist');

        File::deleteDir($this->dir.'/missing');
    }

    public function test_delete_dir_refuses_filesystem_root(): void
    {
        $root = PHP_OS_FAMILY === 'Windows' ? substr((string) realpath($this->dir), 0, 3) : '/';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('refusing to delete a filesystem root');

        File::deleteDir($root);
    }

    // files

    public function test_files_lists_sorted_files_only(): void
    {
        touch($this->dir.'/b.csv');
        touch($this->dir.'/a.txt');
        mkdir($this->dir.'/sub');
        touch($this->dir.'/sub/c.csv');

        $this->assertSame(['a.txt', 'b.csv'], array_map(basename(...), File::files($this->dir)));
    }

    public function test_files_recursive_with_extension_filter(): void
    {
        touch($this->dir.'/a.CSV');
        touch($this->dir.'/b.txt');
        mkdir($this->dir.'/sub');
        touch($this->dir.'/sub/c.csv');

        $files = array_map(basename(...), File::files($this->dir, recursive: true, extension: '.csv'));
        sort($files);

        $this->assertSame(['a.CSV', 'c.csv'], $files);
    }

    public function test_files_throws_if_directory_missing(): void
    {
        $this->expectException(RuntimeException::class);

        File::files($this->dir.'/missing');
    }

    // size / mimeType / tempFile

    public function test_size(): void
    {
        file_put_contents($this->dir.'/a.txt', "Ia\u{0219}i"); // 5 bytes in UTF-8 (s cu virgula = 2 bytes)

        $this->assertSame(5, File::size($this->dir.'/a.txt'));
    }

    public function test_mime_type_is_detected_from_content_not_extension(): void
    {
        file_put_contents($this->dir.'/fake.pdf', 'doar text');
        file_put_contents($this->dir.'/real.bin', "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<<>>\nendobj\n");

        $this->assertSame('text/plain', File::mimeType($this->dir.'/fake.pdf'));
        $this->assertSame('application/pdf', File::mimeType($this->dir.'/real.bin'));
    }

    public function test_temp_file(): void
    {
        $path = File::tempFile('test', $this->dir);

        $this->assertFileExists($path);
        $this->assertStringStartsWith('tes', basename($path)); // Windows pastreaza doar 3 caractere din prefix
    }

    public function test_temp_file_throws_if_directory_missing(): void
    {
        $this->expectException(RuntimeException::class);

        File::tempFile('x', $this->dir.'/missing');
    }

    // copyStream / writeAll

    public function test_copy_stream_writes_whole_stream(): void
    {
        $source = fopen('php://temp', 'w+b');
        $this->assertNotFalse($source);
        fwrite($source, str_repeat('abc', 100000));
        rewind($source);

        $bytes = File::copyStream($source, $this->dir.'/out.bin');
        fclose($source);

        $this->assertSame(300000, $bytes);
        $this->assertSame(str_repeat('abc', 100000), file_get_contents($this->dir.'/out.bin'));
        $this->assertSame([$this->dir.DIRECTORY_SEPARATOR.'out.bin'], File::files($this->dir), 'fara fisiere temporare ramase');
    }

    public function test_copy_stream_overwrites_atomically(): void
    {
        file_put_contents($this->dir.'/out.txt', 'vechi');
        $source = fopen('php://memory', 'w+b');
        $this->assertNotFalse($source);
        fwrite($source, 'nou');
        rewind($source);

        File::copyStream($source, $this->dir.'/out.txt');
        fclose($source);

        $this->assertSame('nou', file_get_contents($this->dir.'/out.txt'));
    }

    public function test_copy_stream_rejects_non_streams_and_missing_directory(): void
    {
        $source = fopen('php://memory', 'rb');
        $this->assertNotFalse($source);

        try {
            File::copyStream($source, $this->dir.'/lipsa/out.txt');
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('does not exist', $e->getMessage());
        } finally {
            fclose($source);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('$source must be an open stream resource');

        File::copyStream($source, $this->dir.'/out.txt'); // stream inchis
    }

    public function test_write_all(): void
    {
        $handle = fopen($this->dir.'/out.txt', 'wb');
        $this->assertNotFalse($handle);

        File::writeAll($handle, str_repeat('x', 1000000));
        File::writeAll($handle, '');
        fclose($handle);

        $this->assertSame(1000000, filesize($this->dir.'/out.txt'));
    }

    public function test_write_all_fails_on_read_only_stream(): void
    {
        file_put_contents($this->dir.'/a.txt', 'x');
        $handle = fopen($this->dir.'/a.txt', 'rb');
        $this->assertNotFalse($handle);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('0 of 3 bytes written');

            File::writeAll($handle, 'abc');
        } finally {
            fclose($handle);
        }
    }

    // glob / realpath / touch / chmod / assertWritable

    public function test_glob(): void
    {
        touch($this->dir.'/app-1.log');
        touch($this->dir.'/app-2.log');
        touch($this->dir.'/other.txt');

        $this->assertSame(['app-1.log', 'app-2.log'], array_map(basename(...), File::glob($this->dir.'/app-*.log')));
        $this->assertSame([], File::glob($this->dir.'/nimic-*.log'));
    }

    public function test_realpath(): void
    {
        mkdir($this->dir.'/sub');

        $this->assertSame((string) realpath($this->dir), File::realpath($this->dir.'/sub/..'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('path does not exist');

        File::realpath($this->dir.'/lipsa');
    }

    public function test_touch_creates_and_updates_mtime(): void
    {
        File::touch($this->dir.'/a.txt');
        $this->assertFileExists($this->dir.'/a.txt');

        File::touch($this->dir.'/a.txt', 1_000_000_000);
        clearstatcache();

        $this->assertSame(1_000_000_000, filemtime($this->dir.'/a.txt'));
    }

    public function test_touch_requires_existing_directory(): void
    {
        $this->expectException(RuntimeException::class);

        File::touch($this->dir.'/lipsa/a.txt');
    }

    public function test_chmod_read_only_and_back(): void
    {
        file_put_contents($this->dir.'/a.txt', 'x');

        File::chmod($this->dir.'/a.txt', 0444);
        clearstatcache();
        $this->assertIsNotWritable($this->dir.'/a.txt');

        File::chmod($this->dir.'/a.txt', 0666);
        clearstatcache();
        $this->assertIsWritable($this->dir.'/a.txt');
    }

    public function test_chmod_validation(): void
    {
        try {
            File::chmod($this->dir.'/lipsa.txt', 0644);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('path does not exist', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid mode 10000');

        File::chmod($this->dir, 010000);
    }

    public function test_assert_writable(): void
    {
        file_put_contents($this->dir.'/a.txt', 'x');

        File::assertWritable($this->dir);
        File::assertWritable($this->dir.'/a.txt');
        File::assertWritable($this->dir.'/nou.txt');

        chmod($this->dir.'/a.txt', 0444);

        try {
            File::assertWritable($this->dir.'/a.txt');
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('file is not writable', $e->getMessage());
        } finally {
            chmod($this->dir.'/a.txt', 0666);
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("directory '{$this->dir}/lipsa' does not exist");

        File::assertWritable($this->dir.'/lipsa/nou.txt');
    }

    // join

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function joinProvider(): iterable
    {
        yield 'simple' => [['a', 'b', 'c.txt'], 'a/b/c.txt'];
        yield 'duplicate separators' => [['/var/www/', '/storage', 'logs/'], '/var/www/storage/logs'];
        yield 'windows' => [['C:\\xampp\\', '\\htdocs'], 'C:\\xampp/htdocs'];
        yield 'empty segments skipped' => [['a', '', '/', 'b'], 'a/b'];
        yield 'root only' => [['/'], '/'];
    }

    /**
     * @param  list<string>  $parts
     */
    #[DataProvider('joinProvider')]
    public function test_join(array $parts, string $expected): void
    {
        $this->assertSame($expected, File::join(...$parts));
    }

    public function test_join_throws_without_segments(): void
    {
        $this->expectException(InvalidArgumentException::class);

        File::join('', '');
    }

    // integrare cu error handler-ul gazdei

    public function test_warnings_are_not_leaked_to_host_error_handler(): void
    {
        $leaked = [];
        set_error_handler(static function (int $severity, string $message) use (&$leaked): bool {
            $leaked[] = $message;

            return true;
        });

        try {
            File::copy(__FILE__, $this->dir); // destinatia e un director: copy() emite warning
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('File::copy() failed for', $e->getMessage());
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $leaked);
    }
}
