<?php

declare(strict_types=1);

namespace Foundry\Tests;

use DateTimeImmutable;
use Foundry\Csv;
use Foundry\File;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

final class CsvTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/utilities-csv-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDir($this->dir);
    }

    private function file(string $name, string $content): string
    {
        $path = $this->dir.'/'.$name;
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function all(string $path, ?string $delimiter = null): array
    {
        return iterator_to_array(Csv::read($path, $delimiter));
    }

    /**
     * Toate loturile importului, unite ca un singur rezultat.
     *
     * @param  array<array-key, string>  $files
     * @param  array<string, array<string, mixed>>  $schema
     * @param  array{delimiter?: string|null, fallbackEncoding?: string, allowExtraColumns?: bool, maxErrors?: int, uniqueFile?: string|null, maxUnique?: int}  $options
     * @return array{valid: bool, rows: list<array{file: string, row: int, data: array<string, mixed>}>, errors: list<array{file: string, row: int|null, column: string|null, code: string, message: string}>, files: array<string, array{status: string, rows: int, valid: int, errors: int}>}
     */
    private function importAll(array $files, array $schema, array $options = []): array
    {
        $result = ['valid' => false, 'rows' => [], 'errors' => [], 'files' => []];
        foreach (Csv::import($files, $schema, $options, 2) as $batch) {
            array_push($result['rows'], ...$batch['rows']);
            array_push($result['errors'], ...$batch['errors']);
            $result['files'] = $batch['files'];
            $result['valid'] = $batch['valid'];
        }

        return $result;
    }

    // citire

    public function test_read_excel_romanian_file(): void
    {
        $path = $this->file('a.csv', "\xEF\xBB\xBFnume;suma;oras\r\nIon;1.234,50;Ia\u{0219}i\r\nAna;10;Cluj\r\n");

        $this->assertSame([
            2 => ['nume' => 'Ion', 'suma' => '1.234,50', 'oras' => "Ia\u{0219}i"],
            3 => ['nume' => 'Ana', 'suma' => '10', 'oras' => 'Cluj'],
        ], $this->all($path));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function delimiterProvider(): iterable
    {
        yield 'virgula' => ["a,b\n1,2\n"];
        yield 'punct si virgula' => ["a;b\n1;2\n"];
        yield 'tab' => ["a\tb\n1\t2\n"];
        yield 'pipe' => ["a|b\n1|2\n"];
        yield 'separator in ghilimele ignorat' => ["\"a;x\",b\n1,2\n"];
    }

    #[DataProvider('delimiterProvider')]
    public function test_delimiter_detection(string $content): void
    {
        $rows = $this->all($this->file('d.csv', $content));

        $this->assertSame(['1', '2'], array_values($rows[2]));
    }

    public function test_quoted_fields_multiline_and_backslash(): void
    {
        $path = $this->file('q.csv', "id,text\n1,\"linia 1\nlinia 2\"\n2,\"spune \"\"da\"\"\"\n3,\"cale C:\\dir\\\"\n");

        $this->assertSame([
            2 => ['id' => '1', 'text' => "linia 1\nlinia 2"],
            3 => ['id' => '2', 'text' => 'spune "da"'],
            4 => ['id' => '3', 'text' => 'cale C:\\dir\\'],
        ], $this->all($path));
    }

    public function test_windows_1250_file_is_converted(): void
    {
        // "Iasi" cu s-sedila si "Tara" cu t-sedila in Windows-1250 (0xBA, 0xDE).
        $path = $this->file('w.csv', "oras;tara\r\nIa\xBAi;\xDEara\r\n");

        $this->assertSame([2 => ['oras' => "Ia\u{015F}i", 'tara' => "\u{0162}ara"]], $this->all($path));
    }

    public function test_bytes_undefined_in_fallback_encoding_are_an_error(): void
    {
        $path = $this->file('u.csv', "a\nx\x81y\n");

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("row 2 of '{$path}' contains bytes that are not valid Windows-1250.");

        $this->all($path);
    }

    public function test_unsupported_fallback_encoding(): void
    {
        $path = $this->file('u.csv', "a\n1\n");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Csv::read(): unsupported fallback encoding 'UTF-8/../x'.");

        iterator_to_array(Csv::read($path, null, 'UTF-8/../x'));
    }

    public function test_empty_lines_are_skipped_and_trailing_empty_fields_tolerated(): void
    {
        $path = $this->file('e.csv', "a;b\n\n1;2;;\n;\n3;4\n");

        $this->assertSame([3 => ['a' => '1', 'b' => '2'], 5 => ['a' => '3', 'b' => '4']], $this->all($path));
    }

    public function test_row_with_wrong_field_count_throws(): void
    {
        $path = $this->file('f.csv', "a;b\n1;2\n1;2;3\n");

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Csv::read(): row 3 has 3 fields, the header has 2.');

        $this->all($path);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidFileProvider(): iterable
    {
        yield 'gol' => ['', 'is empty (no header row)'];
        yield 'coloana fara nume' => ["a;;c\n1;2;3\n", 'header column 2 has no name'];
        yield 'coloana duplicata' => ["a;B;b\n1;2;3\n", "duplicate header column 'b'"];
        yield 'xlsx' => ["PK\x03\x04restul", 'is not a CSV text file (it looks like an XLSX, XLS or PDF file)'];
        yield 'binar' => ["a;b\n1;\0\n", 'is not a CSV text file (it contains binary data)'];
    }

    #[DataProvider('invalidFileProvider')]
    public function test_invalid_files(string $content, string $message): void
    {
        $path = $this->file('x.csv', $content);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        $this->all($path);
    }

    public function test_missing_file(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist or is not readable');

        $this->all($this->dir.'/lipsa.csv');
    }

    public function test_large_file_with_utf8_split_across_chunks(): void
    {
        // Peste 1 MB: o secventa UTF-8 cade la granita dintre bucatile citite.
        $line = str_repeat('x', 99)."\u{0219}\n";
        $path = $this->file('big.csv', "text\n".str_repeat($line, 12000));

        $count = 0;
        foreach (Csv::read($path) as $row) {
            $this->assertSame(rtrim($line, "\n"), $row['text']);
            $count++;
        }
        $this->assertSame(12000, $count);
    }

    // scriere

    public function test_write_and_read_back(): void
    {
        $path = $this->dir.'/out.csv';
        Csv::write($path, [
            ['nume' => 'Ion', 'suma' => 1234.5, 'activ' => true, 'data' => new DateTimeImmutable('2026-03-15 10:20:30'), 'nota' => null],
            ['nume' => "Ana \"Mia\"\nPop", 'suma' => 10, 'activ' => false, 'data' => null, 'nota' => 'a;b'],
        ]);

        $raw = (string) file_get_contents($path);
        $this->assertStringStartsWith("\xEF\xBB\xBFnume;suma;activ;data;nota\r\n", $raw);
        $this->assertStringContainsString("Ion;1234.5;1;\"2026-03-15 10:20:30\";\r\n", $raw);
        $this->assertSame([
            2 => ['nume' => 'Ion', 'suma' => '1234.5', 'activ' => '1', 'data' => '2026-03-15 10:20:30', 'nota' => ''],
            3 => ['nume' => "Ana \"Mia\"\nPop", 'suma' => '10', 'activ' => '0', 'data' => '', 'nota' => 'a;b'],
        ], $this->all($path));
        $this->assertSame(['out.csv'], array_values(array_diff((array) scandir($this->dir), ['.', '..'])), 'fara fisiere temporare');
    }

    public function test_write_escapes_formulas(): void
    {
        $path = $this->dir.'/f.csv';
        Csv::write($path, [['v' => '=HYPERLINK("http://x")'], ['v' => '+1'], ['v' => '-1'], ['v' => '@SUM(A1)'], ['v' => -5], ['v' => 'normal']], bom: false);

        $this->assertSame(["'=HYPERLINK(\"http://x\")", "'+1", "'-1", "'@SUM(A1)", '-5', 'normal'], array_column($this->all($path), 'v'));
    }

    public function test_write_list_rows_with_header_and_other_delimiter(): void
    {
        $path = $this->dir.'/l.csv';
        Csv::write($path, [[1, 'a'], [2, 'b']], ['id', 'cod'], ',', false);

        $this->assertSame("id,cod\r\n1,a\r\n2,b\r\n", file_get_contents($path));
    }

    public function test_write_header_only_when_no_rows(): void
    {
        $path = $this->dir.'/h.csv';
        Csv::write($path, [], ['id', 'cod'], bom: false);

        $this->assertSame("id;cod\r\n", file_get_contents($path));
    }

    public function test_write_rejects_mismatched_rows_and_leaves_nothing(): void
    {
        $path = $this->dir.'/m.csv';

        try {
            Csv::write($path, [['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1]]);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Csv::write(): the keys of row #1 do not match the header (same columns, same order).', $e->getMessage());
        }

        $this->assertSame([], array_values(array_diff((array) scandir($this->dir), ['.', '..'])));
    }

    public function test_write_rejects_unsupported_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Csv::write(): row #0, column 'a': unsupported type array.");

        Csv::write($this->dir.'/t.csv', [['a' => [1]]]);
    }

    public function test_write_to_missing_directory(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        Csv::write($this->dir.'/lipsa/x.csv', [['a' => 1]]);
    }

    #[RunInSeparateProcess]
    public function test_download_streams_csv(): void
    {
        ob_start();
        Csv::download("raport ian\u{0219}.csv", [['a' => 1, 'b' => 'x']]);
        $body = (string) ob_get_clean();

        $this->assertSame("\xEF\xBB\xBFa;b\r\n1;x\r\n", $body);
    }

    #[RunInSeparateProcess]
    public function test_download_file(): void
    {
        $path = $this->file('export.csv', "a;b\r\n1;2\r\n");

        ob_start();
        Csv::downloadFile($path);
        $body = (string) ob_get_clean();

        $this->assertSame("a;b\r\n1;2\r\n", $body);
    }

    // import

    /**
     * @return array<string, array<string, mixed>>
     */
    private function schema(): array
    {
        return [
            'cnp' => ['required' => true, 'pattern' => '/^\d{13}$/D', 'unique' => true],
            'nume' => ['required' => true, 'max' => 20],
            'suma' => ['required' => true, 'type' => 'decimal'],
            'data' => ['type' => 'date'],
            'tip' => ['in' => ['A', 'B']],
            'activ' => ['type' => 'bool'],
            'varsta' => ['type' => 'int'],
        ];
    }

    public function test_import_multiple_valid_files(): void
    {
        $a = $this->file('a.tmp', "CNP;Nume;Suma;Data;Tip;Activ;Varsta\n1960101123456; Ion ;1.234,5;15.03.2026;A;da;30\n");
        $b = $this->file('b.tmp', "cnp,nume,suma,data,tip,activ,varsta\n2960101123456,Ana,\"10\",2026-01-31,,0,\n");

        $result = $this->importAll(['ianuarie.csv' => $a, 'februarie.csv' => $b], $this->schema());

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
        $this->assertSame([
            ['file' => 'ianuarie.csv', 'row' => 2, 'data' => ['cnp' => '1960101123456', 'nume' => 'Ion', 'suma' => '1234.50', 'data' => '2026-03-15', 'tip' => 'A', 'activ' => true, 'varsta' => 30]],
            ['file' => 'februarie.csv', 'row' => 2, 'data' => ['cnp' => '2960101123456', 'nume' => 'Ana', 'suma' => '10.00', 'data' => '2026-01-31', 'tip' => null, 'activ' => false, 'varsta' => null]],
        ], $result['rows']);
        $this->assertSame(['status' => 'ok', 'rows' => 1, 'valid' => 1, 'errors' => 0], $result['files']['ianuarie.csv']);
    }

    public function test_import_reports_value_errors_without_values(): void
    {
        $path = $this->file('v.csv', implode("\n", [
            'cnp;nume;suma;data;tip;activ;varsta',
            '1960101123456;Ion;10;15.03.2026;A;1;30',
            '123;;abc;31.02.2026;C;poate;3.5',
            '1960101123456;Un nume mult prea lung pentru coloana;10,555;;;;',
            ';Dan;1.5;;;;',
            '1;2;3',
        ])."\n");

        $result = $this->importAll([$path], $this->schema());

        $this->assertFalse($result['valid']);
        $this->assertCount(1, $result['rows']);
        $this->assertSame(['status' => 'invalid', 'rows' => 5, 'valid' => 1, 'errors' => 13], $result['files']['v.csv']);

        $codes = array_map(static fn (array $error): string => $error['row'].':'.$error['column'].':'.$error['code'], $result['errors']);
        $this->assertSame([
            '3:cnp:pattern', '3:nume:required', '3:suma:type', '3:data:format', '3:tip:in', '3:activ:type', '3:varsta:type',
            '4:nume:max', '4:suma:scale', '4:cnp:unique',
            '5:cnp:required', '5:suma:type',
            '6::field_count',
        ], $codes);

        $all = implode(' ', array_column($result['errors'], 'message'));
        foreach (['1960101123456', 'abc', 'poate', 'mult prea lung'] as $value) {
            $this->assertStringNotContainsString($value, $all);
        }
    }

    public function test_import_unique_across_files(): void
    {
        $a = $this->file('a.csv', "cnp;nume;suma\n1960101123456;Ion;1\n");
        $b = $this->file('b.csv', "cnp;nume;suma\n1960101123456;Alt Ion;2\n");

        $result = $this->importAll([$a, $b], ['cnp' => ['unique' => true], 'nume' => [], 'suma' => ['type' => 'decimal']]);

        $this->assertFalse($result['valid']);
        $this->assertSame([['file' => 'b.csv', 'row' => 2, 'column' => 'cnp', 'code' => 'unique', 'message' => "Duplicate value (first seen in 'a.csv', row 2)."]], $result['errors']);
        $this->assertSame('ok', $result['files']['a.csv']['status']);
        $this->assertSame('invalid', $result['files']['b.csv']['status']);
    }

    public function test_import_file_level_problems(): void
    {
        $files = [
            'lipsa.csv' => $this->dir.'/lipsa.csv',
            'excel.csv' => $this->file('excel.csv', "PK\x03\x04..."),
            'gol.csv' => $this->file('gol.csv', ''),
            'doar-antet.csv' => $this->file('doar-antet.csv', "cnp;nume;suma\n"),
            'coloane.csv' => $this->file('coloane.csv', "cnp;prenume;suma;extra\n1;2;3;4\n"),
        ];

        $result = $this->importAll($files, ['cnp' => [], 'nume' => [], 'suma' => []]);

        $this->assertSame(
            ['lipsa.csv' => 'unreadable', 'excel.csv' => 'unreadable', 'gol.csv' => 'invalid', 'doar-antet.csv' => 'invalid', 'coloane.csv' => 'invalid'],
            array_map(static fn (array $file): string => $file['status'], $result['files']),
        );
        $this->assertSame(
            ['unreadable', 'not_text', 'empty', 'empty', 'missing_columns', 'extra_columns'],
            array_column($result['errors'], 'code'),
        );
        $this->assertSame("Missing columns: 'nume'.", $result['errors'][4]['message']);
        $this->assertSame("Unexpected columns: 'prenume', 'extra'.", $result['errors'][5]['message']);
    }

    public function test_import_allow_extra_columns(): void
    {
        $path = $this->file('x.csv', "id;ignorat\n1;x\n");

        $result = $this->importAll([$path], ['id' => ['type' => 'int']], ['allowExtraColumns' => true]);

        $this->assertTrue($result['valid']);
        $this->assertSame(['id' => 1], $result['rows'][0]['data']);
    }

    public function test_import_max_errors(): void
    {
        $path = $this->file('m.csv', "id\n".str_repeat("x\n", 10));

        $result = $this->importAll([$path], ['id' => ['type' => 'int']], ['maxErrors' => 3]);

        $this->assertSame(10, $result['files']['m.csv']['errors']);
        $this->assertCount(4, $result['errors']);
        $this->assertSame('too_many_errors', $result['errors'][3]['code']);
    }

    public function test_import_decimal_with_dot_separator_and_windows_1250(): void
    {
        $path = $this->file('w.csv', "oras,suma\nIa\xBAi,\"1,234.5\"\n");

        $result = $this->importAll([$path], ['oras' => [], 'suma' => ['type' => 'decimal', 'decimal' => '.', 'scale' => 1]]);

        $this->assertSame(['oras' => "Ia\u{015F}i", 'suma' => '1234.5'], $result['rows'][0]['data']);
    }

    public function test_unclosed_quote_is_rejected_before_reading_the_whole_file(): void
    {
        $path = $this->dir.'/unclosed.csv';
        $handle = fopen($path, 'w');
        $this->assertIsResource($handle);
        fwrite($handle, "id;nume\n1;\"ghilimea neinchisa\n");
        $line = str_repeat('x', 1023)."\n";
        for ($i = 0; $i < 9 * 1024; $i++) {
            fwrite($handle, $line);
        }
        fclose($handle);

        try {
            iterator_to_array(Csv::read($path));
            $this->fail('Expected UnexpectedValueException');
        } catch (UnexpectedValueException $e) {
            $this->assertStringContainsString('has a record longer than 8 MB (probably an unclosed quote)', $e->getMessage());
        }

        $result = $this->importAll([$path], ['id' => [], 'nume' => []]);
        $this->assertSame('corrupt', $result['errors'][0]['code']);
    }

    public function test_long_quoted_multiline_values_below_the_limit_are_read(): void
    {
        $value = str_repeat("rand lung\n", 50_000); // ~500 KB intr-un singur camp, pe mai multe linii
        $path = $this->file('lung.csv', "id;text\n1;\"{$value}\"\n2;scurt\n");

        $rows = iterator_to_array(Csv::read($path));

        $this->assertSame($value, $rows[2]['text']);
        $this->assertSame('scurt', $rows[3]['text']);
    }

    public function test_utf8_file_with_one_stray_byte_keeps_its_diacritics(): void
    {
        // fisier UTF-8 cu un singur octet Windows-1250 (0xBA = s cu sedila) pe ultimul rand
        $path = $this->file('mixt.csv', "oras;judet\nIa\u{0219}i;Ia\u{0219}i\nTimi\u{0219}oara;Timi\u{0219}\nCra\xBAov;Dolj\n");

        $rows = array_values(iterator_to_array(Csv::read($path)));

        $this->assertSame("Ia\u{0219}i", $rows[0]['oras'], 'campurile UTF-8 valide raman neschimbate');
        $this->assertSame("Timi\u{0219}oara", $rows[1]['oras']);
        $this->assertSame("Cra\u{015F}ov", $rows[2]['oras'], 'doar campul invalid este convertit din Windows-1250');
    }

    public function test_import_decimal_rejects_dot_decimal_mistaken_for_thousands(): void
    {
        // '0.500' scris cu punct zecimal intr-un fisier cu format RO nu trebuie sa devina 500.00
        $path = $this->file('d.csv', "id;suma\n1;0.500\n2;1.234,50\n");

        $result = $this->importAll([$path], ['id' => [], 'suma' => ['type' => 'decimal', 'scale' => 2]]);

        $this->assertCount(1, $result['rows']);
        $this->assertSame('1234.50', $result['rows'][0]['data']['suma']);
        $this->assertSame(2, $result['errors'][0]['row']);
        $this->assertSame('suma', $result['errors'][0]['column']);
    }

    /**
     * @return iterable<string, array{array<string, array<string, mixed>>, string}>
     */
    public static function invalidSchemaProvider(): iterable
    {
        yield 'goala' => [[], 'schema must not be empty'];
        yield 'regula necunoscuta' => [['a' => ['requred' => true]], "unknown rule 'requred'"];
        yield 'tip necunoscut' => [['a' => ['type' => 'money']], "invalid type for column 'a'"];
        yield 'regex invalid' => [['a' => ['pattern' => '/(/']], "'pattern' for column 'a' is not a valid regular expression"];
        yield 'max invalid' => [['a' => ['max' => 0]], "'max' for column 'a' must be an int >= 1"];
        yield 'separator invalid' => [['a' => ['type' => 'decimal', 'decimal' => ';']], "'decimal' ',' or '.'"];
        yield 'in gol' => [['a' => ['in' => []]], "'in' for column 'a' must be a non-empty list"];
    }

    /**
     * @param  array<string, array<string, mixed>>  $schema
     */
    #[DataProvider('invalidSchemaProvider')]
    public function test_import_rejects_invalid_schema(array $schema, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Csv::import([$this->file('s.csv', "a\n1\n")], $schema);
    }

    public function test_import_rejects_no_files_and_duplicate_names(): void
    {
        try {
            Csv::import([], ['a' => []]);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Csv::import(): no files given.', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("duplicate file name 'a.csv'");

        Csv::import([$this->file('a.csv', "a\n1\n"), $this->dir.'/../'.basename($this->dir).'/a.csv'], ['a' => []]);
    }

    // import pe loturi si reluare

    public function test_import_yields_batches_with_cursor(): void
    {
        $path = $this->file('b.csv', "id\n1\n2\nx\n4\n5\n");

        $batches = iterator_to_array(Csv::import([$path], ['id' => ['type' => 'int']], [], 2), false);

        $this->assertCount(3, $batches);
        $this->assertSame([[1, 2], [4], [5]], array_map(static fn (array $batch): array => array_column(array_column($batch['rows'], 'data'), 'id'), $batches));
        $this->assertSame([[], ['4:type'], []], array_map(static fn (array $batch): array => array_map(static fn (array $error): string => $error['row'].':'.$error['code'], $batch['errors']), $batches));
        $this->assertSame([false, false, true], array_column($batches, 'done'));
        $this->assertSame(['status' => 'invalid', 'rows' => 5, 'valid' => 4, 'errors' => 1], $batches[2]['files']['b.csv']);
        $this->assertFalse($batches[2]['valid']);
        $this->assertSame(json_decode((string) json_encode($batches[1]['cursor']), true), $batches[1]['cursor'], 'cursorul se poate salva ca JSON');
    }

    public function test_import_resumes_from_cursor_without_rereading(): void
    {
        // Windows-1250 (conversie pe camp) si doua fisiere: reluarea trebuie sa continue exact unde s-a oprit.
        $a = $this->file('a.csv', "cod;oras\n".implode("\n", array_map(static fn (int $i): string => "A{$i};Ia\xBAi", range(1, 7)))."\n");
        $b = $this->file('b.csv', "cod;oras\n".implode("\n", array_map(static fn (int $i): string => "B{$i};Cluj", range(1, 5)))."\n");
        $files = ['a.csv' => $a, 'b.csv' => $b];
        $schema = ['cod' => ['required' => true, 'unique' => true], 'oras' => []];
        $uniqueFile = $this->dir.'/unique.bin';

        $codes = [];
        $cursor = null;
        $calls = 0;
        do {
            $calls++;
            $last = null;
            foreach (Csv::import($files, $schema, ['uniqueFile' => $uniqueFile], 3, $cursor) as $batch) {
                array_push($codes, ...array_column(array_column($batch['rows'], 'data'), 'cod'));
                $cursor = $batch['cursor'];
                $last = $batch;

                break; // o "cerere web" proceseaza un singur lot
            }
        } while ($last !== null && ! $last['done']);

        $this->assertNotNull($last);
        $this->assertSame([...array_map(static fn (int $i): string => "A{$i}", range(1, 7)), ...array_map(static fn (int $i): string => "B{$i}", range(1, 5))], $codes);
        $this->assertSame(['a.csv' => ['status' => 'ok', 'rows' => 7, 'valid' => 7, 'errors' => 0], 'b.csv' => ['status' => 'ok', 'rows' => 5, 'valid' => 5, 'errors' => 0]], $last['files']);
        $this->assertTrue($last['valid']);
        $this->assertGreaterThan(3, $calls);
    }

    public function test_reprocessing_a_batch_after_interruption_is_not_a_duplicate(): void
    {
        $path = $this->file('r.csv', "cod\nA\nB\nC\nD\n");
        $schema = ['cod' => ['unique' => true]];
        $options = ['uniqueFile' => $this->dir.'/u.bin'];

        $first = Csv::import([$path], $schema, $options, 2)->current();
        // Lotul 2 este procesat, dar cursorul lui nu a fost salvat (intrerupere): se reia din cursorul lotului 1.
        $generator = Csv::import([$path], $schema, $options, 2, $first['cursor']);
        $generator->current();
        $retry = iterator_to_array(Csv::import([$path], $schema, $options, 2, $first['cursor']), false);

        $this->assertSame([], array_merge(...array_column($retry, 'errors')));
        $this->assertSame(['C', 'D'], array_column(array_column(array_merge(...array_column($retry, 'rows')), 'data'), 'cod'));
    }

    public function test_duplicates_across_resumed_runs_are_found(): void
    {
        $path = $this->file('d.csv', "cod\nA\nB\nA\n");
        $schema = ['cod' => ['unique' => true]];
        $options = ['uniqueFile' => $this->dir.'/u.bin'];

        $first = Csv::import([$path], $schema, $options, 2)->current();
        $rest = iterator_to_array(Csv::import([$path], $schema, $options, 2, $first['cursor']), false);

        $this->assertSame(
            [['file' => 'd.csv', 'row' => 4, 'column' => 'cod', 'code' => 'unique', 'message' => "Duplicate value (first seen in 'd.csv', row 2)."]],
            array_merge(...array_column($rest, 'errors')),
        );
    }

    public function test_resume_with_unique_requires_unique_file(): void
    {
        $path = $this->file('u.csv', "cod\nA\nB\nC\n");
        $cursor = Csv::import([$path], ['cod' => ['unique' => true]], [], 1)->current()['cursor'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("resuming an import with 'unique' columns requires the 'uniqueFile' option");

        Csv::import([$path], ['cod' => ['unique' => true]], [], 1, $cursor);
    }

    public function test_invalid_or_foreign_cursor_is_rejected(): void
    {
        $a = $this->file('a.csv', "cod\nA\nB\n");
        $cursor = Csv::import([$a], ['cod' => []], [], 1)->current()['cursor'];

        try {
            Csv::import(['alt.csv' => $a], ['cod' => []], [], 1, $cursor);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Csv::import(): the cursor belongs to a different list of files.', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Csv::import(): invalid cursor.');

        Csv::import([$a], ['cod' => []], [], 1, [...$cursor, 'offset' => -5]);
    }

    public function test_max_unique_limit(): void
    {
        $path = $this->file('m.csv', "cod\nA\nB\nC\n");

        $this->expectException(\OverflowException::class);
        $this->expectExceptionMessage("more than 2 unique values; raise 'maxUnique' or check uniqueness in the database");

        iterator_to_array(Csv::import([$path], ['cod' => ['unique' => true]], ['maxUnique' => 2]));
    }

    public function test_encoding_error_is_reported_on_the_row(): void
    {
        $path = $this->file('e.csv', "cod\nIa\xBAi\nx\x81y\nok\n");

        $result = $this->importAll([$path], ['cod' => []]);

        $this->assertSame([['e.csv', 3, 'encoding']], array_map(static fn (array $error): array => [$error['file'], $error['row'], $error['code']], $result['errors']));
        $this->assertSame(["Ia\u{015F}i", 'ok'], array_column(array_column($result['rows'], 'data'), 'cod'));
    }

    // writer

    public function test_writer_in_a_loop(): void
    {
        $path = $this->dir.'/w.csv';
        $writer = Csv::writer($path, bom: false);
        foreach ((static function (): \Generator {
            for ($i = 1; $i <= 3; $i++) {
                yield ['id' => $i, 'nume' => "N{$i}"];
            }
        })() as $row) {
            $writer->add($row);
        }

        $this->assertFileDoesNotExist($path, 'fisierul apare abia la close()');
        $writer->close();
        $writer->close();

        $this->assertSame(3, $writer->count());
        $this->assertSame("id;nume\r\n1;N1\r\n2;N2\r\n3;N3\r\n", file_get_contents($path));

        $this->expectException(\LogicException::class);
        $writer->add(['id' => 4, 'nume' => 'N4']);
    }

    public function test_abandoned_writer_leaves_nothing(): void
    {
        $path = $this->dir.'/a.csv';
        $writer = Csv::writer($path);
        $writer->add(['id' => 1]);
        unset($writer);

        $this->assertSame([], array_values(array_diff((array) scandir($this->dir), ['.', '..'])));
    }

    public function test_writer_append_across_requests(): void
    {
        $path = $this->dir.'/export.csv';

        foreach ([[1, 2], [3], []] as $chunk) {
            $writer = Csv::writer($path, ['id', 'nume'], append: true);
            foreach ($chunk as $id) {
                $writer->add(['id' => $id, 'nume' => "N{$id}"]);
            }
            $writer->close();
        }

        $this->assertSame([2 => ['id' => '1', 'nume' => 'N1'], 3 => ['id' => '2', 'nume' => 'N2'], 4 => ['id' => '3', 'nume' => 'N3']], self::all($path));
        $this->assertSame(1, substr_count((string) file_get_contents($path), "\xEF\xBB\xBF"), 'BOM doar o data');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("the header of '{$path}' is different from the given header");

        Csv::writer($path, ['id', 'alt'], append: true);
    }

    public function test_no_warnings_reach_host_error_handler(): void
    {
        $triggered = false;
        set_error_handler(static function () use (&$triggered): bool {
            $triggered = true;

            return true;
        });

        try {
            $this->importAll([
                $this->dir.'/lipsa.csv',
                $this->file('bin.csv', "\0\0"),
                $this->file('bun.csv', "a\n1\n"),
            ], ['a' => []]);
            try {
                Csv::write($this->dir.'/lipsa/x.csv', [['a' => 1]]);
            } catch (RuntimeException) {
            }
        } finally {
            restore_error_handler();
        }

        $this->assertFalse($triggered);
    }
}
