<?php

declare(strict_types=1);

namespace Foundry;

use Closure;
use DateTimeInterface;
use DOMDocument;
use DOMNode;
use DOMXPath;
use Generator;
use InvalidArgumentException;
use LibXMLError;
use LogicException;
use RuntimeException;
use SimpleXMLElement;
use Throwable;
use UnexpectedValueException;
use XMLReader;
use XMLWriter;

/**
 * Xml Class
 *
 * XML reading, validation, querying, and writing (e.g., e-Factura UBL, SOAP responses, exports),
 * with libxml errors converted to exceptions and free of classic parser vulnerabilities.
 *
 * Native function pitfalls it avoids:
 *   - simplexml_load_string() / DOMDocument::loadXML() return false and emit warnings, and with
 *     libxml_use_internal_errors(true) errors accumulate silently in a global list;
 *   - XXE and "billion laughs": documents with DOCTYPE are rejected by default, network access is disabled;
 *   - DOMXPath::query() with a faulty expression returns false; a missing node silently yields an empty string.
 *
 * The application's libxml state (libxml_use_internal_errors, error list) is restored after each call.
 *
 * Error messages contain line, column, and libxml error text; document values are masked ('?'),
 * because libxml includes them in validation errors (e.g. "'1960101123456' is not a valid value").
 *
 * Large volumes: records() streams repeated elements from a large file (constant memory),
 * and writer() writes records one by one inside the consumer's loop.
 *
 * Usage:
 *   require_once 'Xml.php';
 *   use Foundry\Xml;
 *
 *   $invoice = Xml::documentFile('invoice.xml');
 *   Xml::validate($invoice, __DIR__.'/xsd/UBL-Invoice-2.1.xsd');
 *   $ns = ['cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2'];
 *   $number = Xml::value($invoice, '//cbc:ID', $ns);                 // exactly one node, otherwise exception
 *
 *   foreach (Xml::records('export.xml', 'Policy') as $policy) { ... } // SimpleXMLElement, streamed
 *
 *   $out = Xml::writer('report.xml', 'Policies');
 *   foreach ($rows as $row) {
 *       $out->add('Policy', ['@id' => $row['id'], 'Client' => $row['client']]);
 *   }
 *   $out->close();
 *
 * @category Utilities
 *
 * @version  1.0
 *
 * @since    PHP 8.3
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
final class Xml
{
    /** @var int libxml options: no network access, no entity substitution, no DTD loading. */
    private const int PARSE_OPTIONS = LIBXML_NONET | LIBXML_NOCDATA;

    /** @var int How many libxml errors are included in an exception message. */
    private const int ERRORS_IN_MESSAGE = 5;

    /** @var string Element or attribute name, optionally prefixed (cbc:ID). */
    private const string NAME_PATTERN = '/^(?:([A-Za-z_][A-Za-z0-9._-]*):)?[A-Za-z_][A-Za-z0-9._-]*$/D';

    /** @var string Characters prohibited in XML 1.0. */
    private const string INVALID_CHARACTERS = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x{FFFE}\x{FFFF}]/u';

    private ?XMLWriter $xml;

    private bool $closed = false;

    private int $written = 0;

    /**
     * @param  list<string>  $prefixes
     */
    private function __construct(
        XMLWriter $xml,
        private readonly string $temp,
        private readonly string $path,
        private readonly array $prefixes,
    ) {
        $this->xml = $xml;
    }

    /**
     * An abandoned file (without close(), e.g. after an exception) does not appear at destination.
     */
    public function __destruct()
    {
        if (! $this->closed) {
            $this->abort();
        }
    }

    /**
     * Document as SimpleXMLElement.
     *
     * @throws UnexpectedValueException If the XML is invalid or contains DOCTYPE (without $allowDoctype).
     */
    public static function parse(string $xml, bool $allowDoctype = false): SimpleXMLElement
    {
        return self::simple('parse', self::load('parse', $xml, null, $allowDoctype));
    }

    /**
     * File as SimpleXMLElement.
     *
     * @throws RuntimeException If the file cannot be read.
     * @throws UnexpectedValueException If the XML is invalid or contains DOCTYPE (without $allowDoctype).
     */
    public static function parseFile(string $path, bool $allowDoctype = false): SimpleXMLElement
    {
        return self::simple('parseFile', self::load('parseFile', self::read('parseFile', $path), $path, $allowDoctype));
    }

    /**
     * Document as DOMDocument (for XPath, validation, mutations).
     *
     * @throws UnexpectedValueException If the XML is invalid or contains DOCTYPE (without $allowDoctype).
     */
    public static function document(string $xml, bool $allowDoctype = false): DOMDocument
    {
        return self::load('document', $xml, null, $allowDoctype);
    }

    /**
     * File as DOMDocument.
     *
     * @throws RuntimeException If the file cannot be read.
     * @throws UnexpectedValueException If the XML is invalid or contains DOCTYPE (without $allowDoctype).
     */
    public static function documentFile(string $path, bool $allowDoctype = false): DOMDocument
    {
        return self::load('documentFile', self::read('documentFile', $path), $path, $allowDoctype);
    }

    /**
     * Validates the document against an XSD schema; all violations are reported in the message (first 5).
     *
     * @throws RuntimeException If the schema does not exist.
     * @throws UnexpectedValueException If the document does not match the schema or the schema is invalid.
     */
    public static function validate(DOMDocument $document, string $schema): void
    {
        $errors = self::schemaErrors($document, $schema);

        if ($errors !== []) {
            throw new UnexpectedValueException("Xml::validate(): the document does not match '{$schema}': ".self::summary($errors));
        }
    }

    /**
     * Document violations against an XSD schema (empty list = valid), for display or reporting.
     *
     * @return list<array{line: int, column: int, message: string}>
     *
     * @throws RuntimeException If the schema does not exist.
     * @throws UnexpectedValueException If the schema itself is invalid.
     */
    public static function schemaErrors(DOMDocument $document, string $schema): array
    {
        if (! is_file($schema) || ! is_readable($schema)) {
            throw new RuntimeException("Xml::schemaErrors(): schema '{$schema}' does not exist or is not readable.");
        }

        [$valid, $errors, $warning] = self::libxml(static fn (): bool => $document->schemaValidate($schema));

        // O schema care nu se poate compila produce avertismentul "Invalid Schema"; erorile sunt ale schemei.
        if (! $valid && ($errors === [] || ($warning !== null && str_contains($warning, 'Invalid Schema')))) {
            throw new UnexpectedValueException("Xml::schemaErrors(): schema '{$schema}' is invalid: ".self::summary($errors));
        }

        return array_map(static fn (array $error): array => ['line' => $error['line'], 'column' => $error['column'], 'message' => $error['message']], $errors);
    }

    /**
     * Text of the single node matched by XPath expression; zero or more than one node is an error.
     *
     * @param  array<string, string>  $namespaces  Prefix => URI, for prefixes in the expression.
     *
     * @throws InvalidArgumentException If the expression or prefixes are invalid.
     * @throws UnexpectedValueException If the expression does not match exactly one node.
     */
    public static function value(DOMNode $context, string $expression, array $namespaces = []): string
    {
        $nodes = self::find('value', $context, $expression, $namespaces);

        if (count($nodes) !== 1) {
            throw new UnexpectedValueException("Xml::value(): '{$expression}' matched ".count($nodes).' nodes, expected exactly 1.');
        }

        return $nodes[0]->textContent;
    }

    /**
     * Texts of all nodes matched by XPath expression (empty list if none found).
     *
     * @param  array<string, string>  $namespaces
     * @return list<string>
     *
     * @throws InvalidArgumentException If the expression or prefixes are invalid.
     */
    public static function values(DOMNode $context, string $expression, array $namespaces = []): array
    {
        return array_map(static fn (DOMNode $node): string => $node->textContent, self::find('values', $context, $expression, $namespaces));
    }

    /**
     * Nodes matched by XPath expression.
     *
     * @param  array<string, string>  $namespaces
     * @return list<DOMNode>
     *
     * @throws InvalidArgumentException If the expression or prefixes are invalid.
     */
    public static function nodes(DOMNode $context, string $expression, array $namespaces = []): array
    {
        return self::find('nodes', $context, $expression, $namespaces);
    }

    /**
     * @param  array<string, string>  $namespaces
     * @return list<DOMNode>
     *
     * @throws InvalidArgumentException If the expression or prefixes are invalid.
     */
    private static function find(string $method, DOMNode $context, string $expression, array $namespaces): array
    {
        $document = $context instanceof DOMDocument ? $context : $context->ownerDocument;
        if (! $document instanceof DOMDocument) {
            throw new InvalidArgumentException("Xml::{$method}(): the node does not belong to a document.");
        }

        $xpath = new DOMXPath($document);
        foreach ($namespaces as $prefix => $uri) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9._-]*$/D', $prefix) !== 1 || $uri === '' || ! $xpath->registerNamespace($prefix, $uri)) {
                throw new InvalidArgumentException("Xml::{$method}(): invalid namespace prefix '{$prefix}' or empty URI.");
            }
        }

        [$result, $warning] = self::capture(static fn (): mixed => $xpath->query($expression, $context));
        if ($result === false || $warning !== null) {
            throw new InvalidArgumentException("Xml::{$method}(): invalid XPath expression '{$expression}' (or an undeclared namespace prefix).");
        }

        $nodes = [];
        foreach ($result as $node) {
            if ($node instanceof DOMNode) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * Matches $element elements from arbitrarily large files, streamed (constant memory), as SimpleXMLElement.
     * Nested elements inside another element with the same name are not read separately.
     * Generator key is the element position (zero-based).
     *
     * @param  string|null  $namespace  Element namespace URI (null = any).
     * @return Generator<int, SimpleXMLElement>
     *
     * @throws RuntimeException If the file cannot be read.
     * @throws UnexpectedValueException If the XML is invalid or contains DOCTYPE (without $allowDoctype).
     */
    public static function records(string $path, string $element, ?string $namespace = null, bool $allowDoctype = false): Generator
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Xml::records(): file '{$path}' does not exist or is not readable.");
        }

        $reader = new XMLReader;
        [$opened] = self::capture(static fn (): bool => $reader->open($path, null, self::PARSE_OPTIONS));
        if (! $opened) {
            throw new UnexpectedValueException("Xml::records(): cannot open '{$path}'.");
        }

        try {
            $index = 0;
            $positioned = false;
            while (true) {
                // Erorile sunt captate doar cat se citeste o inregistrare; intre ele, controlul este al apelantului.
                [$record, $errors] = self::libxml(static function () use ($reader, $element, $namespace, $allowDoctype, &$positioned): SimpleXMLElement|false|null {
                    while ($positioned || $reader->read()) {
                        $positioned = false;

                        if ($reader->nodeType === XMLReader::DOC_TYPE && ! $allowDoctype) {
                            return false;
                        }

                        if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== $element || ($namespace !== null && $reader->namespaceURI !== $namespace)) {
                            continue;
                        }

                        $node = $reader->expand();
                        if (! $node instanceof DOMNode) {
                            return null;
                        }

                        $document = new DOMDocument;
                        $document->appendChild($document->importNode($node, true));
                        $simple = simplexml_import_dom($document);

                        // Sare peste continutul elementului (deja citit) si ramane pe nodul urmator.
                        $reader->next();
                        $positioned = true;

                        return $simple instanceof SimpleXMLElement ? $simple : null;
                    }

                    return null;
                });

                if ($record === false) {
                    throw new UnexpectedValueException("Xml::records(): '{$path}' contains a DOCTYPE, which is not allowed.");
                }

                if (self::fatal($errors) !== []) {
                    throw new UnexpectedValueException("Xml::records(): '{$path}' is not valid XML: ".self::summary(self::fatal($errors)));
                }

                if ($record === null) {
                    break;
                }

                yield $index++ => $record;
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * XML representation of an array, under the $root root element.
     *
     * Format: 'Name' => value (text) or array (children); a list of values/arrays repeats the element;
     * '@attribute' => value; '#' => text content for elements that also have attributes. null omits the element.
     * bool -> true/false; DateTimeInterface -> 'Y-m-d\TH:i:sP' (for simple dates pass a string).
     *
     * @param  array<array-key, mixed>  $data
     * @param  array<string, string>  $namespaces  Prefix => URI ('' = default namespace), declared on root.
     *
     * @throws InvalidArgumentException If names, prefixes, or values are invalid.
     */
    public static function fromArray(string $root, array $data, array $namespaces = []): string
    {
        $prefixes = self::checkNamespaces('fromArray', $namespaces);
        $writer = new XMLWriter;
        $writer->openMemory();
        $writer->startDocument('1.0', 'UTF-8');
        self::startRoot('fromArray', $writer, $root, $namespaces, $prefixes);
        self::writeData('fromArray', $writer, $data, $root, $prefixes);
        $writer->endElement();
        $writer->endDocument();

        return $writer->outputMemory();
    }

    /**
     * An XML file written record by record: add() in a loop, then close().
     * The file appears at its destination only on close(), complete.
     *
     * @param  array<string, string>  $namespaces  Prefix => URI, declared on root.
     * @param  array<string, string|int|float|bool>  $attributes  Root attributes.
     *
     * @throws InvalidArgumentException If names or prefixes are invalid.
     * @throws RuntimeException If the file cannot be created.
     */
    public static function writer(string $path, string $root, array $namespaces = [], array $attributes = []): self
    {
        $prefixes = self::checkNamespaces('writer', $namespaces);

        $directory = dirname($path);
        if (! is_dir($directory)) {
            throw new RuntimeException("Xml::writer(): directory '{$directory}' does not exist.");
        }

        [$temp, $warning] = self::capture(static fn (): string|false => tempnam($directory, '.xml'));
        if ($temp === false) {
            throw new RuntimeException("Xml::writer(): cannot create a temporary file in '{$directory}': ".($warning ?? 'unknown error').'.');
        }

        $xml = new XMLWriter;
        if (! $xml->openUri($temp)) {
            self::capture(static fn (): bool => unlink($temp));

            throw new RuntimeException("Xml::writer(): cannot write '{$temp}'.");
        }

        $writer = new self($xml, $temp, $path, $prefixes);

        try {
            $xml->startDocument('1.0', 'UTF-8');
            self::startRoot('writer', $xml, $root, $namespaces, $prefixes);
            foreach ($attributes as $name => $value) {
                self::writeAttribute('writer', $xml, $name, $value, $root, $prefixes);
            }
        } catch (Throwable $e) {
            $writer->abort();

            throw $e;
        }

        return $writer;
    }

    /**
     * Adds a record under the root (same format as in fromArray()).
     *
     * @param  array<array-key, mixed>|string|int|float|bool|DateTimeInterface  $data
     *
     * @throws LogicException If the file has been closed.
     * @throws InvalidArgumentException If names, prefixes, or values are invalid.
     */
    public function add(string $element, array|string|int|float|bool|DateTimeInterface $data): void
    {
        if ($this->closed || ! $this->xml instanceof XMLWriter) {
            throw new LogicException('Xml::add(): the file is already closed.');
        }

        self::writeData('add', $this->xml, [$element => $data], '#'.$this->written, $this->prefixes);
        $this->written++;

        if ($this->written % 1000 === 0) {
            $this->xml->flush();
        }
    }

    /**
     * Closes the root element and moves the file to destination. A second call has no effect.
     *
     * @throws RuntimeException If the file cannot be written.
     */
    public function close(): void
    {
        if ($this->closed || ! $this->xml instanceof XMLWriter) {
            return;
        }

        try {
            $this->xml->endElement();
            $this->xml->endDocument();
            $this->xml->flush();
            $this->xml = null;

            $temp = $this->temp;
            $path = $this->path;
            [$renamed, $warning] = self::capture(static fn (): bool => rename($temp, $path));
            if (! $renamed) {
                throw new RuntimeException("Xml::close(): cannot write '{$path}': ".($warning ?? 'unknown error').'.');
            }
        } finally {
            $this->abort();
        }
    }

    /**
     * Number of added records.
     */
    public function count(): int
    {
        return $this->written;
    }

    /**
     * Aborts the file: closes and removes the temporary file.
     */
    private function abort(): void
    {
        $this->closed = true;
        $this->xml = null;

        $temp = $this->temp;
        if (is_file($temp)) {
            self::capture(static fn (): bool => unlink($temp));
        }
    }

    /**
     * Loads XML with safe options and converts libxml errors into an exception.
     *
     * @throws UnexpectedValueException If the XML is empty, invalid, or contains a disallowed DOCTYPE.
     */
    private static function load(string $method, string $xml, ?string $path, bool $allowDoctype): DOMDocument
    {
        $source = $path === null ? 'the input' : "'{$path}'";

        if (trim($xml) === '') {
            throw new UnexpectedValueException("Xml::{$method}(): {$source} is empty.");
        }

        $document = new DOMDocument;
        [$loaded, $errors] = self::libxml(static fn (): bool => $document->loadXML($xml, self::PARSE_OPTIONS));

        if (! $loaded || self::fatal($errors) !== []) {
            throw new UnexpectedValueException("Xml::{$method}(): {$source} is not valid XML: ".self::summary(self::fatal($errors) ?: $errors));
        }

        if ($document->doctype instanceof \DOMDocumentType && ! $allowDoctype) {
            throw new UnexpectedValueException("Xml::{$method}(): {$source} contains a DOCTYPE, which is not allowed (protection against XXE and entity expansion).");
        }

        return $document;
    }

    /**
     * @throws UnexpectedValueException If the document cannot be converted.
     */
    private static function simple(string $method, DOMDocument $document): SimpleXMLElement
    {
        $simple = simplexml_import_dom($document);

        return $simple instanceof SimpleXMLElement ? $simple : throw new UnexpectedValueException("Xml::{$method}(): the document has no root element.");
    }

    /**
     * @throws RuntimeException If the file cannot be read.
     */
    private static function read(string $method, string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Xml::{$method}(): file '{$path}' does not exist or is not readable.");
        }

        [$content, $warning] = self::capture(static fn (): string|false => file_get_contents($path));
        if ($content === false) {
            throw new RuntimeException("Xml::{$method}(): cannot read '{$path}': ".($warning ?? 'unknown error').'.');
        }

        return $content;
    }

    /**
     * Runs a libxml operation with locally collected errors; restores application libxml state.
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return array{0: T, 1: list<array{line: int, column: int, message: string, level: int}>, 2: string|null} [result, libxml errors, PHP warning]
     */
    private static function libxml(Closure $operation): array
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            [$result, $warning] = self::capture($operation);
            $errors = array_map(static fn (LibXMLError $error): array => [
                'line' => $error->line,
                'column' => $error->column,
                'message' => self::mask(trim($error->message)),
                'level' => $error->level,
            ], libxml_get_errors());

            return [$result, $errors, $warning];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * Fatal errors (excluding warnings).
     *
     * @param  list<array{line: int, column: int, message: string, level: int}>  $errors
     * @return list<array{line: int, column: int, message: string, level: int}>
     */
    private static function fatal(array $errors): array
    {
        return array_values(array_filter($errors, static fn (array $error): bool => $error['level'] !== LIBXML_ERR_WARNING));
    }

    /**
     * Values in libxml messages replaced with '?' (element and attribute names remain).
     */
    private static function mask(string $message): string
    {
        return (string) preg_replace(
            ["/((?:Element|attribute) '[^']*': )'[^']*'/", "/The value '[^']*'/", "/(\\[facet '[^']*'\\] )'[^']*'/"],
            ["\$1'?'", "The value '?'", "\$1'?'"],
            $message,
        );
    }

    /**
     * First errors for exception message: 'line 3, column 7: ...; ... (and 4 more).'
     *
     * @param  list<array{line: int, column: int, message: string, level?: int}>  $errors
     */
    private static function summary(array $errors): string
    {
        if ($errors === []) {
            return 'unknown error.';
        }

        $parts = array_map(
            static fn (array $error): string => ($error['line'] > 0 ? "line {$error['line']}, column {$error['column']}: " : '').rtrim($error['message'], '.'),
            array_slice($errors, 0, self::ERRORS_IN_MESSAGE),
        );
        $more = count($errors) - self::ERRORS_IN_MESSAGE;

        return implode('; ', $parts).($more > 0 ? " (and {$more} more)" : '').'.';
    }

    /**
     * @param  array<string, string>  $namespaces
     * @return list<string> Declared prefixes.
     *
     * @throws InvalidArgumentException If a prefix or URI is invalid.
     */
    private static function checkNamespaces(string $method, array $namespaces): array
    {
        foreach ($namespaces as $prefix => $uri) {
            if (($prefix !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9._-]*$/D', $prefix) !== 1) || $uri === '' || strtolower(substr($prefix, 0, 3)) === 'xml') {
                throw new InvalidArgumentException("Xml::{$method}(): invalid namespace prefix '{$prefix}' or empty URI.");
            }
        }

        return array_values(array_filter(array_keys($namespaces), static fn (string $prefix): bool => $prefix !== ''));
    }

    /**
     * @param  array<string, string>  $namespaces
     * @param  list<string>  $prefixes
     *
     * @throws InvalidArgumentException If the root name is invalid.
     */
    private static function startRoot(string $method, XMLWriter $writer, string $root, array $namespaces, array $prefixes): void
    {
        self::checkName($method, $root, $root, $prefixes);
        $writer->startElement($root);

        foreach ($namespaces as $prefix => $uri) {
            $writer->writeAttribute($prefix === '' ? 'xmlns' : 'xmlns:'.$prefix, $uri);
        }
    }

    /**
     * Writes array element content (see fromArray()).
     *
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $prefixes
     *
     * @throws InvalidArgumentException If names or values are invalid.
     */
    private static function writeData(string $method, XMLWriter $writer, array $data, string $path, array $prefixes): void
    {
        // Atributele trebuie scrise inaintea copiilor.
        foreach ($data as $key => $value) {
            if (is_string($key) && str_starts_with($key, '@')) {
                if ($value !== null) {
                    self::writeAttribute($method, $writer, substr($key, 1), $value, $path, $prefixes);
                }
            }
        }

        foreach ($data as $key => $value) {
            if (is_string($key) && str_starts_with($key, '@')) {
                continue;
            }

            if ($key === '#') {
                if ($value !== null) {
                    $writer->text(self::text($method, $value, $path));
                }

                continue;
            }

            if (! is_string($key)) {
                throw new InvalidArgumentException("Xml::{$method}(): '{$path}' has a list where element names were expected.");
            }

            $childPath = $path.'/'.$key;
            self::checkName($method, $key, $childPath, $prefixes);

            $items = is_array($value) && array_is_list($value) && $value !== [] ? $value : [$value];
            foreach ($items as $item) {
                if ($item === null) {
                    continue;
                }

                $writer->startElement($key);
                if (is_array($item)) {
                    self::writeData($method, $writer, $item, $childPath, $prefixes);
                } else {
                    $writer->text(self::text($method, $item, $childPath));
                }
                $writer->endElement();
            }
        }
    }

    /**
     * @param  list<string>  $prefixes
     *
     * @throws InvalidArgumentException If the attribute name or value is invalid.
     */
    private static function writeAttribute(string $method, XMLWriter $writer, string $name, mixed $value, string $path, array $prefixes): void
    {
        self::checkName($method, $name, $path.'/@'.$name, $prefixes);
        $writer->writeAttribute($name, self::text($method, $value, $path.'/@'.$name));
    }

    /**
     * @param  list<string>  $prefixes
     *
     * @throws InvalidArgumentException If the name is invalid or the prefix is not declared.
     */
    private static function checkName(string $method, string $name, string $path, array $prefixes): void
    {
        if (preg_match(self::NAME_PATTERN, $name, $match) !== 1 || str_starts_with(strtolower($name), 'xml')) {
            throw new InvalidArgumentException("Xml::{$method}(): invalid XML name '{$name}' at '{$path}'.");
        }

        $prefix = $match[1] ?? '';
        if ($prefix !== '' && ! in_array($prefix, $prefixes, true)) {
            throw new InvalidArgumentException("Xml::{$method}(): namespace prefix '{$prefix}' at '{$path}' is not declared.");
        }
    }

    /**
     * Value as XML text: valid UTF-8, no prohibited characters.
     *
     * @throws InvalidArgumentException If the type is unsupported or the text is invalid.
     */
    private static function text(string $method, mixed $value, string $path): string
    {
        $text = match (true) {
            is_string($value) => $value,
            is_int($value) => (string) $value,
            is_float($value) => is_finite($value) ? var_export($value, true) : throw new InvalidArgumentException("Xml::{$method}(): number at '{$path}' must be finite."),
            is_bool($value) => $value ? 'true' : 'false',
            $value instanceof DateTimeInterface => $value->format('Y-m-d\TH:i:sP'),
            default => throw new InvalidArgumentException("Xml::{$method}(): unsupported type ".get_debug_type($value)." at '{$path}'."),
        };

        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidArgumentException("Xml::{$method}(): text at '{$path}' is not valid UTF-8.");
        }

        if (preg_match(self::INVALID_CHARACTERS, $text) === 1) {
            throw new InvalidArgumentException("Xml::{$method}(): text at '{$path}' contains characters not allowed in XML.");
        }

        return $text;
    }

    /**
     * Runs a native function and captures any emitted warning instead of displaying it.
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return array{0: T, 1: string|null} [result, warning message or null]
     */
    private static function capture(Closure $operation): array
    {
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            return [$operation(), $warning];
        } finally {
            restore_error_handler();
        }
    }
}
