<?php

declare(strict_types=1);

namespace Foundry\Tests;

use Foundry\Breadcrumbs;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BreadcrumbsTest extends TestCase
{
    public function test_items(): void
    {
        $breadcrumbs = (new Breadcrumbs)->add('Acasa', '/')->add(' Polite ', '/polite?page=2')->add('Polita 123');

        $this->assertSame([
            ['label' => 'Acasa', 'url' => '/', 'active' => false, 'position' => 1],
            ['label' => 'Polite', 'url' => '/polite?page=2', 'active' => false, 'position' => 2],
            ['label' => 'Polita 123', 'url' => null, 'active' => true, 'position' => 3],
        ], $breadcrumbs->items());
        $this->assertSame(3, $breadcrumbs->count());
        $this->assertFalse($breadcrumbs->isEmpty());
    }

    public function test_empty(): void
    {
        $breadcrumbs = new Breadcrumbs;

        $this->assertSame([], $breadcrumbs->items());
        $this->assertTrue($breadcrumbs->isEmpty());
        $this->assertSame(0, $breadcrumbs->count());
    }

    public function test_is_immutable(): void
    {
        $base = (new Breadcrumbs)->add('Acasa', '/');
        $polite = $base->add('Polite', '/polite');
        $clienti = $base->add('Clienti', '/clienti');

        $this->assertSame(1, $base->count());
        $this->assertSame('Polite', $polite->items()[1]['label']);
        $this->assertSame('Clienti', $clienti->items()[1]['label']);
        $this->assertTrue($base->items()[0]['active']);
    }

    public function test_labels_are_not_escaped(): void
    {
        $item = (new Breadcrumbs)->add('<b>Ion & Co</b>')->items()[0];

        $this->assertSame('<b>Ion & Co</b>', $item['label']);
    }

    public function test_absolute_url(): void
    {
        $this->assertSame('https://portal.example.com/x', (new Breadcrumbs)->add('Portal', 'https://portal.example.com/x')->items()[0]['url']);
    }

    /**
     * @return iterable<string, array{string, ?string, string}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'eticheta goala' => ['  ', null, 'the label is empty or not valid UTF-8'];
        yield 'eticheta UTF-8 invalid' => ["a\xE2", null, 'the label is empty or not valid UTF-8'];
        yield 'url gol' => ['A', '', 'the URL is empty'];
        yield 'javascript' => ['A', 'javascript:alert(1)', 'must be a local path (/...) or an absolute http(s) URL'];
        yield 'relativ la schema' => ['A', '//evil.ro', 'must be a local path'];
        yield 'relativ' => ['A', 'polite', 'must be a local path'];
        yield 'backslash' => ['A', '/\\evil.ro', 'backslashes'];
        yield 'linie noua' => ['A', "/a\nb", 'control characters'];
        yield 'ftp' => ['A', 'ftp://a.ro', 'must be a local path'];
    }

    #[DataProvider('invalidProvider')]
    public function test_invalid(string $label, ?string $url, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        (new Breadcrumbs)->add($label, $url);
    }

    public function test_schema(): void
    {
        $breadcrumbs = (new Breadcrumbs)->add('Acasa', '/')->add('Portal', 'https://portal.ro/p')->add('Polita 123');

        $this->assertSame([
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Acasa', 'item' => 'https://app.example.com/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Portal', 'item' => 'https://portal.ro/p'],
                ['@type' => 'ListItem', 'position' => 3, 'name' => 'Polita 123'],
            ],
        ], $breadcrumbs->schema('https://app.example.com/'));
    }

    public function test_schema_json_is_safe_inside_script_tag(): void
    {
        $json = (new Breadcrumbs)->add("</script><script>alert('x')</script> \u{0219}", '/a?b=1&c=2')->schemaJson('https://a.ro');

        $this->assertStringNotContainsString('</script>', $json);
        $this->assertStringNotContainsString('&', $json);
        $this->assertStringContainsString("\u{0219}", $json);
        $this->assertStringNotContainsString('<', $json);
        $this->assertStringNotContainsString("'", $json);
        $this->assertSame((new Breadcrumbs)->add("</script><script>alert('x')</script> \u{0219}", '/a?b=1&c=2')->schema('https://a.ro'), json_decode($json, true));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBaseProvider(): iterable
    {
        yield 'relativ' => ['/app'];
        yield 'cu query' => ['https://a.ro/?x=1'];
        yield 'ftp' => ['ftp://a.ro'];
        yield 'gol' => [''];
    }

    #[DataProvider('invalidBaseProvider')]
    public function test_schema_rejects_invalid_base(string $base): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Breadcrumbs::schema(): the base URL must be an absolute http(s) URL');

        (new Breadcrumbs)->add('A', '/')->schema($base);
    }
}
