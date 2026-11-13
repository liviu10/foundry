<?php

declare(strict_types=1);

namespace Foundry\Tests;

use Foundry\Url;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use UnexpectedValueException;

final class UrlTest extends TestCase
{
    // parse

    public function test_parse(): void
    {
        $this->assertSame([
            'scheme' => 'https',
            'host' => 'app.example.com',
            'port' => 8443,
            'user' => 'u',
            'pass' => 'p',
            'path' => '/polite',
            'query' => 'id=5',
            'fragment' => 'sus',
        ], Url::parse('HTTPS://u:p@App.Example.com:8443/polite?id=5#sus'));

        $this->assertSame('', Url::parse('?a=1')['path']);
        $this->assertNull(Url::parse('/cale')['host']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedProvider(): iterable
    {
        yield 'gol' => [''];
        yield 'spatiu' => ['/a b'];
        yield 'linie noua' => ["/a\r\nSet-Cookie: x=1"];
        yield 'backslash' => ['/\\evil.ro'];
        yield 'port invalid' => ['http://a.ro:99999/'];
        yield 'doar schema' => ['http://'];
    }

    #[DataProvider('malformedProvider')]
    public function test_parse_rejects_malformed(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^Url::parse\(\): /');

        Url::parse($url);
    }

    // with / without

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function withProvider(): iterable
    {
        yield 'fara query' => ['/polite', ['page' => 2], '/polite?page=2'];
        yield 'inlocuieste' => ['/polite?page=2&q=x', ['page' => 3], '/polite?q=x&page=3'];
        yield 'null sterge' => ['/polite?page=2&q=x', ['q' => null], '/polite?page=2'];
        yield 'null pe tot' => ['/polite?q=x', ['q' => null], '/polite'];
        yield 'pastreaza restul exact' => ['/p?a.b=1&c=%20', ['d' => 1], '/p?a.b=1&c=%20&d=1'];
        yield 'fragment' => ['/p?a=1#sus', ['b' => 2], '/p?a=1&b=2#sus'];
        yield 'codare RFC 3986' => ['/p', ['q' => 'a b&c=d'], '/p?q=a%20b%26c%3Dd'];
        yield 'UTF-8' => ['/p', ['oras' => "Ia\u{0219}i"], '/p?oras=Ia%C8%99i'];
        yield 'array' => ['/p', ['id' => [1, 2]], '/p?id%5B0%5D=1&id%5B1%5D=2'];
        yield 'inlocuieste array' => ['/p?id[]=1&id[]=2&x=1', ['id' => 3], '/p?x=1&id=3'];
        yield 'bool' => ['/p', ['activ' => true, 'sters' => false], '/p?activ=1&sters=0'];
        yield 'absolut' => ['https://a.ro/p?x=1', ['y' => 2], 'https://a.ro/p?x=1&y=2'];
    }

    /**
     * @param  array<string, mixed>  $query
     */
    #[DataProvider('withProvider')]
    public function test_with(string $url, array $query, string $expected): void
    {
        $this->assertSame($expected, Url::with($url, $query));
    }

    public function test_with_rejects_objects(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Url::with(): query values must be scalars or arrays, got stdClass.');

        Url::with('/p', ['x' => ['y' => new stdClass]]);
    }

    public function test_without(): void
    {
        $this->assertSame('/p?b=2#f', Url::without('/p?a=1&b=2&c[]=3#f', 'a', 'c'));
        $this->assertSame('/p', Url::without('/p?a=1', 'a'));
        $this->assertSame('/p?a=1', Url::without('/p?a=1', 'x'));
    }

    // absolute

    public function test_absolute(): void
    {
        $this->assertSame('https://app.ro/portal/polite?id=5', Url::absolute('https://app.ro/portal/', '/polite', ['id' => 5]));
        $this->assertSame('https://app.ro/polite', Url::absolute('https://app.ro', 'polite'));
        $this->assertSame('https://app.ro/', Url::absolute('https://app.ro'));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function invalidAbsoluteProvider(): iterable
    {
        yield 'baza relativa' => ['/portal', 'x', 'the base must be an absolute http(s) URL'];
        yield 'baza ftp' => ['ftp://a.ro', 'x', 'the base must be an absolute http(s) URL'];
        yield 'baza cu query' => ['https://a.ro/?x=1', 'p', 'must not contain a query string or a fragment'];
        yield 'cale absoluta' => ['https://a.ro', 'https://evil.ro/x', 'the path must be relative to the base'];
        yield 'cale //' => ['https://a.ro', '//evil.ro/x', 'the path must be relative to the base'];
        yield 'javascript' => ['https://a.ro', 'javascript:alert(1)', 'the path must be relative to the base'];
    }

    #[DataProvider('invalidAbsoluteProvider')]
    public function test_absolute_rejects(string $base, string $path, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Url::absolute($base, $path);
    }

    // current

    public function test_current(): void
    {
        $server = ['HTTP_HOST' => 'App.Example.com', 'REQUEST_URI' => '/polite?page=2', 'HTTPS' => 'on'];

        $this->assertSame('https://app.example.com/polite?page=2', Url::current(['app.example.com'], $server));
    }

    public function test_current_with_port_and_http(): void
    {
        $this->assertSame('http://localhost:8080/', Url::current(['localhost'], ['HTTP_HOST' => 'localhost:8080', 'REQUEST_URI' => '/']));
        $this->assertSame('http://a.ro/x', Url::current(['a.ro'], ['HTTP_HOST' => 'a.ro:80', 'REQUEST_URI' => '/x', 'HTTPS' => 'off']));
        $this->assertSame('https://a.ro/x', Url::current(['a.ro'], ['HTTP_HOST' => 'a.ro', 'REQUEST_URI' => '/x', 'SERVER_PORT' => 443]));
    }

    public function test_current_wildcard_host(): void
    {
        $server = ['HTTP_HOST' => 'test.example.com', 'REQUEST_URI' => '/'];

        $this->assertSame('http://test.example.com/', Url::current(['*.example.com'], $server));

        $this->expectException(UnexpectedValueException::class);
        Url::current(['*.example.com'], ['HTTP_HOST' => 'evilexample.com', 'REQUEST_URI' => '/']);
    }

    public function test_current_rejects_untrusted_host(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Url::current(): the request host is not in the list of trusted hosts.');

        Url::current(['app.example.com'], ['HTTP_HOST' => 'evil.ro', 'REQUEST_URI' => '/reset']);
    }

    public function test_current_rejects_invalid_host(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Url::current(): the request has no valid host.');

        Url::current(['a.ro'], ['HTTP_HOST' => 'a.ro/evil', 'REQUEST_URI' => '/']);
    }

    public function test_current_requires_trusted_hosts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Url::current([], ['HTTP_HOST' => 'a.ro']);
    }

    public function test_current_forwarded_proto_only_when_trusted(): void
    {
        $server = ['HTTP_HOST' => 'a.ro', 'REQUEST_URI' => '/', 'HTTP_X_FORWARDED_PROTO' => 'https'];

        $this->assertSame('http://a.ro/', Url::current(['a.ro'], $server));
        $this->assertSame('https://a.ro/', Url::current(['a.ro'], $server, trustForwarded: true));
    }

    public function test_current_normalizes_request_target(): void
    {
        $this->assertSame('http://a.ro/x?y=1', Url::current(['a.ro'], ['HTTP_HOST' => 'a.ro', 'REQUEST_URI' => 'http://other/x?y=1']));
        $this->assertSame('http://a.ro/', Url::current(['a.ro'], ['HTTP_HOST' => 'a.ro', 'REQUEST_URI' => '*']));
        $this->assertSame('http://a.ro/', Url::current(['a.ro'], ['HTTP_HOST' => 'a.ro']));
    }

    // isLocal

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function localProvider(): iterable
    {
        yield 'radacina' => ['/', true];
        yield 'cale cu query' => ['/polite?id=1#x', true];
        yield 'gol' => ['', false];
        yield 'relativ' => ['polite', false];
        yield 'extern' => ['https://evil.ro', false];
        yield 'relativ la schema' => ['//evil.ro', false];
        yield 'backslash' => ['/\\evil.ro', false];
        yield 'backslash in interior' => ['/a\\b', false];
        yield 'javascript' => ['javascript:alert(1)', false];
        yield 'tab' => ["/\t/evil.ro", false];
        yield 'linie noua' => ["/a\nb", false];
    }

    #[DataProvider('localProvider')]
    public function test_is_local(string $url, bool $local): void
    {
        $this->assertSame($local, Url::isLocal($url));
    }
}
