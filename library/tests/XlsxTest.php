<?php

declare(strict_types=1);

namespace Foundry\Tests;

use DateTimeImmutable;
use DateTimeInterface;
use Foundry\File;
use Foundry\Xlsx;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;
use ZipArchive;

final class XlsxTest extends TestCase
{
    private const string MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/utilities-xlsx-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDir($this->dir);
    }

    /**
     * Valorile citite, cu datele transformate in text pentru comparare.
     *
     * @return array<int, array<string, mixed>>
     */
    private function all(string $path, string|int|null $sheet = null): array
    {
        $rows = [];
        foreach (Xlsx::read($path, $sheet) as $number => $row) {
            $rows[$number] = array_map(static fn (mixed $value): mixed => $value instanceof DateTimeInterface ? 'D:'.$value->format('Y-m-d H:i:s') : $value, $row);
        }

        return $rows;
    }

    /**
     * Toate loturile importului, unite ca un singur rezultat.
     *
     * @param  array<array-key, string>  $files
     * @param  array<string, array<string, mixed>>  $schema
     * @param  array{sheet?: string|int|null, allowExtraColumns?: bool, maxErrors?: int, uniqueFile?: string|null, maxUnique?: int}  $options
     * @return array{valid: bool, rows: list<array{file: string, row: int, data: array<string, mixed>}>, errors: list<array{file: string, row: int|null, column: string|null, code: string, message: string}>, files: array<string, array{status: string, rows: int, valid: int, errors: int}>}
     */
    private function importAll(array $files, array $schema, array $options = []): array
    {
        $result = ['valid' => false, 'rows' => [], 'errors' => [], 'files' => []];
        foreach (Xlsx::import($files, $schema, $options, 2) as $batch) {
            array_push($result['rows'], ...$batch['rows']);
            array_push($result['errors'], ...$batch['errors']);
            $result['files'] = $batch['files'];
            $result['valid'] = $batch['valid'];
        }

        return $result;
    }

    /**
     * Un .xlsx construit manual, ca cele salvate de Excel (texte partajate, stiluri, foi multiple).
     *
     * @param  array<string, string>  $sheets  Nume foaie => continutul <sheetData>.
     */
    private function crafted(array $sheets, string $sharedStrings = '', string $styles = '', bool $date1904 = false, string $name = 'excel.xlsx'): string
    {
        $path = $this->dir.'/'.$name;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $sheetList = '';
        $relations = '';
        $index = 0;
        foreach ($sheets as $sheetName => $data) {
            $index++;
            $sheetList .= '<sheet name="'.$sheetName.'" sheetId="'.$index.'" r:id="rId'.$index.'"/>';
            $relations .= '<Relationship Id="rId'.$index.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="/xl/worksheets/s'.$index.'.xml"/>';
            $zip->addFromString('xl/worksheets/s'.$index.'.xml', '<?xml version="1.0"?><worksheet xmlns="'.self::MAIN.'"><sheetData>'.$data.'</sheetData></worksheet>');
        }

        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="'.self::MAIN.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .($date1904 ? '<workbookPr date1904="1"/>' : '').'<sheets>'.$sheetList.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relations.'</Relationships>');
        if ($sharedStrings !== '') {
            $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="'.self::MAIN.'">'.$sharedStrings.'</sst>');
        }
        if ($styles !== '') {
            $zip->addFromString('xl/styles.xml', '<?xml version="1.0"?><styleSheet xmlns="'.self::MAIN.'">'.$styles.'</styleSheet>');
        }
        $zip->close();

        return $path;
    }

    // scriere + citire

    public function test_write_and_read_back_types(): void
    {
        $path = $this->dir.'/r.xlsx';
        Xlsx::write($path, [
            ['nr' => 1, 'client' => "Ion \u{0218}tefan", 'suma' => 1234.5, 'activ' => true, 'data' => new DateTimeImmutable('2026-03-15'), 'ora' => new DateTimeImmutable('2026-03-15 10:30:00'), 'nota' => null],
            ['nr' => -2, 'client' => '=SUM(A1)', 'suma' => 0.1 + 0.2, 'activ' => false, 'data' => new DateTimeImmutable('1900-03-01'), 'ora' => new DateTimeImmutable('2026-12-31 23:59:59'), 'nota' => " _x0041_ \nrand nou"],
        ]);

        $this->assertSame([
            2 => ['nr' => 1, 'client' => "Ion \u{0218}tefan", 'suma' => 1234.5, 'activ' => true, 'data' => 'D:2026-03-15 00:00:00', 'ora' => 'D:2026-03-15 10:30:00', 'nota' => null],
            3 => ['nr' => -2, 'client' => '=SUM(A1)', 'suma' => 0.1 + 0.2, 'activ' => false, 'data' => 'D:1900-03-01 00:00:00', 'ora' => 'D:2026-12-31 23:59:59', 'nota' => " _x0041_ \nrand nou"],
        ], $this->all($path));
        $this->assertSame(['r.xlsx'], array_values(array_diff((array) scandir($this->dir), ['.', '..'])), 'fara fisiere temporare');
    }

    public function test_written_file_structure(): void
    {
        $path = $this->dir.'/s.xlsx';
        Xlsx::write($path, [[1, 'a'], [2, 'b']], ['id', 'cod'], 'Date');

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $workbook = (string) $zip->getFromName('xl/workbook.xml');
        $zip->close();

        $this->assertStringContainsString('<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>', $sheet);
        $this->assertStringContainsString('<c r="A1" s="1" t="inlineStr"><is><t>id</t></is></c>', $sheet);
        $this->assertStringContainsString('<autoFilter ref="A1:B3"/>', $sheet);
        $this->assertStringContainsString('<sheet name="Date" sheetId="1" r:id="rId1"/>', $workbook);
        $this->assertStringNotContainsString('<f>', $sheet, 'textul nu devine formula');
    }

    public function test_write_multiple_sheets(): void
    {
        $path = $this->dir.'/m.xlsx';
        Xlsx::writeSheets($path, ['Polite' => [['nr' => 1]], "Daune & \u{0218}" => [['id' => 'x'], ['id' => 'y']]]);

        $this->assertSame(['Polite', "Daune & \u{0218}"], Xlsx::sheets($path));
        $this->assertSame([2 => ['id' => 'x'], 3 => ['id' => 'y']], $this->all($path, 1));
        $this->assertSame([2 => ['nr' => 1]], $this->all($path, 'Polite'));
    }

    public function test_write_header_only(): void
    {
        $path = $this->dir.'/h.xlsx';
        Xlsx::write($path, [], ['id', 'cod']);

        $this->assertSame([], $this->all($path));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSheetNameProvider(): iterable
    {
        yield 'gol' => [''];
        yield 'prea lung' => [str_repeat('a', 32)];
        yield 'caracter interzis' => ['Ian/Feb'];
        yield 'paranteze drepte' => ['[1]'];
        yield 'apostrof la inceput' => ["'x"];
    }

    #[DataProvider('invalidSheetNameProvider')]
    public function test_invalid_sheet_names(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid sheet name');

        Xlsx::write($this->dir.'/n.xlsx', [['a' => 1]], null, $name);
    }

    public function test_duplicate_sheet_names_ignore_case(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("duplicate sheet name 'date'");

        Xlsx::writeSheets($this->dir.'/d.xlsx', ['Date' => [], 'date' => []]);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidValueProvider(): iterable
    {
        yield 'caracter de control' => ["a\x01b", 'text contains control characters not allowed in Excel'];
        yield 'UTF-8 invalid' => ["a\xC3\x28", 'text is not valid UTF-8'];
        yield 'text prea lung' => [str_repeat('a', Xlsx::MAX_CELL_LENGTH + 1), 'text is longer than 32767 characters'];
        yield 'NAN' => [NAN, 'number must be finite'];
        yield 'data veche' => [new DateTimeImmutable('1900-02-28'), 'dates before 01.03.1900 are not supported'];
        yield 'array' => [[1], 'unsupported type array'];
    }

    #[DataProvider('invalidValueProvider')]
    public function test_invalid_values_are_rejected(mixed $value, string $message): void
    {
        try {
            Xlsx::write($this->dir.'/v.xlsx', [['id' => 1, 'val' => $value]]);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString("sheet 'Sheet1', row 2, column 'val': {$message}", $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->dir.'/v.xlsx');
        $this->assertSame([], array_values(array_diff((array) scandir($this->dir), ['.', '..'])));
    }

    public function test_rows_must_match_header(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Xlsx::write(): sheet 'Sheet1', the keys of row #1 do not match the header");

        Xlsx::write($this->dir.'/k.xlsx', [['a' => 1, 'b' => 2], ['a' => 1]]);
    }

    public function test_write_to_missing_directory(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        Xlsx::write($this->dir.'/lipsa/x.xlsx', [['a' => 1]]);
    }

    #[RunInSeparateProcess]
    public function test_download_sends_xlsx(): void
    {
        ob_start();
        Xlsx::download('raport.xlsx', ['Date' => [['a' => 1]]]);
        $body = (string) ob_get_clean();

        $this->assertStringStartsWith("PK\x03\x04", $body);
        $path = $this->dir.'/descarcat.xlsx';
        file_put_contents($path, $body);
        $this->assertSame([2 => ['a' => 1]], $this->all($path));
    }

    // citire fisiere Excel

    public function test_read_excel_features(): void
    {
        $strings = '<si><t>Nume</t></si><si><t>Data</t></si><si><t>Suma</t></si><si><t>Stare</t></si>'
            .'<si><r><t>Ion </t></r><r><rPr><b/></rPr><t>Pop</t></r><rPh><t>fonetic</t></rPh></si>'
            .'<si><t>linia1_x000D_linia2</t></si><si/>';
        $styles = '<numFmts><numFmt numFmtId="164" formatCode="[$-418]dd/mm/yyyy;@"/><numFmt numFmtId="165" formatCode="#,##0.00 &quot;lei&quot;"/></numFmts>'
            .'<cellXfs><xf numFmtId="0"/><xf numFmtId="14"/><xf numFmtId="164"/><xf numFmtId="165"/><xf numFmtId="22"/></cellXfs>';
        $data = '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c><c r="D1" t="s"><v>3</v></c></row>'
            .'<row r="2"><c r="A2" t="s"><v>4</v></c><c r="B2" s="1"><v>46096</v></c><c r="C2" s="3"><v>1234.5</v></c><c r="D2" t="b"><v>1</v></c></row>'
            .'<row r="3"/>'
            .'<row r="5"><c r="A5" t="s"><v>5</v></c><c r="B5" s="2"><v>46096.5</v></c><c r="C5" t="e"><v>#DIV/0!</v></c><c r="D5" t="inlineStr"><is><t>da</t></is></c></row>'
            .'<row><c t="str"><v>formula</v></c><c s="4"><v>60</v></c><c><v>42</v></c><c t="s"><v>6</v></c></row>';

        $path = $this->crafted(['Foaie' => $data], $strings, $styles);

        $this->assertSame([
            2 => ['Nume' => 'Ion Pop', 'Data' => 'D:2026-03-15 00:00:00', 'Suma' => 1234.5, 'Stare' => true],
            5 => ['Nume' => "linia1\rlinia2", 'Data' => 'D:2026-03-15 12:00:00', 'Suma' => '#DIV/0!', 'Stare' => 'da'],
            6 => ['Nume' => 'formula', 'Data' => 'D:1900-03-01 00:00:00', 'Suma' => 42, 'Stare' => ''], // serial 60 = 29.02.1900 inexistent
        ], $this->all($path));
    }

    public function test_many_shared_strings_are_read_from_disk(): void
    {
        // Peste 100.000 de texte partajate, ele sunt mutate intr-un fisier temporar indexat.
        $count = 100_500;
        $strings = '';
        $rows = '<row r="1"><c r="A1" t="inlineStr"><is><t>text</t></is></c></row>';
        for ($i = 0; $i < $count; $i++) {
            $strings .= "<si><t>valoare {$i}</t></si>";
        }
        foreach ([0, 99_999, 100_000, $count - 1, 5] as $row => $index) {
            $rows .= '<row r="'.($row + 2).'"><c r="A'.($row + 2).'" t="s"><v>'.$index.'</v></c></row>';
        }
        $path = $this->crafted(['A' => $rows], $strings);

        $this->assertSame(['valoare 0', 'valoare 99999', 'valoare 100000', 'valoare '.($count - 1), 'valoare 5'], array_column(self::all($path), 'text'));
    }

    public function test_read_1904_date_system(): void
    {
        $path = $this->crafted(['A' => '<row r="1"><c r="A1" t="inlineStr"><is><t>d</t></is></c></row><row r="2"><c r="A2" s="1"><v>0</v></c></row>'], '', '<cellXfs><xf numFmtId="0"/><xf numFmtId="14"/></cellXfs>', true);

        $this->assertSame([2 => ['d' => 'D:1904-01-01 00:00:00']], $this->all($path));
    }

    public function test_values_outside_header_columns(): void
    {
        $path = $this->crafted(['A' => '<row r="1"><c r="A1" t="inlineStr"><is><t>a</t></is></c></row><row r="2"><c r="A2"><v>1</v></c><c r="C2"><v>3</v></c></row>']);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Xlsx::read(): row 2 has values outside the 1 header columns.');

        $this->all($path);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidFileProvider(): iterable
    {
        yield 'csv redenumit' => ["a;b\n1;2\n", 'it is not an .xlsx file (not a ZIP archive)'];
        yield 'xls vechi' => ["\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 100), 'it is an old .xls file; save it as .xlsx'];
    }

    #[DataProvider('invalidFileProvider')]
    public function test_files_that_are_not_xlsx(string $content, string $message): void
    {
        $path = $this->dir.'/f.xlsx';
        file_put_contents($path, $content);

        try {
            Xlsx::sheets($path);
            $this->fail('Expected UnexpectedValueException');
        } catch (UnexpectedValueException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_damaged_files(): void
    {
        $noWorkbook = $this->dir.'/a.xlsx';
        $zip = new ZipArchive;
        $zip->open($noWorkbook, ZipArchive::CREATE);
        $zip->addFromString('x.txt', 'x');
        $zip->close();

        $badSheet = $this->crafted(['A' => '<row><c><v>1</v></row>'], name: 'b.xlsx');
        $badString = $this->crafted(['A' => '<row><c t="s"><v>9</v></c></row>'], '<si><t>a</t></si>', name: 'c.xlsx');

        foreach ([[$noWorkbook, "'xl/workbook.xml' is missing"], [$badSheet, 'is not valid XML'], [$badString, 'invalid shared string index']] as [$path, $message]) {
            try {
                $this->all($path);
                $this->fail("Expected UnexpectedValueException for {$path}");
            } catch (UnexpectedValueException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function test_missing_sheet(): void
    {
        $path = $this->crafted(['Unu' => '', 'Doi' => '']);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("sheet 'Trei' does not exist in '{$path}' (sheets: 'Unu', 'Doi').");

        $this->all($path, 'Trei');
    }

    public function test_styles_with_millions_of_elements_are_rejected(): void
    {
        // ~2 MB comprimat bine, dar 400.000 de elemente xf: inainte devenea sute de MB de array-uri PHP
        $path = $this->crafted(['Foaie' => '<row r="1"><c r="A1"><v>1</v></c></row>'], styles: '<cellXfs>'.str_repeat('<xf numFmtId="0"/>', 400_000).'</cellXfs>');

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("'xl/styles.xml' has more than 300000 elements");

        $this->all($path);
    }

    public function test_cells_without_reference_are_capped_at_the_column_limit(): void
    {
        $path = $this->crafted(['Foaie' => '<row r="1">'.str_repeat('<c><v>1</v></c>', Xlsx::MAX_COLUMNS + 1).'</row>']);

        $this->expectException(UnexpectedValueException::class);

        $this->all($path);
    }

    public function test_shared_string_longer_than_excel_limit_is_rejected(): void
    {
        $runs = str_repeat('<r><t>'.str_repeat('a', 10_000).'</t></r>', 30); // 300.000 caractere intr-o singura celula
        $path = $this->crafted(['Foaie' => '<row r="1"><c r="A1" t="s"><v>0</v></c></row>'], sharedStrings: '<si>'.$runs.'</si>');

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('a shared string is longer than 32767 characters');

        $this->all($path);
    }

    public function test_styles_without_cell_formats_are_valid(): void
    {
        $path = $this->crafted(['Foaie' => '<row r="1"><c r="A1"><v>1</v></c></row><row r="2"><c r="A2"><v>5</v></c></row>'], styles: '<fonts count="1"><font/></fonts>');

        $this->assertCount(1, $this->all($path));
    }

    public function test_empty_sheet_and_invalid_header(): void
    {
        foreach ([
            [$this->crafted(['A' => ''], name: 'gol.xlsx'), 'is empty (no header row)'],
            [$this->crafted(['A' => '<row><c t="inlineStr"><is><t>a</t></is></c><c/><c t="inlineStr"><is><t>c</t></is></c></row>'], name: 'h1.xlsx'), 'header column 2 has no name'],
            [$this->crafted(['A' => '<row><c t="inlineStr"><is><t>a</t></is></c><c t="inlineStr"><is><t>A</t></is></c></row>'], name: 'h2.xlsx'), "duplicate header column 'A'"],
        ] as [$path, $message]) {
            try {
                $this->all($path);
                $this->fail("Expected UnexpectedValueException for {$path}");
            } catch (UnexpectedValueException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    // import

    /**
     * @return array<string, array<string, mixed>>
     */
    private function schema(): array
    {
        return [
            'cnp' => ['required' => true, 'pattern' => '/^\d{13}$/D', 'unique' => true],
            'suma' => ['required' => true, 'type' => 'decimal'],
            'data' => ['type' => 'date'],
            'nr' => ['type' => 'int'],
        ];
    }

    public function test_import_typed_cells(): void
    {
        $a = $this->dir.'/a.xlsx';
        Xlsx::write($a, [
            ['CNP' => '1960101123456', 'Suma' => 1234.5, 'Data' => new DateTimeImmutable('2026-03-15'), 'Nr' => 7.0],
            ['CNP' => '2960101123456', 'Suma' => '10,5', 'Data' => '31.01.2026', 'Nr' => '8'],
        ]);
        $b = $this->dir.'/b.xlsx';
        Xlsx::write($b, [['cnp' => '1960101123456', 'suma' => 1.005, 'data' => true, 'nr' => 1.5]]);

        $result = $this->importAll(['ianuarie.xlsx' => $a, 'februarie.xlsx' => $b], $this->schema());

        $this->assertSame([
            ['file' => 'ianuarie.xlsx', 'row' => 2, 'data' => ['cnp' => '1960101123456', 'suma' => '1234.50', 'data' => '2026-03-15', 'nr' => 7]],
            ['file' => 'ianuarie.xlsx', 'row' => 3, 'data' => ['cnp' => '2960101123456', 'suma' => '10.50', 'data' => '2026-01-31', 'nr' => 8]],
        ], $result['rows']);
        $this->assertSame(
            ['2:suma:scale', '2:data:type', '2:nr:type', '2:cnp:unique'],
            array_map(static fn (array $error): string => $error['row'].':'.$error['column'].':'.$error['code'], $result['errors']),
        );
        $this->assertSame(['status' => 'invalid', 'rows' => 1, 'valid' => 0, 'errors' => 4], $result['files']['februarie.xlsx']);
        $this->assertFalse($result['valid']);
    }

    public function test_import_cell_errors_and_file_problems(): void
    {
        $errors = $this->crafted(['Date' => '<row><c t="inlineStr"><is><t>cnp</t></is></c><c t="inlineStr"><is><t>suma</t></is></c><c t="inlineStr"><is><t>data</t></is></c><c t="inlineStr"><is><t>nr</t></is></c></row>'
            .'<row><c t="inlineStr"><is><t>1960101123456</t></is></c><c t="e"><v>#N/A</v></c></row>'], name: 'erori.xlsx');
        $csv = $this->dir.'/date.csv';
        file_put_contents($csv, "cnp;suma\n1;2\n");

        $result = $this->importAll([$errors, $csv, $this->dir.'/lipsa.xlsx', $errors.'#'], $this->schema(), ['sheet' => 'Date']);

        $this->assertSame(
            [['erori.xlsx', 'suma', 'cell_error'], ['date.csv', null, 'not_xlsx'], ['lipsa.xlsx', null, 'unreadable'], ['erori.xlsx#', null, 'unreadable']],
            array_map(static fn (array $error): array => [$error['file'], $error['column'], $error['code']], $result['errors']),
        );
        $this->assertSame('The cell contains an Excel error (#N/A).', $result['errors'][0]['message']);
        $this->assertSame('unreadable', $result['files']['date.csv']['status']);
    }

    public function test_import_missing_sheet_is_reported(): void
    {
        $path = $this->dir.'/s.xlsx';
        Xlsx::write($path, [['cnp' => '1', 'suma' => 1, 'data' => null, 'nr' => null]]);

        $result = $this->importAll([$path], $this->schema(), ['sheet' => 'Altceva']);

        $this->assertSame('sheet', $result['errors'][0]['code']);
        $this->assertSame('invalid', $result['files']['s.xlsx']['status']);
    }

    public function test_import_rejects_invalid_schema(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Xlsx::import(): unknown rule 'requred' for column 'a'.");

        Xlsx::import([$this->dir.'/x.xlsx'], ['a' => ['requred' => true]]);
    }

    // writer si export pe ZIP

    public function test_writer_in_a_loop(): void
    {
        $path = $this->dir.'/w.xlsx';
        $writer = Xlsx::writer($path, sheet: 'Polite');
        for ($i = 1; $i <= 3; $i++) {
            $writer->add(['nr' => $i, 'data' => new DateTimeImmutable('2026-03-15')]);
        }

        $this->assertFileDoesNotExist($path, 'fisierul apare abia la close()');
        $writer->close();
        $writer->close();

        $this->assertSame(3, $writer->count());
        $this->assertSame(['Polite'], Xlsx::sheets($path));
        $this->assertSame([2 => ['nr' => 1, 'data' => 'D:2026-03-15 00:00:00'], 3 => ['nr' => 2, 'data' => 'D:2026-03-15 00:00:00'], 4 => ['nr' => 3, 'data' => 'D:2026-03-15 00:00:00']], self::all($path));
        $this->assertSame(['w.xlsx'], array_values(array_diff((array) scandir($this->dir), ['.', '..'])));

        $this->expectException(\LogicException::class);
        $writer->add(['nr' => 4, 'data' => null]);
    }

    public function test_writer_zip_splits_large_exports(): void
    {
        $path = $this->dir.'/polite.zip';
        $writer = Xlsx::writer($path, ['nr', 'nume'], maxRowsPerFile: 2);
        foreach (range(1, 5) as $i) {
            $writer->add([$i, "N{$i}"]);
        }
        $writer->close();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        $this->assertSame(['polite_1.xlsx', 'polite_2.xlsx', 'polite_3.xlsx'], $names);

        $rows = [];
        foreach ($names as $name) {
            $part = $this->dir.'/'.$name;
            file_put_contents($part, (string) $zip->getFromName($name));
            $rows[$name] = array_column(self::all($part), 'nr');
        }
        $zip->close();

        $this->assertSame(['polite_1.xlsx' => [1, 2], 'polite_2.xlsx' => [3, 4], 'polite_3.xlsx' => [5]], $rows);
    }

    public function test_writer_zip_with_one_part(): void
    {
        $path = $this->dir.'/mic.zip';
        $writer = Xlsx::writer($path, ['nr']);
        $writer->add([1]);
        $writer->close();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $this->assertSame('mic.xlsx', $zip->getNameIndex(0));
        $this->assertSame(1, $zip->numFiles);
        $zip->close();
    }

    public function test_writer_xlsx_overflow_leaves_nothing(): void
    {
        $writer = Xlsx::writer($this->dir.'/o.xlsx', ['nr'], maxRowsPerFile: 2);
        $writer->add([1]);
        $writer->add([2]);

        try {
            $writer->add([3]);
            $this->fail('Expected OverflowException');
        } catch (\OverflowException $e) {
            $this->assertSame('Xlsx::add(): more than 2 rows; use a .zip path to split the export into several .xlsx files.', $e->getMessage());
        }

        unset($writer);
        $this->assertSame([], array_values(array_diff((array) scandir($this->dir), ['.', '..'])));
    }

    public function test_writer_rejects_other_extensions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the path must end with .xlsx (one file) or .zip');

        Xlsx::writer($this->dir.'/x.csv');
    }

    #[RunInSeparateProcess]
    public function test_download_file(): void
    {
        $path = $this->dir.'/d.zip';
        $writer = Xlsx::writer($path, ['a']);
        $writer->add([1]);
        $writer->close();

        ob_start();
        Xlsx::downloadFile($path, 'export polite.zip');
        $body = (string) ob_get_clean();

        $this->assertSame((string) file_get_contents($path), $body);
    }

    // import pe loturi si reluare

    public function test_import_batches_and_resume(): void
    {
        $path = $this->dir.'/r.xlsx';
        Xlsx::write($path, array_map(static fn (int $i): array => ['cod' => "C{$i}", 'suma' => $i], range(1, 7)));
        $schema = ['cod' => ['unique' => true], 'suma' => ['type' => 'int']];
        $options = ['uniqueFile' => $this->dir.'/u.bin'];

        $codes = [];
        $cursor = null;
        $calls = 0;
        do {
            $calls++;
            $last = null;
            foreach (Xlsx::import([$path], $schema, $options, 3, $cursor) as $batch) {
                array_push($codes, ...array_column(array_column($batch['rows'], 'data'), 'cod'));
                $cursor = $batch['cursor'];
                $last = $batch;

                break;
            }
        } while ($last !== null && ! $last['done']);

        $this->assertNotNull($last);
        $this->assertSame(array_map(static fn (int $i): string => "C{$i}", range(1, 7)), $codes);
        $this->assertSame(['r.xlsx' => ['status' => 'ok', 'rows' => 7, 'valid' => 7, 'errors' => 0]], $last['files']);
        $this->assertSame(3, $calls, '3 + 3 + 1 randuri; ultimul lot are si done');
    }

    // stage: import in mai multe cereri fara reparcurgerea foii

    private function stageSource(): string
    {
        $path = $this->dir.'/sursa.xlsx';
        Xlsx::write($path, array_map(static fn (int $i): array => [
            'cod' => "C{$i}",
            'suma' => $i === 3 ? 1.0 : $i * 1.5,
            'data' => new DateTimeImmutable('2026-03-'.sprintf('%02d', $i).' 10:30:00'),
            'activ' => $i % 2 === 0,
            'nota' => $i === 4 ? null : "N{$i}",
        ], range(1, 9)));

        return $path;
    }

    public function test_stage_gives_the_same_result_as_direct_import(): void
    {
        $path = $this->stageSource();
        $schema = ['cod' => ['unique' => true], 'suma' => ['type' => 'decimal', 'scale' => 1], 'data' => ['type' => 'date'], 'activ' => ['type' => 'bool'], 'nota' => ['required' => true]];

        $this->assertSame(9, Xlsx::stage($path, $this->dir.'/sursa.stage'));

        $direct = self::importAll(['sursa.xlsx' => $path], $schema);
        $staged = self::importAll(['sursa.xlsx' => $path], $schema, ['staged' => ['sursa.xlsx' => $this->dir.'/sursa.stage']]);

        $this->assertSame($direct, $staged);
        $this->assertSame('1.0', $staged['rows'][2]['data']['suma'], '1.0 ramane numar cu zecimale');
        $this->assertSame([['sursa.xlsx', 5, 'nota', 'required']], array_map(static fn (array $error): array => [$error['file'], $error['row'], $error['column'], $error['code']], $staged['errors']));
    }

    public function test_staged_values_keep_their_types(): void
    {
        $path = $this->crafted(['A' => '<row><c t="inlineStr"><is><t>a</t></is></c><c t="inlineStr"><is><t>b</t></is></c></row>'
            .'<row><c t="e"><v>#N/A</v></c><c t="b"><v>1</v></c></row>'], name: 'e.xlsx');
        Xlsx::stage($path, $this->dir.'/e.stage');

        $result = self::importAll(['e.xlsx' => $path], ['a' => [], 'b' => ['type' => 'bool']], ['staged' => ['e.xlsx' => $this->dir.'/e.stage']]);

        $this->assertSame([['e.xlsx', 2, 'a', 'cell_error']], array_map(static fn (array $error): array => [$error['file'], $error['row'], $error['column'], $error['code']], $result['errors']));
    }

    public function test_staged_import_resumes_across_requests(): void
    {
        $path = $this->stageSource();
        $work = $this->dir.'/sursa.stage';
        Xlsx::stage($path, $work);
        $options = ['staged' => ['sursa.xlsx' => $work], 'uniqueFile' => $this->dir.'/u.bin', 'allowExtraColumns' => true];

        $codes = [];
        $cursor = null;
        $calls = 0;
        do {
            $calls++;
            $last = null;
            foreach (Xlsx::import(['sursa.xlsx' => $path], ['cod' => ['unique' => true]], $options, 2, $cursor) as $batch) {
                array_push($codes, ...array_column(array_column($batch['rows'], 'data'), 'cod'));
                $cursor = $batch['cursor'];
                $last = $batch;

                break;
            }
        } while ($last !== null && ! $last['done']);

        $this->assertSame(array_map(static fn (int $i): string => "C{$i}", range(1, 9)), $codes);
        $this->assertSame(5, $calls, '2 + 2 + 2 + 2 + 1');
        $this->assertNotNull($last);
        $this->assertTrue($last['valid']);
    }

    public function test_stale_staged_file_is_rejected(): void
    {
        $path = $this->stageSource();
        $work = $this->dir.'/sursa.stage';
        Xlsx::stage($path, $work);

        try {
            iterator_to_array(Xlsx::import(['sursa.xlsx' => $path], ['cod' => []], ['staged' => ['sursa.xlsx' => $work], 'sheet' => 0]));
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('does not match', $e->getMessage(), 'alta foaie decat la stage()');
        }

        touch($path, time() + 10);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("the staged file '{$work}' does not match '{$path}' (the source or the sheet changed); run stage() again.");

        iterator_to_array(Xlsx::import(['sursa.xlsx' => $path], ['cod' => []], ['staged' => ['sursa.xlsx' => $work]]));
    }

    public function test_stage_of_invalid_file_leaves_nothing(): void
    {
        $path = $this->dir.'/date.xlsx';
        file_put_contents($path, "a;b\n1;2\n");

        try {
            Xlsx::stage($path, $this->dir.'/date.stage');
            $this->fail('Expected UnexpectedValueException');
        } catch (UnexpectedValueException $e) {
            $this->assertStringContainsString('not an .xlsx file', $e->getMessage());
        }

        $this->assertSame(['date.xlsx'], array_values(array_diff((array) scandir($this->dir), ['.', '..'])));
    }

    public function test_staged_option_must_name_a_file_in_the_list(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Xlsx::import(): staged file 'alt.xlsx' is not in the list of files.");

        Xlsx::import(['a.xlsx' => $this->dir.'/a.xlsx'], ['a' => []], ['staged' => ['alt.xlsx' => $this->dir.'/x.stage']]);
    }

    public function test_no_warnings_reach_host_error_handler(): void
    {
        $notZip = $this->dir.'/n.xlsx';
        file_put_contents($notZip, 'text');
        $badXml = $this->crafted(['A' => '<row><c><v>1</v></row>'], name: 'bad.xlsx');

        $triggered = false;
        set_error_handler(static function () use (&$triggered): bool {
            $triggered = true;

            return true;
        });

        try {
            $this->importAll([$notZip, $badXml, $this->dir.'/lipsa.xlsx'], ['a' => []]);
            try {
                Xlsx::write($this->dir.'/lipsa/x.xlsx', [['a' => 1]]);
            } catch (RuntimeException) {
            }
        } finally {
            restore_error_handler();
        }

        $this->assertFalse($triggered);
    }
}
