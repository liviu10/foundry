<?php

declare(strict_types=1);

namespace Foundry\Tests;

use DateTimeImmutable;
use Foundry\File;
use Foundry\Xml;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

final class XmlTest extends TestCase
{
    private const string CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private const string INVOICE = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<Invoice xmlns="urn:test:invoice" xmlns:cbc="'.self::CBC.'">'
        .'<cbc:ID>F-2026-001</cbc:ID><cbc:IssueDate>2026-03-15</cbc:IssueDate>'
        .'<Line><cbc:Amount currencyID="RON">100.50</cbc:Amount></Line>'
        .'<Line><cbc:Amount currencyID="RON">20.00</cbc:Amount></Line>'
        .'</Invoice>';

    private const string XSD = '<?xml version="1.0"?>'
        .'<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" targetNamespace="urn:test:polite" xmlns="urn:test:polite" elementFormDefault="qualified">'
        .'<xs:element name="Polite"><xs:complexType><xs:sequence>'
        .'<xs:element name="Polita" maxOccurs="unbounded"><xs:complexType><xs:sequence>'
        .'<xs:element name="Cnp"><xs:simpleType><xs:restriction base="xs:string"><xs:pattern value="\d{13}"/></xs:restriction></xs:simpleType></xs:element>'
        .'<xs:element name="Suma" type="xs:decimal"/>'
        .'</xs:sequence></xs:complexType></xs:element>'
        .'</xs:sequence><xs:attribute name="generat" type="xs:date"/></xs:complexType></xs:element>'
        .'</xs:schema>';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/utilities-xml-'.bin2hex(random_bytes(6));
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

    private function polite(string $cnp, string $suma): string
    {
        return '<?xml version="1.0"?><Polite xmlns="urn:test:polite"><Polita><Cnp>'.$cnp.'</Cnp><Suma>'.$suma.'</Suma></Polita></Polite>';
    }

    // citire

    public function test_parse_and_document(): void
    {
        $simple = Xml::parse(self::INVOICE);
        $this->assertSame('F-2026-001', (string) $simple->children(self::CBC)->ID);

        $document = Xml::document(self::INVOICE);
        $this->assertSame('Invoice', $document->documentElement?->localName);

        $path = $this->file('f.xml', self::INVOICE);
        $this->assertSame('2026-03-15', (string) Xml::parseFile($path)->children(self::CBC)->IssueDate);
        $this->assertSame('Invoice', Xml::documentFile($path)->documentElement?->localName);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidXmlProvider(): iterable
    {
        yield 'tag neinchis' => ['<a><b></a>', 'is not valid XML: line 1, column'];
        yield 'text simplu' => ['nu este xml', 'is not valid XML'];
        yield 'gol' => ['  ', 'the input is empty'];
        yield 'doua radacini' => ['<a/><b/>', 'is not valid XML'];
    }

    #[DataProvider('invalidXmlProvider')]
    public function test_invalid_xml(string $xml, string $message): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        Xml::parse($xml);
    }

    public function test_doctype_is_rejected_and_entities_are_never_loaded(): void
    {
        $secret = $this->file('secret.txt', 'parola-secreta');
        $xxe = '<?xml version="1.0"?><!DOCTYPE a [<!ENTITY xxe SYSTEM "file:///'.str_replace('\\', '/', $secret).'">]><a>&xxe;</a>';

        try {
            Xml::parse($xxe);
            $this->fail('Expected UnexpectedValueException');
        } catch (UnexpectedValueException $e) {
            $this->assertStringContainsString('contains a DOCTYPE, which is not allowed', $e->getMessage());
        }

        // Chiar cu DOCTYPE permis, entitatile externe nu sunt incarcate.
        $document = Xml::document($xxe, allowDoctype: true);
        $this->assertStringNotContainsString('parola-secreta', (string) $document->saveXML());
        $this->assertStringNotContainsString('parola-secreta', (string) $document->documentElement?->textContent);
    }

    public function test_missing_file(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist or is not readable');

        Xml::parseFile($this->dir.'/lipsa.xml');
    }

    public function test_libxml_state_of_the_application_is_restored(): void
    {
        $previous = libxml_use_internal_errors(false);

        try {
            try {
                Xml::parse('<a>');
            } catch (UnexpectedValueException) {
            }

            $this->assertFalse(libxml_use_internal_errors());
            $this->assertSame([], libxml_get_errors());
        } finally {
            libxml_use_internal_errors($previous);
        }
    }

    // validare XSD

    public function test_validate(): void
    {
        $schema = $this->file('polite.xsd', self::XSD);

        Xml::validate(Xml::document($this->polite('1960101123456', '100.50')), $schema);
        $this->assertSame([], Xml::schemaErrors(Xml::document($this->polite('1960101123456', '1')), $schema));
    }

