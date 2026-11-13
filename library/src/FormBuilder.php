<?php

declare(strict_types=1);

namespace Foundry;

use Closure;
use InvalidArgumentException;

/**
 * FormBuilder Class
 *
 * Generates form field definitions and validation rules from database table column metadata.
 * Returns data rather than HTML: field type, label, required flag, max length, numeric limits,
 * and options; the application template determines presentation. Rules follow the Validator format,
 * ensuring form definition and validation come from a single source of truth and remain in sync.
 *
 * Data sources (rows queried by the application):
 *   fromMySql:  SHOW FULL COLUMNS FROM `table` (or INFORMATION_SCHEMA.COLUMNS with COLUMN_TYPE)
 *   fromOracle: SELECT * FROM ALL_TAB_COLUMNS WHERE OWNER = ? AND TABLE_NAME = ? (column names become lowercase)
 *   fromSqlite: PRAGMA table_info(table)
 *
 * Rules:
 *   - database-generated columns (AUTO_INCREMENT, computed columns, INTEGER PRIMARY KEY in SQLite)
 *     are excluded automatically; others can be filtered with only() / except();
 *   - required = NOT NULL without a default value, and not a checkbox;
 *   - types without a form field counterpart (BLOB, JSON, SET, RAW...) throw an exception if retained;
 *   - the object is immutable: every modification returns a clone.
 *
 * Usage:
 *   require_once 'FormBuilder.php';
 *   use Foundry\FormBuilder;
 *
 *   $form = FormBuilder::fromMySql($db->fetchAll('SHOW FULL COLUMNS FROM `policies`'))
 *       ->only('number', 'type', 'amount', 'start_date', 'email', 'notes')
 *       ->label('number', 'Policy number')
 *       ->options('type', ['RCA' => 'RCA', 'CASCO' => 'CASCO'])
 *       ->type('email', 'email')
 *       ->type('notes', 'textarea');
 *
 *   $fields = $form->fields($policy);                     // for template rendering
 *   $validator = new Validator($_POST, $form->rules());   // identical validation rules
 *
 * @category Utilities
 *
 * @version  1.1
 *
 * @since    PHP 8.3
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
final class FormBuilder
{
    /** @var list<string> Field types accepted by type(). */
    private const array FIELD_TYPES = ['text', 'textarea', 'password', 'email', 'tel', 'url', 'number', 'date', 'datetime-local', 'time', 'checkbox', 'select', 'hidden'];

    /** @var array<string, array{int, int}> MySQL integer limits: [signed], [unsigned]. */
    private const array MYSQL_INTS = [
        'tinyint' => [-128, 127], 'smallint' => [-32768, 32767], 'mediumint' => [-8388608, 8388607],
        'int' => [-2147483648, 2147483647], 'integer' => [-2147483648, 2147483647], 'bigint' => [PHP_INT_MIN, PHP_INT_MAX],
    ];

    private const array MYSQL_UNSIGNED_MAX = [
        'tinyint' => 255, 'smallint' => 65535, 'mediumint' => 16777215, 'int' => 4294967295, 'integer' => 4294967295, 'bigint' => PHP_INT_MAX,
    ];

    /**
     * Columns in table order.
     * kind: text, textarea, int, decimal, float, bool, date, datetime, time, enum, unsupported.
     *
     * @var array<string, array{kind: string, dbType: string, nullable: bool, hasDefault: bool, generated: bool, maxLength: ?int, min: int|string|null, max: int|string|null, precision: ?int, scale: ?int, options: ?list<string>, comment: string}>
     */
    private array $columns;

    /** @var list<string> Form fields in order. */
    private array $selected = [];

    /** @var array<string, array{label?: string, type?: string, options?: array<string, string>, help?: string, required?: bool, rules?: list<string>}> */
    private array $overrides = [];

    /**
     * @param  array<string, array{kind: string, dbType: string, nullable: bool, hasDefault: bool, generated: bool, maxLength: ?int, min: int|string|null, max: int|string|null, precision: ?int, scale: ?int, options: ?list<string>, comment: string}>  $columns
     */
    private function __construct(array $columns)
    {
        if ($columns === []) {
            throw new InvalidArgumentException('FormBuilder: no columns given (the table does not exist or the query returned nothing).');
        }

        $this->columns = $columns;
        foreach ($columns as $name => $column) {
            if (! $column['generated']) {
                $this->selected[] = $name;
            }
        }
    }

    // Surse

    /**
     * From SHOW [FULL] COLUMNS (Field, Type, Null, Default, Extra, Comment)
     * or INFORMATION_SCHEMA.COLUMNS (COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_COMMENT).
     *
     * @param  list<array<string, mixed>>  $rows
     *
     * @throws InvalidArgumentException If rows do not match the expected structure.
     */
    public static function fromMySql(array $rows): self
    {
        $columns = [];
        foreach ($rows as $index => $row) {
            $row = array_change_key_case($row, CASE_LOWER);
            $name = self::rowString($row, ['field', 'column_name'], 'fromMySql', $index);
            $type = trim(self::rowString($row, ['type', 'column_type'], 'fromMySql', $index));
            $nullable = strtoupper(self::rowString($row, ['null', 'is_nullable'], 'fromMySql', $index)) === 'YES';
            $extra = strtolower((string) self::rowValue($row, ['extra']));
            $default = self::rowValue($row, ['default', 'column_default']);

            $column = self::mysqlType($type);
            $column['nullable'] = $nullable;
            $column['hasDefault'] = $default !== null;
            $column['generated'] = str_contains($extra, 'auto_increment') || str_contains($extra, 'generated') && ! str_contains($extra, 'default_generated');
            $column['comment'] = trim((string) self::rowValue($row, ['comment', 'column_comment']));
            $columns[$name] = $column;
        }

        return new self($columns);
    }

    /**
     * From ALL_TAB_COLUMNS / USER_TAB_COLUMNS (COLUMN_NAME, DATA_TYPE, NULLABLE, CHAR_LENGTH,
     * DATA_PRECISION, DATA_SCALE, DATA_DEFAULT). Column names are lowercased (as in Oracle.php).
     * DATE becomes a date field; for date and time use type('column', 'datetime-local').
     *
     * @param  list<array<string, mixed>>  $rows
     *
     * @throws InvalidArgumentException If rows do not match the expected structure.
     */
    public static function fromOracle(array $rows): self
    {
        $columns = [];
        foreach ($rows as $index => $row) {
            $row = array_change_key_case($row, CASE_LOWER);
            $name = strtolower(self::rowString($row, ['column_name'], 'fromOracle', $index));
            $type = strtoupper(self::rowString($row, ['data_type'], 'fromOracle', $index));
            $precision = self::rowInt($row, 'data_precision');
            $scale = self::rowInt($row, 'data_scale');
            $length = self::rowInt($row, 'char_length');

            $column = self::blank($type);
            if (preg_match('/^N?(VARCHAR2|CHAR)$/', $type) === 1) {
                $column['kind'] = 'text';
                $column['maxLength'] = $length;
            } elseif ($type === 'CLOB' || $type === 'NCLOB') {
                $column['kind'] = 'textarea';
            } elseif ($type === 'NUMBER') {
                $column = self::number($column, $precision, $scale);
            } elseif (in_array($type, ['FLOAT', 'BINARY_FLOAT', 'BINARY_DOUBLE'], true)) {
                $column['kind'] = 'float';
            } elseif ($type === 'DATE') {
                $column['kind'] = 'date';
            } elseif (str_starts_with($type, 'TIMESTAMP')) {
                $column['kind'] = 'datetime';
            }

            $column['nullable'] = strtoupper(self::rowString($row, ['nullable'], 'fromOracle', $index)) !== 'N';
            $default = self::rowValue($row, ['data_default']);
            $column['hasDefault'] = $default !== null && trim((string) $default) !== '' && strtoupper(trim((string) $default)) !== 'NULL';
            $columns[$name] = $column;
        }

        return new self($columns);
    }

    /**
     * From PRAGMA table_info (name, type, notnull, dflt_value, pk).
     *
     * @param  list<array<string, mixed>>  $rows
     *
     * @throws InvalidArgumentException If rows do not match the expected structure.
     */
    public static function fromSqlite(array $rows): self
    {
        $columns = [];
        foreach ($rows as $index => $row) {
            $row = array_change_key_case($row, CASE_LOWER);
            $name = self::rowString($row, ['name'], 'fromSqlite', $index);
            $declared = strtoupper(trim((string) self::rowValue($row, ['type'])));
            $column = self::blank($declared);

            preg_match('/\(\s*(\d+)\s*(?:,\s*(\d+)\s*)?\)/', $declared, $size);
            $first = isset($size[1]) ? (int) $size[1] : null;
            $second = isset($size[2]) ? (int) $size[2] : null;

            // Afinitatea tipului, in ordinea regulilor SQLite; tipurile de data/ora si bool au prioritate.
            if (preg_match('/^(BOOL|BOOLEAN)\b/', $declared) === 1) {
                $column['kind'] = 'bool';
            } elseif (preg_match('/^DATETIME|^TIMESTAMP/', $declared) === 1) {
                $column['kind'] = 'datetime';
            } elseif (preg_match('/^DATE\b/', $declared) === 1) {
                $column['kind'] = 'date';
            } elseif (preg_match('/^TIME\b/', $declared) === 1) {
                $column['kind'] = 'time';
            } elseif (str_contains($declared, 'INT')) {
                $column['kind'] = 'int';
                $column['min'] = PHP_INT_MIN;
                $column['max'] = PHP_INT_MAX;
            } elseif (str_contains($declared, 'CHAR') || str_contains($declared, 'CLOB') || str_contains($declared, 'TEXT')) {
                $column['kind'] = 'text';
                $column['maxLength'] = $first;
            } elseif (preg_match('/REAL|FLOA|DOUB/', $declared) === 1) {
                $column['kind'] = 'float';
            } elseif (preg_match('/^(DECIMAL|NUMERIC)/', $declared) === 1) {
                $column = $first === null ? ['kind' => 'float'] + $column : self::number($column, $first, $second ?? 0);
            }

            $primary = (int) self::rowValue($row, ['pk']) > 0;
            $column['nullable'] = (int) self::rowValue($row, ['notnull']) === 0 && ! $primary;
            $column['hasDefault'] = self::rowValue($row, ['dflt_value']) !== null;
            // INTEGER PRIMARY KEY este rowid-ul, completat automat.
            $column['generated'] = $primary && $declared === 'INTEGER';
            $columns[$name] = $column;
        }

        return new self($columns);
    }

    // Ajustari (returneaza o copie)

    /**
     * Selects only these fields, in this order (can include generated columns, e.g. for display).
     *
     * @throws InvalidArgumentException If a column does not exist.
     */
    public function only(string ...$names): self
    {
        foreach ($names as $name) {
            $this->assertColumn('only', $name);
        }

        $copy = clone $this;
        $copy->selected = array_values(array_unique($names));

        return $copy;
    }

    /**
     * @throws InvalidArgumentException If a column does not exist.
     */
    public function except(string ...$names): self
    {
        foreach ($names as $name) {
            $this->assertColumn('except', $name);
        }

        $copy = clone $this;
        $copy->selected = array_values(array_diff($this->selected, $names));

        return $copy;
    }

    /**
     * @throws InvalidArgumentException If the column does not exist or the label is empty.
     */
    public function label(string $name, string $label): self
    {
        if (trim($label) === '') {
            throw new InvalidArgumentException("FormBuilder::label(): the label for '{$name}' is empty.");
        }

        return $this->override('label', $name, ['label' => $label]);
    }

    /**
     * @param  array<string, string>  $labels  column => label
     *
     * @throws InvalidArgumentException
     */
    public function labels(array $labels): self
    {
        $copy = $this;
        foreach ($labels as $name => $label) {
            $copy = $copy->label((string) $name, $label);
        }

        return $copy;
    }

    /**
     * Options for a select field (value => label); the field becomes a select and the rule becomes in:.
     *
     * @param  array<int|string, string>  $options
     *
     * @throws InvalidArgumentException If the list is empty or any value contains a comma.
     */
    public function options(string $name, array $options): self
    {
        if ($options === []) {
            throw new InvalidArgumentException("FormBuilder::options(): the list of options for '{$name}' is empty.");
        }

        foreach (array_keys($options) as $value) {
            if (str_contains((string) $value, ',') || (string) $value === '') {
                throw new InvalidArgumentException("FormBuilder::options(): option values for '{$name}' cannot be empty or contain commas.");
            }
        }

        $normalized = [];
        foreach ($options as $value => $label) {
            $normalized[(string) $value] = $label;
        }

        return $this->override('options', $name, ['options' => $normalized]);
    }

    /**
     * Field type: text, textarea, password, email, tel, url, number, date, datetime-local, time,
     * checkbox, select, hidden. email adds the email rule; checkbox adds the bool rule; datetime-local adds date/time validation.
     *
     * @throws InvalidArgumentException If the type is not supported.
     */
    public function type(string $name, string $type): self
    {
        if (! in_array($type, self::FIELD_TYPES, true)) {
            throw new InvalidArgumentException("FormBuilder::type(): unknown field type '{$type}' (expected ".implode(', ', self::FIELD_TYPES).').');
        }

        return $this->override('type', $name, ['type' => $type]);
    }

    /**
     * Helper text displayed alongside the field.
     *
     * @throws InvalidArgumentException
     */
    public function help(string $name, string $help): self
    {
        return $this->override('help', $name, ['help' => $help]);
    }

    /**
     * Overrides whether the field is required.
     *
     * @throws InvalidArgumentException
     */
    public function required(string $name, bool $required = true): self
    {
        return $this->override('required', $name, ['required' => $required]);
    }

    /**
     * Additional Validator rules (e.g. 'regex:/^[A-Z]{2}\d+$/', 'min:3').
     *
     * @param  string|list<string>  $rules
     *
     * @throws InvalidArgumentException
     */
    public function addRules(string $name, string|array $rules): self
    {
        $list = is_string($rules) ? [$rules] : $rules;
        $existing = $this->overrides[$name]['rules'] ?? [];

        return $this->override('addRules', $name, ['rules' => [...$existing, ...$list]]);
    }

    // Rezultat

    /**
     * Form fields, in order.
     *
     * @param  array<array-key, mixed>  $values  Current values (e.g. database record or $_POST after a validation failure).
     * @return list<array{name: string, label: string, type: string, required: bool, value: mixed, options: ?list<array{value: string, label: string}>, maxlength: ?int, min: int|string|null, max: int|string|null, step: ?string, help: ?string}>
     *
     * @throws InvalidArgumentException If a field has a type without a form counterpart.
     */
    public function fields(array $values = []): array
    {
        $fields = [];
        foreach ($this->selected as $name) {
            $column = $this->columns[$name];
            $type = $this->fieldType('fields', $name);
            $override = $this->overrides[$name] ?? [];
            $numeric = $type === 'number';

            $fields[] = [
                'name' => $name,
                'label' => $override['label'] ?? ($column['comment'] !== '' ? $column['comment'] : ucfirst(str_replace('_', ' ', $name))),
                'type' => $type,
                'required' => $this->isRequired($name),
                'value' => $values[$name] ?? null,
                'options' => $this->optionList($name),
                'maxlength' => in_array($type, ['text', 'textarea', 'password', 'email', 'tel', 'url'], true) ? $column['maxLength'] : null,
                'min' => $numeric ? $column['min'] : null,
                'max' => $numeric ? $column['max'] : null,
                'step' => $numeric ? $this->step($column) : null,
                'help' => $override['help'] ?? null,
            ];
        }

        return $fields;
    }

    /**
     * Validator rules indexed by field (list format). An enum whose values contain a comma or an empty value
     * gets a Closure rule under the key 'enum' (strict comparison) instead of 'in:...'.
     *
     * @return array<string, array<int|string, string|Closure>>
     *
     * @throws InvalidArgumentException If a field has a type without a form counterpart.
     */
    public function rules(): array
    {
        $rules = [];
        foreach ($this->selected as $name) {
            $column = $this->columns[$name];
            $type = $this->fieldType('rules', $name);
            $list = $this->isRequired($name) ? ['required'] : [];

            $options = $this->optionList($name);
            if ($options !== null) {
                $values = array_column($options, 'value');
                // 'in:a,b' cannot express a value containing a comma (enum('a,b','c')) or an empty value:
                // those lists become an exact, strict comparison under the error code 'enum'.
                $plain = array_filter($values, static fn (string $v): bool => $v === '' || str_contains($v, ',')) === [];
                if ($plain) {
                    $list[] = 'in:'.implode(',', $values);
                } else {
                    $list['enum'] = static fn (mixed $value): bool => in_array($value, $values, true);
                }
            } elseif ($type === 'checkbox') {
                $list[] = 'bool';
            } else {
                $list = [...$list, ...$this->kindRules($column, $type)];
            }

            if ($type === 'email') {
                $list[] = 'email';
            }

            $rules[$name] = [...$list, ...($this->overrides[$name]['rules'] ?? [])];
        }

        return $rules;
    }

    /**
     * Form field names, in order.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return $this->selected;
    }

    // Intern

    /**
     * @param  array{label?: string, type?: string, options?: array<string, string>, help?: string, required?: bool, rules?: list<string>}  $values
     *
     * @throws InvalidArgumentException
     */
    private function override(string $method, string $name, array $values): self
    {
        $this->assertColumn($method, $name);

        $copy = clone $this;
        $copy->overrides[$name] = array_replace($this->overrides[$name] ?? [], $values);

        return $copy;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertColumn(string $method, string $name): void
    {
        if (! isset($this->columns[$name])) {
            throw new InvalidArgumentException("FormBuilder::{$method}(): unknown column '{$name}'.");
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private function fieldType(string $method, string $name): string
    {
        $override = $this->overrides[$name] ?? [];
        if (isset($override['type'])) {
            if ($override['type'] === 'select' && $this->optionList($name) === null) {
                throw new InvalidArgumentException("FormBuilder::{$method}(): field '{$name}' is a select without options; use options().");
            }

            return $override['type'];
        }

        if (isset($override['options'])) {
            return 'select';
        }

        $column = $this->columns[$name];

        return match ($column['kind']) {
            'text' => 'text',
            'textarea' => 'textarea',
            'int', 'decimal', 'float' => 'number',
            'bool' => 'checkbox',
            'date' => 'date',
            'datetime' => 'datetime-local',
            'time' => 'time',
            'enum' => 'select',
            default => throw new InvalidArgumentException("FormBuilder::{$method}(): column '{$name}' has type '{$column['dbType']}', which has no form field; exclude it with except() or set type()."),
        };
    }

    private function isRequired(string $name): bool
    {
        $override = $this->overrides[$name]['required'] ?? null;
        if ($override !== null) {
            return $override;
        }

        $column = $this->columns[$name];
        $type = $this->overrides[$name]['type'] ?? null;

        return ! $column['nullable'] && ! $column['hasDefault'] && ! $column['generated']
            && $column['kind'] !== 'bool' && $type !== 'checkbox';
    }

    /**
     * @return ?list<array{value: string, label: string}>
     */
    private function optionList(string $name): ?array
    {
        $options = $this->overrides[$name]['options'] ?? null;
        if ($options !== null) {
            $list = [];
            foreach ($options as $value => $label) {
                $list[] = ['value' => (string) $value, 'label' => $label];
            }

            return $list;
        }

        $values = $this->columns[$name]['options'];

        return $values === null ? null : array_map(static fn (string $value): array => ['value' => $value, 'label' => $value], $values);
    }

    /**
     * Rules inferred from the column type (field type can be overridden, e.g. text on an int column).
     *
     * @param  array{kind: string, dbType: string, nullable: bool, hasDefault: bool, generated: bool, maxLength: ?int, min: int|string|null, max: int|string|null, precision: ?int, scale: ?int, options: ?list<string>, comment: string}  $column
     * @return list<string>
     */
    private function kindRules(array $column, string $type): array
    {
        if ($type === 'datetime-local') {
            return ['date:Y-m-d\TH:i'];
        }

        return match ($column['kind']) {
            'text', 'textarea' => $column['maxLength'] === null ? ['string'] : ['string', "max:{$column['maxLength']}"],
            'int' => ['int', "between:{$column['min']},{$column['max']}"],
            // unsigned decimal / float columns have min 0 (as reported by fields()); the rule enforces it too
            'decimal' => ['numeric', ...($column['min'] === 0 ? ['min:0'] : []), 'regex:/^[+-]?\d{1,'.max(1, (int) $column['precision'] - (int) $column['scale']).'}(\.\d{1,'.(int) $column['scale'].'})?$/'],
            'float' => ['numeric', ...($column['min'] === 0 ? ['min:0'] : [])],
            'date' => ['date:Y-m-d'],
            'time' => ['date:H:i'],
            default => [],
        };
    }

    /**
     * @param  array{kind: string, scale: ?int}  $column
     */
    private function step(array $column): ?string
    {
        return match ($column['kind']) {
            'int' => '1',
            'decimal' => $column['scale'] === null || $column['scale'] === 0 ? '1' : '0.'.str_repeat('0', $column['scale'] - 1).'1',
            'float' => 'any',
            default => null,
        };
    }

    /**
     * Type from SHOW COLUMNS / COLUMN_TYPE: 'varchar(50)', 'int unsigned', 'decimal(10,2)', "enum('a','b')".
     *
     * @return array{kind: string, dbType: string, nullable: bool, hasDefault: bool, generated: bool, maxLength: ?int, min: int|string|null, max: int|string|null, precision: ?int, scale: ?int, options: ?list<string>, comment: string}
     */
    private static function mysqlType(string $type): array
    {
        $column = self::blank($type);
        preg_match('/^([a-z]+)(?:\((.*)\))?(.*)$/i', $type, $match);
        $base = strtolower($match[1] ?? '');
        $args = $match[2] ?? '';
        $unsigned = str_contains(strtolower($match[3] ?? ''), 'unsigned');

        if ($base === 'tinyint' && $args === '1' || $base === 'bit' && $args === '1' || $base === 'bool' || $base === 'boolean') {
            $column['kind'] = 'bool';
        } elseif (isset(self::MYSQL_INTS[$base])) {
            $column['kind'] = 'int';
            [$column['min'], $column['max']] = $unsigned ? [0, self::MYSQL_UNSIGNED_MAX[$base]] : self::MYSQL_INTS[$base];
        } elseif ($base === 'year') {
            $column['kind'] = 'int';
            [$column['min'], $column['max']] = [1901, 2155];
        } elseif ($base === 'char' || $base === 'varchar') {
            $column['kind'] = 'text';
            $column['maxLength'] = $args === '' ? null : (int) $args;
        } elseif (in_array($base, ['tinytext', 'text', 'mediumtext', 'longtext'], true)) {
            $column['kind'] = 'textarea';
            $column['maxLength'] = $base === 'tinytext' ? 255 : null;
        } elseif ($base === 'decimal' || $base === 'numeric') {
            [$precision, $scale] = array_map(intval(...), array_pad(explode(',', $args === '' ? '10' : $args), 2, '0'));
            $column = self::number($column, $precision, $scale);
            if ($unsigned) {
                $column['min'] = 0;
            }
        } elseif (in_array($base, ['float', 'double', 'real'], true)) {
            $column['kind'] = 'float';
            $column['min'] = $unsigned ? 0 : null;
        } elseif ($base === 'date') {
            $column['kind'] = 'date';
        } elseif ($base === 'datetime' || $base === 'timestamp') {
            $column['kind'] = 'datetime';
        } elseif ($base === 'time') {
            $column['kind'] = 'time';
        } elseif ($base === 'enum') {
            preg_match_all("/'((?:[^']|'')*)'/", $args, $values);
            $column['kind'] = 'enum';
            $column['options'] = array_map(static fn (string $value): string => str_replace("''", "'", $value), $values[1]);
        }

        return $column;
    }

    /**
     * NUMBER(p, s) / DECIMAL(p, s): integer if s = 0 and fits in integer range, otherwise decimal.
     *
     * @param  array{kind: string, dbType: string, nullable: bool, hasDefault: bool, generated: bool, maxLength: ?int, min: int|string|null, max: int|string|null, precision: ?int, scale: ?int, options: ?list<string>, comment: string}  $column
     * @return array{kind: string, dbType: string, nullable: bool, hasDefault: bool, generated: bool, maxLength: ?int, min: int|string|null, max: int|string|null, precision: ?int, scale: ?int, options: ?list<string>, comment: string}
     */
    private static function number(array $column, ?int $precision, ?int $scale): array
    {
        if ($precision === null) {
            $column['kind'] = ($scale === 0) ? 'int' : 'float';
            if ($scale === 0) {
                [$column['min'], $column['max']] = [PHP_INT_MIN, PHP_INT_MAX];
            }

            return $column;
        }

        $scale ??= 0;
        $column['precision'] = $precision;
        $column['scale'] = $scale;

        if ($scale === 0 && $precision <= 18) {
            $column['kind'] = 'int';
            $limit = (int) str_repeat('9', $precision);
            [$column['min'], $column['max']] = [-$limit, $limit];

            return $column;
        }

        $column['kind'] = 'decimal';
        $digits = str_repeat('9', max(1, $precision - $scale)).($scale > 0 ? '.'.str_repeat('9', $scale) : '');
        [$column['min'], $column['max']] = ['-'.$digits, $digits];

        return $column;
    }

    /**
     * @return array{kind: string, dbType: string, nullable: bool, hasDefault: bool, generated: bool, maxLength: ?int, min: int|string|null, max: int|string|null, precision: ?int, scale: ?int, options: ?list<string>, comment: string}
     */
    private static function blank(string $dbType): array
    {
        return [
            'kind' => 'unsupported', 'dbType' => $dbType, 'nullable' => true, 'hasDefault' => false, 'generated' => false,
            'maxLength' => null, 'min' => null, 'max' => null, 'precision' => null, 'scale' => null, 'options' => null, 'comment' => '',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     */
    private static function rowValue(array $row, array $keys): string|int|float|null
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                $value = $row[$key];

                return is_string($value) || is_int($value) || is_float($value) ? $value : null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     *
     * @throws InvalidArgumentException
     */
    private static function rowString(array $row, array $keys, string $method, int|string $index): string
    {
        $value = self::rowValue($row, $keys);
        if ($value === null || (string) $value === '') {
            throw new InvalidArgumentException("FormBuilder::{$method}(): row {$index} has no '".implode("' / '", $keys)."' column (unexpected metadata format).");
        }

        return (string) $value;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function rowInt(array $row, string $key): ?int
    {
        $value = self::rowValue($row, [$key]);

        return $value === null || $value === '' ? null : (int) $value;
    }
}
