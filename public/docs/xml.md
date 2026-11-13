# Xml

> Secure XML parsing (immune to XXE), XSD schema validation (e.g., ANAF e-Factura), XPath querying, streaming I/O.

Canonical: https://foundry.insightdevhub.ro/docs/xml/  
Namespace: `Foundry\Xml` · File: `Xml.php` · Version 1.0 · PHP 8.3+ · Extensions: mbstring, xmlreader, xmlwriter, dom, simplexml

XML reading, validation, querying, and writing (e.g., e-Factura UBL, SOAP responses, exports), with libxml errors converted to exceptions and free of classic parser vulnerabilities.

## Native function pitfalls it avoids

- simplexml_load_string() / DOMDocument::loadXML() return false and emit warnings, and with libxml_use_internal_errors(true) errors accumulate silently in a global list
- XXE and "billion laughs": documents with DOCTYPE are rejected by default, network access is disabled
- DOMXPath::query() with a faulty expression returns false; a missing node silently yields an empty string.

The application's libxml state (libxml_use_internal_errors, error list) is restored after each call.

Error messages contain line, column, and libxml error text; document values are masked ('?'), because libxml includes them in validation errors (e.g. "'1960101123456' is not a valid value").

Large volumes: records() streams repeated elements from a large file (constant memory), and writer() writes records one by one inside the consumer's loop.

## Usage

```php
require_once 'Xml.php';
use Foundry\Xml;

$invoice = Xml::documentFile('invoice.xml');
Xml::validate($invoice, __DIR__.'/xsd/UBL-Invoice-2.1.xsd');
$ns = ['cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2'];
$number = Xml::value($invoice, '//cbc:ID', $ns);                 // exactly one node, otherwise exception

foreach (Xml::records('export.xml', 'Policy') as $policy) { ... } // SimpleXMLElement, streamed

$out = Xml::writer('report.xml', 'Policies');
foreach ($rows as $row) {
    $out->add('Policy', ['@id' => $row['id'], 'Client' => $row['client']]);
}
$out->close();
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Xml.php';

use Foundry\Xml;
```

## Methods

### __destruct()

```php
public function __destruct()
```

An abandoned file (without close(), e.g. after an exception) does not appear at destination.

### parse()

```php
public static function parse(string $xml, bool $allowDoctype = false): SimpleXMLElement
```

Document as SimpleXMLElement.

Parameters:
- `$xml` (`string`, required)
- `$allowDoctype` (`bool`, default `false`)

Returns `SimpleXMLElement`.

Throws:
- `UnexpectedValueException`: If the XML is invalid or contains DOCTYPE (without $allowDoctype).

### parseFile()

```php
public static function parseFile(string $path, bool $allowDoctype = false): SimpleXMLElement
```

File as SimpleXMLElement.

Parameters:
- `$path` (`string`, required)
- `$allowDoctype` (`bool`, default `false`)

Returns `SimpleXMLElement`.

Throws:
- `RuntimeException`: If the file cannot be read.
- `UnexpectedValueException`: If the XML is invalid or contains DOCTYPE (without $allowDoctype).

### document()

```php
public static function document(string $xml, bool $allowDoctype = false): DOMDocument
```

Document as DOMDocument (for XPath, validation, mutations).

Parameters:
- `$xml` (`string`, required)
- `$allowDoctype` (`bool`, default `false`)

Returns `DOMDocument`.

Throws:
- `UnexpectedValueException`: If the XML is invalid or contains DOCTYPE (without $allowDoctype).

### documentFile()

```php
public static function documentFile(string $path, bool $allowDoctype = false): DOMDocument
```

File as DOMDocument.

Parameters:
- `$path` (`string`, required)
- `$allowDoctype` (`bool`, default `false`)

Returns `DOMDocument`.

Throws:
- `RuntimeException`: If the file cannot be read.
- `UnexpectedValueException`: If the XML is invalid or contains DOCTYPE (without $allowDoctype).

### validate()

```php
public static function validate(DOMDocument $document, string $schema): void
```

Validates the document against an XSD schema; all violations are reported in the message (first 5).

Parameters:
- `$document` (`DOMDocument`, required)
- `$schema` (`string`, required)

Throws:
- `RuntimeException`: If the schema does not exist.
- `UnexpectedValueException`: If the document does not match the schema or the schema is invalid.

### schemaErrors()

```php
public static function schemaErrors(DOMDocument $document, string $schema): array
```

Document violations against an XSD schema (empty list = valid), for display or reporting.

