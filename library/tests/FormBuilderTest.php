<?php

declare(strict_types=1);

namespace Foundry\Tests;

use Foundry\FormBuilder;
use Foundry\Validator;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormBuilderTest extends TestCase
{
    /**
     * Randuri ca din SHOW FULL COLUMNS FROM `polite`.
     *
     * @return list<array<string, mixed>>
     */
    private function mysqlRows(): array
    {
        $row = static fn (string $field, string $type, string $null = 'NO', ?string $default = null, string $extra = '', string $comment = ''): array => [
            'Field' => $field, 'Type' => $type, 'Collation' => null, 'Null' => $null, 'Key' => '', 'Default' => $default, 'Extra' => $extra, 'Privileges' => 'select', 'Comment' => $comment,
        ];

        return [
            $row('id', 'int unsigned', extra: 'auto_increment'),
            $row('numar', 'varchar(20)', comment: 'Numar polita'),
            $row('tip', "enum('RCA','CASCO','Locuinta d''vacanta')"),
            $row('suma', 'decimal(10,2) unsigned'),
            $row('rate', 'tinyint unsigned', default: '1'),
            $row('activ', 'tinyint(1)', default: '1'),
            $row('data_inceput', 'date'),
            $row('creat_la', 'timestamp', default: 'CURRENT_TIMESTAMP', extra: 'DEFAULT_GENERATED'),
            $row('ora', 'time', 'YES'),
            $row('observatii', 'text', 'YES'),
            $row('email', 'varchar(255)', 'YES'),
            $row('procent', 'double', 'YES'),
            $row('an', 'year', 'YES'),
            $row('total', 'decimal(12,2)', 'YES', extra: 'VIRTUAL GENERATED'),
            $row('document', 'blob', 'YES'),
        ];
    }

    public function test_mysql_fields(): void
    {
        $fields = FormBuilder::fromMySql($this->mysqlRows())->except('document')->fields(['numar' => 'RO123', 'activ' => true]);
        $byName = array_column($fields, null, 'name');

        // Coloanele generate (id, total) sunt excluse automat.
        $this->assertSame(['numar', 'tip', 'suma', 'rate', 'activ', 'data_inceput', 'creat_la', 'ora', 'observatii', 'email', 'procent', 'an'], array_keys($byName));

        $this->assertSame([
            'name' => 'numar', 'label' => 'Numar polita', 'type' => 'text', 'required' => true, 'value' => 'RO123',
            'options' => null, 'maxlength' => 20, 'min' => null, 'max' => null, 'step' => null, 'help' => null,
        ], $byName['numar']);

        $this->assertSame('select', $byName['tip']['type']);
        $this->assertSame([
            ['value' => 'RCA', 'label' => 'RCA'],
            ['value' => 'CASCO', 'label' => 'CASCO'],
            ['value' => "Locuinta d'vacanta", 'label' => "Locuinta d'vacanta"],
        ], $byName['tip']['options']);

        $this->assertSame(['number', 0, '99999999.99', '0.01'], [$byName['suma']['type'], $byName['suma']['min'], $byName['suma']['max'], $byName['suma']['step']]);
        $this->assertSame(['number', 0, 255, '1', false], [$byName['rate']['type'], $byName['rate']['min'], $byName['rate']['max'], $byName['rate']['step'], $byName['rate']['required']]);
        $this->assertSame(['checkbox', false, true], [$byName['activ']['type'], $byName['activ']['required'], $byName['activ']['value']]);
        $this->assertSame(['date', true], [$byName['data_inceput']['type'], $byName['data_inceput']['required']]);
        $this->assertSame(['datetime-local', false], [$byName['creat_la']['type'], $byName['creat_la']['required']]);
        $this->assertSame('time', $byName['ora']['type']);
        $this->assertSame(['textarea', null, false], [$byName['observatii']['type'], $byName['observatii']['maxlength'], $byName['observatii']['required']]);
        $this->assertSame(['number', 'any'], [$byName['procent']['type'], $byName['procent']['step']]);
        $this->assertSame([1901, 2155], [$byName['an']['min'], $byName['an']['max']]);
        $this->assertSame('Data inceput', $byName['data_inceput']['label']);
    }

    public function test_mysql_rules(): void
    {
        $rules = FormBuilder::fromMySql($this->mysqlRows())->except('document')->rules();

        $this->assertSame([
            'numar' => ['required', 'string', 'max:20'],
            'tip' => ['required', "in:RCA,CASCO,Locuinta d'vacanta"],
            'suma' => ['required', 'numeric', 'min:0', 'regex:/^[+-]?\d{1,8}(\.\d{1,2})?$/'],
            'rate' => ['int', 'between:0,255'],
            'activ' => ['bool'],
            'data_inceput' => ['required', 'date:Y-m-d'],
            'creat_la' => ['date:Y-m-d\TH:i'],
            'ora' => ['date:H:i'],
            'observatii' => ['string'],
            'email' => ['string', 'max:255'],
            'procent' => ['numeric'],
            'an' => ['int', 'between:1901,2155'],
        ], $rules);
    }

    public function test_information_schema_rows(): void
    {
        $rows = [[
            'COLUMN_NAME' => 'cod', 'COLUMN_TYPE' => 'char(3)', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => null, 'EXTRA' => '', 'COLUMN_COMMENT' => 'Cod judet',
        ]];

        $field = FormBuilder::fromMySql($rows)->fields()[0];

        $this->assertSame(['cod', 'Cod judet', 'text', true, 3], [$field['name'], $field['label'], $field['type'], $field['required'], $field['maxlength']]);
    }

    public function test_mysql_type_in_upper_case(): void
    {
        $rows = [['Field' => 'nume', 'Type' => 'VARCHAR(50)', 'Null' => 'NO'], ['Field' => 'stare', 'Type' => "ENUM('Activ','Inactiv')", 'Null' => 'YES']];

        $this->assertSame(['nume' => ['required', 'string', 'max:50'], 'stare' => ['in:Activ,Inactiv']], FormBuilder::fromMySql($rows)->rules());
    }

    public function test_unsupported_column_must_be_excluded(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("FormBuilder::fields(): column 'document' has type 'blob', which has no form field; exclude it with except() or set type().");

        FormBuilder::fromMySql($this->mysqlRows())->fields();
    }

    public function test_rules_work_with_validator(): void
    {
        $form = FormBuilder::fromMySql($this->mysqlRows())->only('numar', 'tip', 'suma', 'activ', 'data_inceput', 'email')->type('email', 'email');

        $valid = new Validator(['numar' => 'RO1', 'tip' => 'CASCO', 'suma' => '1500.50', 'activ' => '1', 'data_inceput' => '2026-01-01', 'email' => 'a@b.ro'], $form->rules());
        $this->assertTrue($valid->passes(), json_encode($valid->errors(), JSON_THROW_ON_ERROR));

        $invalid = new Validator(['numar' => str_repeat('x', 21), 'tip' => 'PAD', 'suma' => '1.555', 'data_inceput' => '01.01.2026', 'email' => 'x'], $form->rules());
        $this->assertSame([
            'numar' => 'max', 'tip' => 'in', 'suma' => 'regex', 'data_inceput' => 'date', 'email' => 'email',
        ], $invalid->codes());
    }

    public function test_unsigned_decimal_rejects_negative_values(): void
    {
        $form = FormBuilder::fromMySql($this->mysqlRows())->only('suma');

        $this->assertSame(['suma' => 'min'], (new Validator(['suma' => '-5.00'], $form->rules()))->codes());
    }

    public function test_enum_values_with_commas_or_empty_strings(): void
    {
        $row = static fn (string $field, string $type): array => [
            'Field' => $field, 'Type' => $type, 'Collation' => null, 'Null' => 'NO', 'Key' => '', 'Default' => null, 'Extra' => '', 'Privileges' => 'select', 'Comment' => '',
        ];
        $form = FormBuilder::fromMySql([$row('optiune', "enum('a,b','c','')")]);
        $rules = $form->rules();

        $this->assertArrayHasKey('enum', $rules['optiune'], 'valorile cu virgula nu incap intr-o regula in:');
        $this->assertTrue((new Validator(['optiune' => 'a,b'], $rules))->passes());
        $this->assertSame(['optiune' => 'enum'], (new Validator(['optiune' => 'a'], $rules))->codes(), "'a' nu este o valoare a enum-ului");
    }

    // Oracle

    public function test_oracle(): void
    {
        $row = static fn (string $name, string $type, string $nullable = 'Y', ?int $length = null, ?int $precision = null, ?int $scale = null, ?string $default = null): array => [
            'COLUMN_NAME' => $name, 'DATA_TYPE' => $type, 'NULLABLE' => $nullable, 'CHAR_LENGTH' => $length,
            'DATA_PRECISION' => $precision, 'DATA_SCALE' => $scale, 'DATA_DEFAULT' => $default,
        ];

        $form = FormBuilder::fromOracle([
            $row('NUMAR', 'VARCHAR2', 'N', 30),
            $row('DENUMIRE', 'NVARCHAR2', 'Y', 100),
            $row('SUMA', 'NUMBER', 'N', 0, 12, 2),
            $row('NR_RATE', 'NUMBER', 'N', 0, 2, 0, '1 '),
            $row('ID_MARE', 'NUMBER', 'Y', 0, 22, 0),
            $row('VALOARE', 'NUMBER'),
            $row('DATA', 'DATE', 'N'),
            $row('MODIFICAT', 'TIMESTAMP(6)'),
            $row('NOTE', 'CLOB'),
            $row('POZA', 'BLOB'),
        ])->except('poza');

        $this->assertSame(['numar', 'denumire', 'suma', 'nr_rate', 'id_mare', 'valoare', 'data', 'modificat', 'note'], $form->names());
        $this->assertSame([
            'numar' => ['required', 'string', 'max:30'],
            'denumire' => ['string', 'max:100'],
            'suma' => ['required', 'numeric', 'regex:/^[+-]?\d{1,10}(\.\d{1,2})?$/'],
            'nr_rate' => ['int', 'between:-99,99'],
            'id_mare' => ['numeric', 'regex:/^[+-]?\d{1,22}(\.\d{1,0})?$/'],
            'valoare' => ['numeric'],
            'data' => ['required', 'date:Y-m-d'],
            'modificat' => ['date:Y-m-d\TH:i'],
            'note' => ['string'],
        ], $form->rules());
    }

    // SQLite (baza reala)

    public function test_sqlite_pragma(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE clienti (
            id INTEGER PRIMARY KEY,
            nume VARCHAR(100) NOT NULL,
            cnp CHAR(13),
            descriere TEXT,
            varsta INTEGER NOT NULL DEFAULT 18,
            sold DECIMAL(10,2),
            rata REAL,
            activ BOOLEAN NOT NULL DEFAULT 1,
            nascut DATE,
            creat DATETIME,
            poza BLOB
        )');
        $statement = $pdo->query('PRAGMA table_info(clienti)');
        $this->assertNotFalse($statement);
        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        $form = FormBuilder::fromSqlite($rows)->except('poza');

        $this->assertSame([
            'nume' => ['required', 'string', 'max:100'],
            'cnp' => ['string', 'max:13'],
            'descriere' => ['string'],
            'varsta' => ['int', 'between:'.PHP_INT_MIN.','.PHP_INT_MAX],
            'sold' => ['numeric', 'regex:/^[+-]?\d{1,8}(\.\d{1,2})?$/'],
            'rata' => ['numeric'],
            'activ' => ['bool'],
            'nascut' => ['date:Y-m-d'],
            'creat' => ['date:Y-m-d\TH:i'],
        ], $form->rules());
    }

    // Ajustari

    public function test_overrides(): void
    {
        $form = FormBuilder::fromMySql($this->mysqlRows())
            ->only('email', 'numar', 'observatii', 'rate', 'id')
            ->labels(['email' => 'Adresa de email', 'numar' => 'Nr.'])
            ->type('email', 'email')
            ->type('rate', 'select')
            ->options('rate', ['1' => 'Integral', '4' => 'Trimestrial', '12' => 'Lunar'])
            ->help('numar', 'Ex: RO123')
            ->required('observatii')
            ->addRules('numar', 'regex:/^RO\d+$/')
            ->addRules('numar', ['min:3']);

        $fields = array_column($form->fields(), null, 'name');
        $this->assertSame(['email', 'numar', 'observatii', 'rate', 'id'], array_keys($fields));
        $this->assertSame(['Adresa de email', 'email', 255], [$fields['email']['label'], $fields['email']['type'], $fields['email']['maxlength']]);
        $this->assertSame(['Nr.', 'Ex: RO123'], [$fields['numar']['label'], $fields['numar']['help']]);
        $this->assertTrue($fields['observatii']['required']);
        $this->assertSame([['value' => '1', 'label' => 'Integral'], ['value' => '4', 'label' => 'Trimestrial'], ['value' => '12', 'label' => 'Lunar']], $fields['rate']['options']);
        $this->assertSame('number', $fields['id']['type']);
        $this->assertFalse($fields['id']['required']);

        $rules = $form->rules();
        $this->assertSame(['string', 'max:255', 'email'], $rules['email']);
        $this->assertSame(['required', 'string', 'max:20', 'regex:/^RO\d+$/', 'min:3'], $rules['numar']);
        $this->assertSame(['required', 'string'], $rules['observatii']);
        $this->assertSame(['in:1,4,12'], $rules['rate']);
    }

    public function test_checkbox_override_on_number_column(): void
    {
        $form = FormBuilder::fromOracle([
            ['COLUMN_NAME' => 'ACTIV', 'DATA_TYPE' => 'NUMBER', 'NULLABLE' => 'N', 'DATA_PRECISION' => 1, 'DATA_SCALE' => 0, 'DATA_DEFAULT' => null],
        ])->type('activ', 'checkbox');

        $this->assertSame(['activ' => ['bool']], $form->rules());
        $this->assertFalse($form->fields()[0]['required']);
    }

    public function test_is_immutable(): void
    {
        $base = FormBuilder::fromMySql($this->mysqlRows())->only('numar', 'email');
        $changed = $base->label('numar', 'X')->except('email');

        $this->assertSame(['numar', 'email'], $base->names());
        $this->assertSame('Numar polita', $base->fields()[0]['label']);
        $this->assertSame(['numar'], $changed->names());
    }

    /**
     * @return iterable<string, array{callable(FormBuilder): mixed, string}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'coloana necunoscuta' => [static fn (FormBuilder $f): FormBuilder => $f->only('lipsa'), "FormBuilder::only(): unknown column 'lipsa'."];
        yield 'except necunoscut' => [static fn (FormBuilder $f): FormBuilder => $f->except('lipsa'), "FormBuilder::except(): unknown column 'lipsa'."];
        yield 'eticheta goala' => [static fn (FormBuilder $f): FormBuilder => $f->label('numar', ' '), "the label for 'numar' is empty"];
        yield 'tip necunoscut' => [static fn (FormBuilder $f): FormBuilder => $f->type('numar', 'color'), "unknown field type 'color'"];
        yield 'optiuni goale' => [static fn (FormBuilder $f): FormBuilder => $f->options('tip', []), "the list of options for 'tip' is empty"];
        yield 'optiune cu virgula' => [static fn (FormBuilder $f): FormBuilder => $f->options('tip', ['a,b' => 'A']), 'cannot be empty or contain commas'];
        yield 'select fara optiuni' => [static fn (FormBuilder $f): array => $f->only('numar')->type('numar', 'select')->fields(), "field 'numar' is a select without options; use options()."];
    }

    /**
     * @param  callable(FormBuilder): mixed  $callback
     */
    #[DataProvider('invalidProvider')]
    public function test_invalid(callable $callback, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $callback(FormBuilder::fromMySql($this->mysqlRows()));
    }

    public function test_invalid_metadata(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("FormBuilder::fromMySql(): row 0 has no 'type' / 'column_type' column (unexpected metadata format).");

        FormBuilder::fromMySql([['Field' => 'x']]);
    }

    public function test_no_columns(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('FormBuilder: no columns given');

        FormBuilder::fromSqlite([]);
    }
}
