<?php

declare(strict_types=1);

namespace Foundry\Tests;

use Foundry\File;
use Foundry\Zip;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;
use ZipArchive;

final class ZipTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/utilities-zip-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDir($this->dir);
    }

    /**
     * Arhiva construita direct cu ZipArchive, pentru cazuri pe care Zip refuza sa le creeze.
     *
     * @param  array<string, string>  $entries
     */
    private function rawZip(array $entries, string $name = 'raw.zip'): string
    {
        $path = $this->dir.'/'.$name;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        foreach ($entries as $entry => $content) {
            $zip->addFromString($entry, $content);
        }
        $zip->close();

        return $path;
    }

    // create

    public function test_create_from_files_and_directories(): void
    {
        file_put_contents($this->dir.'/raport.pdf', 'pdf');
        file_put_contents($this->dir.'/contract.txt', 'contract');
        mkdir($this->dir.'/docs/sub', 0777, true);
        file_put_contents($this->dir.'/docs/a.txt', 'a');
        file_put_contents($this->dir.'/docs/sub/b.txt', 'b');

        Zip::create($this->dir.'/out.zip', [
            $this->dir.'/raport.pdf',
            'anexe/contract.txt' => $this->dir.'/contract.txt',
            'documente' => $this->dir.'/docs',
        ]);

        $names = array_column(Zip::list($this->dir.'/out.zip'), 'name');
        sort($names);

        $this->assertSame(['anexe/contract.txt', 'documente/a.txt', 'documente/sub/b.txt', 'raport.pdf'], $names);
    }

    public function test_create_refuses_to_overwrite_by_default(): void
    {
        file_put_contents($this->dir.'/a.txt', 'a');
        Zip::create($this->dir.'/out.zip', [$this->dir.'/a.txt']);

        try {
            Zip::create($this->dir.'/out.zip', [$this->dir.'/a.txt']);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('already exists', $e->getMessage());
        }

        file_put_contents($this->dir.'/b.txt', 'b');
        Zip::create($this->dir.'/out.zip', [$this->dir.'/b.txt'], overwrite: true);

        $this->assertSame(['b.txt'], array_column(Zip::list($this->dir.'/out.zip'), 'name'));
    }

    public function test_create_rejects_missing_file_before_writing(): void
    {
        try {
            Zip::create($this->dir.'/out.zip', [$this->dir.'/lipsa.txt']);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('does not exist', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->dir.'/out.zip');
    }

    public function test_create_rejects_empty_list(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Zip::create($this->dir.'/out.zip', []);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeNameProvider(): iterable
    {
        yield 'parinte' => ['../evil.txt'];
        yield 'parinte in mijloc' => ['a/../../evil.txt'];
        yield 'absolut' => ['/etc/passwd'];
        yield 'backslash' => ['..\\evil.txt'];
        yield 'disc windows' => ['C:/Windows/evil.txt'];
        yield 'gol' => [''];
        yield 'caracter de control' => ["a\x00.txt"];
    }

    #[DataProvider('unsafeNameProvider')]
    public function test_create_from_strings_rejects_unsafe_names(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unsafe entry name');

        Zip::createFromStrings($this->dir.'/out.zip', [$name => 'x']);
    }

    public function test_create_requires_existing_target_directory(): void
    {
        $this->expectException(RuntimeException::class);

        Zip::createFromStrings($this->dir.'/lipsa/out.zip', ['a.txt' => 'a']);
    }

    // createFromStrings + extract

    public function test_round_trip(): void
    {
        Zip::createFromStrings($this->dir.'/out.zip', ['date.csv' => "a;b\n1;2", 'sub/info.txt' => 'info']);
        mkdir($this->dir.'/extract');

        $names = Zip::extract($this->dir.'/out.zip', $this->dir.'/extract');

        $this->assertSame(['date.csv', 'sub/info.txt'], $names);
        $this->assertSame("a;b\n1;2", file_get_contents($this->dir.'/extract/date.csv'));
        $this->assertSame('info', file_get_contents($this->dir.'/extract/sub/info.txt'));
    }

    public function test_list_reports_sizes(): void
    {
        Zip::createFromStrings($this->dir.'/out.zip', ['a.txt' => str_repeat('a', 1000)]);

        $entry = Zip::list($this->dir.'/out.zip')[0];

        $this->assertSame('a.txt', $entry['name']);
        $this->assertSame(1000, $entry['size']);
        $this->assertLessThan(1000, $entry['compressedSize']);
    }

    // extract: protectii

    public function test_extract_rejects_path_traversal_and_writes_nothing(): void
    {
        $zip = $this->rawZip(['ok.txt' => 'ok', '../evil.txt' => 'evil']);
        mkdir($this->dir.'/extract');

        try {
            Zip::extract($zip, $this->dir.'/extract');
            $this->fail('Expected UnexpectedValueException');
        } catch (UnexpectedValueException $e) {
            $this->assertStringContainsString('unsafe entry name (entry #1)', $e->getMessage());
            $this->assertStringNotContainsString('evil', $e->getMessage(), 'numele intrarilor sunt date ale utilizatorului si nu apar in mesaje');
        }

        $this->assertFileDoesNotExist($this->dir.'/evil.txt');
        $this->assertFileDoesNotExist($this->dir.'/extract/ok.txt', 'nicio intrare nu se extrage daca una e periculoasa');
    }

    public function test_extract_rejects_symlinks(): void
    {
        $path = $this->rawZip(['link' => '/etc/passwd']);
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->setExternalAttributesName('link', ZipArchive::OPSYS_UNIX, (0xA000 | 0777) << 16);
        $zip->close();
        mkdir($this->dir.'/extract');

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('symbolic link entry (entry #0)');

        Zip::extract($path, $this->dir.'/extract');
    }

    public function test_extract_enforces_max_bytes(): void
    {
        Zip::createFromStrings($this->dir.'/bomb.zip', ['a.txt' => str_repeat('0', 10000), 'b.txt' => str_repeat('0', 10000)]);
        mkdir($this->dir.'/extract');

        try {
            Zip::extract($this->dir.'/bomb.zip', $this->dir.'/extract', maxBytes: 15000);
            $this->fail('Expected UnexpectedValueException');
        } catch (UnexpectedValueException $e) {
            $this->assertStringContainsString('exceeds the maximum extracted size of 15000 bytes', $e->getMessage());
        }

        $this->assertSame([], array_diff(scandir($this->dir.'/extract') ?: [], ['.', '..']));
        $this->assertCount(2, Zip::extract($this->dir.'/bomb.zip', $this->dir.'/extract', maxBytes: 20000));
    }

    /**
     * Arhiva cu o intrare care contine $realBytes, dar declara in ambele antete (local + central) doar $declared.
     */
    private function tamperedZip(int $realBytes, int $declared): string
    {
        $path = $this->rawZip(['ok.txt' => 'ok', 'big.txt' => str_repeat('A', $realBytes)], 'tampered.zip');
        $bin = (string) file_get_contents($path);

        foreach (["PK\x03\x04" => 22, "PK\x01\x02" => 24] as $signature => $sizeOffset) {
            $offset = 0;
            while (($pos = strpos($bin, $signature, $offset)) !== false) {
                $header = unpack('vlength', substr($bin, $pos + ($signature === "PK\x03\x04" ? 26 : 28), 2));
                $this->assertIsArray($header);
                $this->assertIsInt($header['length']);
                $nameLength = $header['length'];
                $nameStart = $pos + ($signature === "PK\x03\x04" ? 30 : 46);
                if (substr($bin, $nameStart, $nameLength) === 'big.txt') {
                    $bin = substr_replace($bin, pack('V', $declared), $pos + $sizeOffset, 4);
                }
                $offset = $pos + 4;
            }
        }
        file_put_contents($path, $bin);

        return $path;
    }

    public function test_extract_counts_real_bytes_not_declared_sizes(): void
    {
        $zip = $this->tamperedZip(1_000_000, 10);
        mkdir($this->dir.'/extract');

        try {
            Zip::extract($zip, $this->dir.'/extract', maxBytes: 1000);
            $this->fail('Expected UnexpectedValueException');
        } catch (UnexpectedValueException $e) {
            $this->assertStringContainsString('entry #1', $e->getMessage());
            $this->assertStringContainsString('larger than its declared size', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->dir.'/extract/big.txt', 'fisierul partial este sters');
        $this->assertFileDoesNotExist($this->dir.'/extract/ok.txt', 'si intrarile deja scrise in acelasi apel');
    }

    public function test_extract_rejects_tampered_sizes_even_without_max_bytes(): void
    {
        $zip = $this->tamperedZip(50_000, 100);
        mkdir($this->dir.'/extract');

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('larger than its declared size');

        Zip::extract($zip, $this->dir.'/extract');
    }

    public function test_extract_enforces_max_entries(): void
    {
        Zip::createFromStrings($this->dir.'/many.zip', ['a.txt' => 'a', 'b.txt' => 'b', 'c.txt' => 'c']);
        mkdir($this->dir.'/extract');

        try {
            Zip::extract($this->dir.'/many.zip', $this->dir.'/extract', maxEntries: 2);
            $this->fail('Expected UnexpectedValueException');
        } catch (UnexpectedValueException $e) {
            $this->assertStringContainsString('has 3 entries, more than the maximum of 2', $e->getMessage());
        }

        $this->assertSame([], array_diff(scandir($this->dir.'/extract') ?: [], ['.', '..']));
        $this->assertCount(3, Zip::extract($this->dir.'/many.zip', $this->dir.'/extract', maxEntries: 3));
    }

    public function test_extract_creates_nested_directories_and_keeps_content(): void
    {
        Zip::createFromStrings($this->dir.'/nested.zip', ['a/b/c.txt' => 'deep', 'root.txt' => 'root']);
        mkdir($this->dir.'/extract');

        $names = Zip::extract($this->dir.'/nested.zip', $this->dir.'/extract', maxBytes: 100, maxEntries: 10);

        $this->assertSame(['a/b/c.txt', 'root.txt'], $names);
        $this->assertSame('deep', file_get_contents($this->dir.'/extract/a/b/c.txt'));
        $this->assertSame('root', file_get_contents($this->dir.'/extract/root.txt'));
    }

    public function test_extract_rejects_invalid_max_entries(): void
    {
        Zip::createFromStrings($this->dir.'/out.zip', ['a.txt' => 'a']);
        mkdir($this->dir.'/extract');

        $this->expectException(LogicException::class);

        Zip::extract($this->dir.'/out.zip', $this->dir.'/extract', maxEntries: 0);
    }

    public function test_extract_requires_existing_directory(): void
    {
        Zip::createFromStrings($this->dir.'/out.zip', ['a.txt' => 'a']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        Zip::extract($this->dir.'/out.zip', $this->dir.'/lipsa');
    }

    // erori la citire

    public function test_not_a_zip(): void
    {
        file_put_contents($this->dir.'/fals.zip', 'nu sunt arhiva');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a zip archive');

        Zip::list($this->dir.'/fals.zip');
    }

    public function test_missing_archive(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        Zip::list($this->dir.'/lipsa.zip');
    }
}
