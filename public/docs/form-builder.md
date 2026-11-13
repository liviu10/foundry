# FormBuilder

> Schema-driven form structure generation and Validator rule mapping directly from table column metadata.

Canonical: https://foundry.insightdevhub.ro/docs/form-builder/  
Namespace: `Foundry\FormBuilder` · File: `FormBuilder.php` · Version 1.1 · PHP 8.3+

Generates form field definitions and validation rules from database table column metadata. Returns data rather than HTML: field type, label, required flag, max length, numeric limits, and options; the application template determines presentation. Rules follow the Validator format, ensuring form definition and validation come from a single source of truth and remain in sync.

Data sources (rows queried by the application):

```text
fromMySql:  SHOW FULL COLUMNS FROM `table` (or INFORMATION_SCHEMA.COLUMNS with COLUMN_TYPE)
fromOracle: SELECT * FROM ALL_TAB_COLUMNS WHERE OWNER = ? AND TABLE_NAME = ? (column names become lowercase)
fromSqlite: PRAGMA table_info(table)
```

## Rules

- database-generated columns (AUTO_INCREMENT, computed columns, INTEGER PRIMARY KEY in SQLite) are excluded automatically; others can be filtered with only() / except()
- required = NOT NULL without a default value, and not a checkbox
- types without a form field counterpart (BLOB, JSON, SET, RAW...) throw an exception if retained
- the object is immutable: every modification returns a clone.

## Usage

```php
require_once 'FormBuilder.php';
use Foundry\FormBuilder;

$form = FormBuilder::fromMySql($db->fetchAll('SHOW FULL COLUMNS FROM `policies`'))
    ->only('number', 'type', 'amount', 'start_date', 'email', 'notes')
    ->label('number', 'Policy number')
    ->options('type', ['RCA' => 'RCA', 'CASCO' => 'CASCO'])
    ->type('email', 'email')
    ->type('notes', 'textarea');

$fields = $form->fields($policy);                     // for template rendering
$validator = new Validator($_POST, $form->rules());   // identical validation rules
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/FormBuilder.php';

use Foundry\FormBuilder;
```

## Methods

### fromMySql()

```php
public static function fromMySql(array $rows): self
```

From SHOW [FULL] COLUMNS (Field, Type, Null, Default, Extra, Comment) or INFORMATION_SCHEMA.COLUMNS (COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_COMMENT).

Parameters:
- `$rows` (`list<array<string, mixed>>`, required)

Returns `Foundry\FormBuilder`.

Throws:
- `InvalidArgumentException`: If rows do not match the expected structure.

### fromOracle()

```php
public static function fromOracle(array $rows): self
```

From ALL_TAB_COLUMNS / USER_TAB_COLUMNS (COLUMN_NAME, DATA_TYPE, NULLABLE, CHAR_LENGTH, DATA_PRECISION, DATA_SCALE, DATA_DEFAULT). Column names are lowercased (as in Oracle.php). DATE becomes a date field; for date and time use type('column', 'datetime-local').

Parameters:
- `$rows` (`list<array<string, mixed>>`, required)

Returns `Foundry\FormBuilder`.

Throws:
- `InvalidArgumentException`: If rows do not match the expected structure.

### fromSqlite()

```php
public static function fromSqlite(array $rows): self
```

From PRAGMA table_info (name, type, notnull, dflt_value, pk).

Parameters:
- `$rows` (`list<array<string, mixed>>`, required)

Returns `Foundry\FormBuilder`.

Throws:
- `InvalidArgumentException`: If rows do not match the expected structure.

### only()

```php
public function only(string ...$names): self
```

Selects only these fields, in this order (can include generated columns, e.g. for display).

Parameters:
- `$names` (`string`)

Returns `Foundry\FormBuilder`.

Throws:
- `InvalidArgumentException`: If a column does not exist.

### except()

```php
public function except(string ...$names): self
```

Parameters:
- `$names` (`string`)

Returns `Foundry\FormBuilder`.

Throws:
- `InvalidArgumentException`: If a column does not exist.

### label()

```php
public function label(string $name, string $label): self
```

Parameters:
- `$name` (`string`, required)
- `$label` (`string`, required)

Returns `Foundry\FormBuilder`.

Throws:
- `InvalidArgumentException`: If the column does not exist or the label is empty.

### labels()

```php
public function labels(array $labels): self
```

Parameters:
- `$labels` (`array<string, string>`, required): column => label

Returns `Foundry\FormBuilder`.

Throws:
- `InvalidArgumentException`

### options()

```php
public function options(string $name, array $options): self
```

Options for a select field (value => label); the field becomes a select and the rule becomes in:.

Parameters:
- `$name` (`string`, required)
- `$options` (`array<int|string, string>`, required)

Returns `Foundry\FormBuilder`.

Throws:
- `InvalidArgumentException`: If the list is empty or any value contains a comma.

### type()

```php
public function type(string $name, string $type): self
```

Field type: text, textarea, password, email, tel, url, number, date, datetime-local, time, checkbox, select, hidden. email adds the email rule; checkbox adds the bool rule; datetime-local adds date/time validation.

Parameters:
- `$name` (`string`, required)
- `$type` (`string`, required)

Returns `Foundry\FormBuilder`.

Throws:
- `InvalidArgumentException`: If the type is not supported.

### help()

```php
public function help(string $name, string $help): self
```

Helper text displayed alongside the field.

Parameters:
- `$name` (`string`, required)
- `$help` (`string`, required)

Returns `Foundry\FormBuilder`.

Throws:
- `InvalidArgumentException`

### required()

```php
public function required(string $name, bool $required = true): self
```

Overrides whether the field is required.

Parameters:
- `$name` (`string`, required)
- `$required` (`bool`, default `true`)

Returns `Foundry\FormBuilder`.

Throws:
- `InvalidArgumentException`

### addRules()

```php
public function addRules(string $name, string|array $rules): self
```

Additional Validator rules (e.g. 'regex:/^[A-Z]{2}\d+$/', 'min:3').

Parameters:
- `$name` (`string`, required)
- `$rules` (`string|list<string>`, required)

Returns `Foundry\FormBuilder`.

Throws:
- `InvalidArgumentException`

### fields()

```php
public function fields(array $values = []): array
```

Form fields, in order.

Parameters:
- `$values` (`array<array-key, mixed>`, default `[]`): Current values (e.g. database record or $_POST after a validation failure).

Returns `list<array{name: string, label: string, type: string, required: bool, value: mixed, options: ?list<array{value: string, label: string}>, maxlength: ?int, min: int|string|null, max: int|string|null, step: ?string, help: ?string}>`.

Throws:
- `InvalidArgumentException`: If a field has a type without a form counterpart.

### rules()

```php
public function rules(): array
```

Validator rules indexed by field (list format). An enum whose values contain a comma or an empty value gets a Closure rule under the key 'enum' (strict comparison) instead of 'in:...'.

Returns `array<string, array<int|string, string|Closure>>`.

Throws:
- `InvalidArgumentException`: If a field has a type without a form counterpart.

### names()

```php
public function names(): array
```

Form field names, in order.

Returns `list<string>`.