Parameters:
- `$document` (`DOMDocument`, required)
- `$schema` (`string`, required)

Returns `list<array{line: int, column: int, message: string}>`.

Throws:
- `RuntimeException`: If the schema does not exist.
- `UnexpectedValueException`: If the schema itself is invalid.

### value()

```php
public static function value(DOMNode $context, string $expression, array $namespaces = []): string
```

Text of the single node matched by XPath expression; zero or more than one node is an error.

Parameters:
- `$context` (`DOMNode`, required)
- `$expression` (`string`, required)
- `$namespaces` (`array<string, string>`, default `[]`): Prefix => URI, for prefixes in the expression.

Returns `string`.

Throws:
- `InvalidArgumentException`: If the expression or prefixes are invalid.
- `UnexpectedValueException`: If the expression does not match exactly one node.

### values()

```php
public static function values(DOMNode $context, string $expression, array $namespaces = []): array
```

Texts of all nodes matched by XPath expression (empty list if none found).

Parameters:
- `$context` (`DOMNode`, required)
- `$expression` (`string`, required)
- `$namespaces` (`array<string, string>`, default `[]`)

Returns `list<string>`.

Throws:
- `InvalidArgumentException`: If the expression or prefixes are invalid.

### nodes()

```php
public static function nodes(DOMNode $context, string $expression, array $namespaces = []): array
```

Nodes matched by XPath expression.

Parameters:
- `$context` (`DOMNode`, required)
- `$expression` (`string`, required)
- `$namespaces` (`array<string, string>`, default `[]`)

Returns `list<DOMNode>`.

Throws:
- `InvalidArgumentException`: If the expression or prefixes are invalid.

### records()

```php
public static function records(string $path, string $element, ?string $namespace = null, bool $allowDoctype = false): Generator
```

Matches $element elements from arbitrarily large files, streamed (constant memory), as SimpleXMLElement. Nested elements inside another element with the same name are not read separately. Generator key is the element position (zero-based).

Parameters:
- `$path` (`string`, required)
- `$element` (`string`, required)
- `$namespace` (`string|null`, default `null`): Element namespace URI (null = any).
- `$allowDoctype` (`bool`, default `false`)

Returns `Generator<int, SimpleXMLElement>`.

Throws:
- `RuntimeException`: If the file cannot be read.
- `UnexpectedValueException`: If the XML is invalid or contains DOCTYPE (without $allowDoctype).

### fromArray()

```php
public static function fromArray(string $root, array $data, array $namespaces = []): string
```

XML representation of an array, under the $root root element.

Format: 'Name' => value (text) or array (children); a list of values/arrays repeats the element; '@attribute' => value; '#' => text content for elements that also have attributes. null omits the element. bool -> true/false; DateTimeInterface -> 'Y-m-d\TH:i:sP' (for simple dates pass a string).

Parameters:
- `$root` (`string`, required)
- `$data` (`array<array-key, mixed>`, required)
- `$namespaces` (`array<string, string>`, default `[]`): Prefix => URI ('' = default namespace), declared on root.

Returns `string`.

Throws:
- `InvalidArgumentException`: If names, prefixes, or values are invalid.

### writer()

```php
public static function writer(string $path, string $root, array $namespaces = [], array $attributes = []): self
```

An XML file written record by record: add() in a loop, then close(). The file appears at its destination only on close(), complete.

Parameters:
- `$path` (`string`, required)
- `$root` (`string`, required)
- `$namespaces` (`array<string, string>`, default `[]`): Prefix => URI, declared on root.
- `$attributes` (`array<string, string|int|float|bool>`, default `[]`): Root attributes.

Returns `Foundry\Xml`.

Throws:
- `InvalidArgumentException`: If names or prefixes are invalid.
- `RuntimeException`: If the file cannot be created.

### add()

```php
public function add(string $element, array|string|int|float|bool|DateTimeInterface $data): void
```

Adds a record under the root (same format as in fromArray()).

Parameters:
- `$element` (`string`, required)
- `$data` (`array<array-key, mixed>|string|int|float|bool|DateTimeInterface`, required)

Throws:
- `LogicException`: If the file has been closed.
- `InvalidArgumentException`: If names, prefixes, or values are invalid.

### close()

```php
public function close(): void
```

Closes the root element and moves the file to destination. A second call has no effect.

Throws:
- `RuntimeException`: If the file cannot be written.

### count()

```php
public function count(): int
```

Number of added records.

Returns `int`.