    public function test_validation_errors_do_not_contain_values(): void
    {
        $schema = $this->file('polite.xsd', self::XSD);
        $document = Xml::document($this->polite('1960101', 'o suta'));

        $errors = Xml::schemaErrors($document, $schema);
        $this->assertCount(2, $errors);
        $this->assertSame(1, $errors[0]['line']);

        try {
            Xml::validate($document, $schema);
            $this->fail('Expected UnexpectedValueException');
        } catch (UnexpectedValueException $e) {
            $this->assertStringStartsWith("Xml::validate(): the document does not match '{$schema}': line 1", $e->getMessage());
            $this->assertStringContainsString("Element '{urn:test:polite}Cnp'", $e->getMessage());
            $this->assertStringNotContainsString('1960101', $e->getMessage());
            $this->assertStringNotContainsString('o suta', $e->getMessage());
        }
    }

    public function test_invalid_or_missing_schema(): void
    {
        try {
            Xml::validate(Xml::document('<a/>'), $this->dir.'/lipsa.xsd');
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('does not exist', $e->getMessage());
        }

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('is invalid');

        Xml::validate(Xml::document('<a/>'), $this->file('rau.xsd', '<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"><xs:element name="a" type="nu-exista"/></xs:schema>'));
    }

    // XPath

    public function test_value_values_and_nodes(): void
    {
        $document = Xml::document(self::INVOICE);
        $ns = ['cbc' => self::CBC, 'i' => 'urn:test:invoice'];

        $this->assertSame('F-2026-001', Xml::value($document, '//cbc:ID', $ns));
        $this->assertSame(['100.50', '20.00'], Xml::values($document, '//i:Line/cbc:Amount', $ns));
        $this->assertSame([], Xml::values($document, '//cbc:Missing', $ns));
        $this->assertSame('RON', Xml::value(Xml::nodes($document, '//i:Line', $ns)[1], 'cbc:Amount/@currencyID', $ns), 'relativ la un nod');
    }

    public function test_value_requires_exactly_one_node(): void
    {
        $document = Xml::document(self::INVOICE);
        $ns = ['cbc' => self::CBC];

        foreach (['//cbc:Missing' => 0, '//cbc:Amount' => 2] as $expression => $count) {
            try {
                Xml::value($document, $expression, $ns);
                $this->fail('Expected UnexpectedValueException');
            } catch (UnexpectedValueException $e) {
                $this->assertSame("Xml::value(): '{$expression}' matched {$count} nodes, expected exactly 1.", $e->getMessage());
            }
        }
    }

    public function test_invalid_xpath_and_undeclared_prefix(): void
    {
        $document = Xml::document(self::INVOICE);

        foreach (['//cbc:ID', '//[', ''] as $expression) {
            try {
                Xml::values($document, $expression);
                $this->fail("Expected InvalidArgumentException for '{$expression}'");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('invalid XPath expression', $e->getMessage());
            }
        }
    }

    // records (pe flux)

    public function test_records_stream_large_files(): void
    {
        $path = $this->dir.'/mare.xml';
        $handle = fopen($path, 'w');
        $this->assertIsResource($handle);
        fwrite($handle, '<?xml version="1.0"?><Export xmlns:x="urn:x"><Antet><Polita>nu</Polita></Antet>');
        for ($i = 1; $i <= 5000; $i++) {
            fwrite($handle, "<x:Polita nr=\"{$i}\"><Client>C{$i}</Client></x:Polita>");
        }
        fwrite($handle, '</Export>');
        fclose($handle);

        $count = 0;
        foreach (Xml::records($path, 'Polita', 'urn:x') as $index => $polita) {
            $this->assertSame($count, $index);
            $this->assertSame((string) ($count + 1), (string) $polita['nr']);
            $this->assertSame('C'.($count + 1), (string) $polita->Client);
            $count++;
        }

        $this->assertSame(5000, $count);
        $this->assertCount(5001, iterator_to_array(Xml::records($path, 'Polita'), false), 'fara namespace: si cel din antet');
    }

    public function test_records_report_invalid_xml_after_valid_records(): void
    {
        // Eroarea este departe de inceput: libxml citeste in avans cateva KB, deci o eroare apropiata
        // ar aparea inaintea primelor inregistrari.
        $path = $this->file('rupt.xml', '<?xml version="1.0"?><E>'.str_repeat('<R>x</R>', 5000).'<R>3</X></E>');

        $read = 0;
        try {
            foreach (Xml::records($path, 'R') as $record) {
                $read++;
            }
            $this->fail('Expected UnexpectedValueException');
        } catch (UnexpectedValueException $e) {
            $this->assertStringContainsString("'{$path}' is not valid XML: line 1", $e->getMessage());
        }

        $this->assertGreaterThan(1000, $read);
        $this->assertLessThan(5001, $read);
    }

    public function test_records_reject_doctype(): void
    {
        $path = $this->file('d.xml', '<?xml version="1.0"?><!DOCTYPE E [<!ENTITY a "x">]><E><R>&a;</R></E>');

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('contains a DOCTYPE, which is not allowed');

        iterator_to_array(Xml::records($path, 'R'));
    }

    // scriere

    public function test_from_array(): void
    {
        $xml = Xml::fromArray('Invoice', [
            'cbc:ID' => 'F-1 & <test>',
            'cbc:IssueDate' => '2026-03-15',
            'Line' => [
                ['@nr' => 1, 'cbc:Amount' => ['@currencyID' => 'RON', '#' => 100.5]],
                ['@nr' => 2, 'cbc:Amount' => ['@currencyID' => 'RON', '#' => 20]],
            ],
            'Paid' => true,
            'Created' => new DateTimeImmutable('2026-03-15 10:30:00', new \DateTimeZone('Europe/Bucharest')),
            'Note' => null,
            'Empty' => [],
        ], ['' => 'urn:test:invoice', 'cbc' => self::CBC]);

        $document = Xml::document($xml);
        $ns = ['cbc' => self::CBC, 'i' => 'urn:test:invoice'];

        $this->assertSame('F-1 & <test>', Xml::value($document, '//cbc:ID', $ns));
        $this->assertSame(['100.5', '20'], Xml::values($document, '//i:Line/cbc:Amount', $ns));
        $this->assertSame(['1', '2'], Xml::values($document, '//i:Line/@nr', $ns));
        $this->assertSame('true', Xml::value($document, '//i:Paid', $ns));
        $this->assertSame('2026-03-15T10:30:00+02:00', Xml::value($document, '//i:Created', $ns));
        $this->assertSame([], Xml::values($document, '//i:Note', $ns), 'null omite elementul');
        $this->assertSame([''], Xml::values($document, '//i:Empty', $ns));
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, string}>
     */
    public static function invalidDataProvider(): iterable
    {
        yield 'nume invalid' => [['1abc' => 'x'], "invalid XML name '1abc' at 'R/1abc'"];
        yield 'prefix nedeclarat' => [['cac:Line' => 'x'], "namespace prefix 'cac' at 'R/cac:Line' is not declared"];
        yield 'caracter de control' => [['A' => "x\x01y"], "text at 'R/A' contains characters not allowed in XML"];
        yield 'tip nesuportat' => [['A' => new \stdClass], "unsupported type stdClass at 'R/A'"];
        yield 'lista fara nume' => [['A' => ['x', ['y']]], "'R/A' has a list where element names were expected"];
        yield 'atribut invalid' => [['@a b' => 'x'], "invalid XML name 'a b'"];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    #[DataProvider('invalidDataProvider')]
    public function test_from_array_rejects_invalid_data(array $data, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Xml::fromArray('R', $data, ['cbc' => self::CBC]);
    }

    public function test_writer_in_a_loop(): void
    {
        $path = $this->dir.'/out.xml';
        $writer = Xml::writer($path, 'Polite', ['' => 'urn:test:polite'], ['generat' => '2026-03-15']);
        foreach (['1960101123456' => '100.50', '2960101123456' => '7'] as $cnp => $suma) {
            $writer->add('Polita', ['Cnp' => (string) $cnp, 'Suma' => $suma]);
        }

        $this->assertFileDoesNotExist($path, 'fisierul apare abia la close()');
        $writer->close();
        $writer->close();

        $this->assertSame(2, $writer->count());
        Xml::validate(Xml::documentFile($path), $this->file('polite.xsd', self::XSD));
        $this->assertSame('2026-03-15', Xml::value(Xml::documentFile($path), '/p:Polite/@generat', ['p' => 'urn:test:polite']));
        $this->assertSame(['out.xml', 'polite.xsd'], array_values(array_diff((array) scandir($this->dir), ['.', '..'])));

        $this->expectException(LogicException::class);
        $writer->add('Polita', []);
    }

    public function test_abandoned_writer_leaves_nothing(): void
    {
        $writer = Xml::writer($this->dir.'/a.xml', 'R');
        $writer->add('A', 'x');

        try {
            $writer->add('B', "\x02");
        } catch (InvalidArgumentException) {
        }
        unset($writer);

        $this->assertSame([], array_values(array_diff((array) scandir($this->dir), ['.', '..'])));
    }

    public function test_no_warnings_reach_host_error_handler(): void
    {
        $triggered = false;
        set_error_handler(static function () use (&$triggered): bool {
            $triggered = true;

            return true;
        });

        try {
            foreach (['<a>', '<a/><b/>', '<a>&nope;</a>'] as $xml) {
                try {
                    Xml::parse($xml);
                } catch (UnexpectedValueException) {
                }
            }
            try {
                Xml::values(Xml::document('<a/>'), '//[');
            } catch (InvalidArgumentException) {
            }
            try {
                iterator_to_array(Xml::records($this->file('r.xml', '<a><b></a>'), 'b'));
            } catch (UnexpectedValueException) {
            }
        } finally {
            restore_error_handler();
        }

        $this->assertFalse($triggered);
    }
}
