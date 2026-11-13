<?php

declare(strict_types=1);

namespace Foundry\Tests;

use Foundry\Request;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class RequestTest extends TestCase
{
    // Metoda, adresa, header-e

    public function test_method(): void
    {
        $this->assertSame('GET', (new Request)->method());
        $this->assertSame('POST', (new Request(server: ['REQUEST_METHOD' => 'post']))->method());
        $this->assertTrue((new Request(server: ['REQUEST_METHOD' => 'POST']))->isPost());
        $this->assertTrue((new Request(server: ['REQUEST_METHOD' => 'GET']))->isGet());
        $this->assertTrue((new Request(server: ['REQUEST_METHOD' => 'DELETE']))->isMethod('delete'));
    }

    public function test_invalid_method(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Request::method(): invalid HTTP method.');

        (new Request(server: ['REQUEST_METHOD' => "GET\r\nX"]))->method();
    }

    public function test_uri_and_path(): void
    {
        $request = new Request(server: ['REQUEST_URI' => '/polite/5?page=2']);
        $this->assertSame('/polite/5?page=2', $request->uri());
        $this->assertSame('/polite/5', $request->path());

        $this->assertSame('/x', (new Request(server: ['REQUEST_URI' => 'http://host/x']))->path());
        $this->assertSame('/', (new Request(server: ['REQUEST_URI' => '*']))->uri());
        $this->assertSame('/', (new Request)->path());
    }

    public function test_headers(): void
    {
        $request = new Request(server: [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            'CONTENT_TYPE' => 'application/json; charset=utf-8',
            'HTTP_USER_AGENT' => 'Test/1.0',
            'PATH' => '/usr/bin',
            'REQUEST_METHOD' => 'POST',
        ]);

        $this->assertSame('application/json', $request->header('accept'));
        $this->assertSame('application/json; charset=utf-8', $request->header('Content-Type'));
        $this->assertSame('Test/1.0', $request->userAgent());
        $this->assertNull($request->header('Path'));
        $this->assertNull($request->header('X-Missing'));
        $this->assertTrue($request->isAjax());
        $this->assertTrue($request->isJson());
        $this->assertTrue($request->wantsJson());
        $this->assertSame([
            'accept' => 'application/json',
            'x-requested-with' => 'XMLHttpRequest',
            'content-type' => 'application/json; charset=utf-8',
            'user-agent' => 'Test/1.0',
        ], $request->headers());
    }

    public function test_is_json_variants(): void
    {
        $this->assertTrue((new Request(server: ['CONTENT_TYPE' => 'application/problem+json']))->isJson());
        $this->assertFalse((new Request(server: ['CONTENT_TYPE' => 'application/jsonp']))->isJson());
        $this->assertFalse((new Request(server: ['CONTENT_TYPE' => 'text/html']))->isJson());
        $this->assertFalse((new Request)->wantsJson());
    }

    public function test_is_secure(): void
    {
        $this->assertTrue((new Request(server: ['HTTPS' => 'on']))->isSecure());
        $this->assertTrue((new Request(server: ['SERVER_PORT' => 443]))->isSecure());
        $this->assertFalse((new Request(server: ['HTTPS' => 'off']))->isSecure());
        $this->assertFalse((new Request(server: ['HTTP_X_FORWARDED_PROTO' => 'https']))->isSecure());
        $this->assertTrue((new Request(server: ['HTTP_X_FORWARDED_PROTO' => 'https, http']))->isSecure(trustForwarded: true));
        $this->assertFalse((new Request(server: ['HTTPS' => 'on', 'HTTP_X_FORWARDED_PROTO' => 'http']))->isSecure(trustForwarded: true));
    }

    public function test_bearer_token_and_cookie(): void
    {
        $request = new Request(server: ['HTTP_AUTHORIZATION' => 'Bearer abc.def'], cookies: ['tema' => 'dark', 'x' => ['y']]);

        $this->assertSame('abc.def', $request->bearerToken());
        $this->assertSame('dark', $request->cookie('tema'));
        $this->assertNull($request->cookie('x'));
        $this->assertNull($request->cookie('lipsa'));
        $this->assertNull((new Request(server: ['HTTP_AUTHORIZATION' => 'Basic dTpw']))->bearerToken());
        $this->assertSame('t', (new Request(server: ['REDIRECT_HTTP_AUTHORIZATION' => 'bearer t']))->bearerToken());
    }

    // ip

    public function test_ip_without_proxies_ignores_forwarded_header(): void
    {
        $request = new Request(server: ['REMOTE_ADDR' => '203.0.113.5', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4']);

        $this->assertSame('203.0.113.5', $request->ip());
    }

    public function test_ip_behind_trusted_proxy(): void
    {
        $request = new Request(server: ['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 198.51.100.7, 10.0.0.9']);

        // De la dreapta: 10.0.0.9 este proxy, 198.51.100.7 este clientul (1.2.3.4 poate fi falsificat).
        $this->assertSame('198.51.100.7', $request->ip(['10.0.0.0/8']));
        $this->assertSame('10.0.0.9', $request->ip(['10.0.0.2']));
    }

    public function test_ip_from_untrusted_remote_ignores_header(): void
    {
        $request = new Request(server: ['REMOTE_ADDR' => '203.0.113.5', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4']);

        $this->assertSame('203.0.113.5', $request->ip(['10.0.0.0/8']));
    }

    public function test_ip_stops_at_invalid_forwarded_entry(): void
    {
        $request = new Request(server: ['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, nu-e-ip']);

        $this->assertSame('10.0.0.2', $request->ip(['10.0.0.0/8']));
    }

    public function test_ip_v6_and_partial_prefix(): void
    {
        $request = new Request(server: ['REMOTE_ADDR' => '2001:db8::1', 'HTTP_X_FORWARDED_FOR' => '2001:db9::5']);
        $this->assertSame('2001:db9::5', $request->ip(['2001:db8::/32']));

        $request = new Request(server: ['REMOTE_ADDR' => '192.168.1.130', 'HTTP_X_FORWARDED_FOR' => '8.8.8.8']);
        $this->assertSame('8.8.8.8', $request->ip(['192.168.1.128/25']));
        $this->assertSame('192.168.1.130', $request->ip(['192.168.1.0/25']));
    }

    public function test_ip_requires_valid_remote_addr(): void
    {
        $this->expectException(UnexpectedValueException::class);

        (new Request(server: ['REMOTE_ADDR' => 'x']))->ip();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidProxyProvider(): iterable
    {
        yield 'text' => ['proxy'];
        yield 'prefix text' => ['10.0.0.0/abc'];
        yield 'prefix prea mare' => ['10.0.0.0/33'];
        yield 'prefix gol' => ['10.0.0.0/'];
    }

    #[DataProvider('invalidProxyProvider')]
    public function test_ip_rejects_invalid_trusted_proxy(string $proxy): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Request::ip(): invalid trusted proxy '{$proxy}'");

        (new Request(server: ['REMOTE_ADDR' => '203.0.113.5']))->ip([$proxy]);
    }

    // Parametri

    public function test_query_post_input(): void
    {
        $request = new Request(
            query: ['id' => '1', 'page' => '2'],
            post: ['id' => '5', 'nume' => 'Ion'],
            server: ['REQUEST_METHOD' => 'POST'],
        );

        $this->assertSame('1', $request->query('id'));
        $this->assertSame('5', $request->post('id'));
        $this->assertSame('5', $request->input('id'));
        $this->assertSame('2', $request->input('page'));
        $this->assertNull($request->input('lipsa'));
        $this->assertSame('x', $request->input('lipsa', 'x'));
        $this->assertSame(['id' => '5', 'page' => '2', 'nume' => 'Ion'], $request->all());
        $this->assertSame(['id' => '5', 'nume' => 'Ion'], $request->only('id', 'nume', 'lipsa'));
        $this->assertSame(['page' => '2'], $request->except('id', 'nume'));
        $this->assertTrue($request->has('nume'));
        $this->assertFalse($request->has('lipsa'));
    }

    public function test_filled(): void
    {
        $request = new Request(query: ['a' => ' ', 'b' => '0', 'c' => [], 'd' => null]);

        $this->assertFalse($request->filled('a'));
        $this->assertTrue($request->filled('b'));
        $this->assertFalse($request->filled('c'));
        $this->assertFalse($request->filled('d'));
        $this->assertTrue($request->has('d'));
    }

    public function test_json_body(): void
    {
        $request = new Request(
            query: ['page' => '2'],
            server: ['REQUEST_METHOD' => 'PATCH', 'CONTENT_TYPE' => 'application/json'],
            body: '{"nume":"Ion","id":12345678901234567890}',
        );

        $this->assertSame('Ion', $request->input('nume'));
        $this->assertSame('12345678901234567890', $request->input('id'));
        $this->assertSame('2', $request->input('page'));
        $this->assertSame(['nume' => 'Ion', 'id' => '12345678901234567890'], $request->json());
    }

    public function test_invalid_json_body(): void
    {
        $request = new Request(server: ['CONTENT_TYPE' => 'application/json'], body: '{"cnp":"1800101420010"');

        try {
            $request->input('x');
            $this->fail('Expected exception.');
        } catch (UnexpectedValueException $e) {
            $this->assertStringStartsWith('Request::json(): Syntax error (body length: 22 bytes).', $e->getMessage());
            $this->assertStringNotContainsString('1800101420010', $e->getMessage());
        }
    }

    public function test_json_scalar_and_empty(): void
    {
        try {
            (new Request(body: '5'))->json();
            $this->fail('Expected exception.');
        } catch (UnexpectedValueException $e) {
            $this->assertSame('Request::json(): expected a JSON object or array, got int.', $e->getMessage());
        }

        // Corp JSON gol: fara parametri, dar json() explicit este eroare.
        $request = new Request(server: ['CONTENT_TYPE' => 'application/json'], body: '');
        $this->assertSame([], $request->all());

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Request::json(): the request body is empty.');
        $request->json();
    }

    public function test_urlencoded_body_for_put(): void
    {
        $request = new Request(
            server: ['REQUEST_METHOD' => 'PUT', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            body: 'nume=Ion+Pop&tags%5B%5D=a&tags%5B%5D=b',
        );

        $this->assertSame('Ion Pop', $request->input('nume'));
        $this->assertSame(['a', 'b'], $request->array('tags'));
    }

    public function test_get_ignores_body(): void
    {
        $request = new Request(server: ['REQUEST_METHOD' => 'GET'], body: 'a=1');

        $this->assertSame([], $request->all());
    }

    // Getter-e tipizate

    public function test_string(): void
    {
        $request = new Request(query: ['nume' => ' Ion ', 'gol' => '  ', 'lista' => ['a'], 'rau' => "a\xE2"]);

        $this->assertSame(' Ion ', $request->string('nume'));
        $this->assertNull($request->stringOrNull('gol'));
        $this->assertNull($request->stringOrNull('lipsa'));

        $this->assertThrowsMessage(static fn (): string => $request->string('gol'), "Request::string(): parameter 'gol' is missing or empty.");
        $this->assertThrowsMessage(static fn (): string => $request->string('lista'), "Request::string(): parameter 'lista' must be text.");
        $this->assertThrowsMessage(static fn (): ?string => $request->stringOrNull('rau'), "Request::stringOrNull(): parameter 'rau' must be valid UTF-8 text.");
    }

    /**
     * @return iterable<string, array{mixed, ?int}>
     */
    public static function intProvider(): iterable
    {
        yield 'string' => ['12', 12];
        yield 'negativ' => ['-5', -5];
        yield 'spatii' => [' 7 ', 7];
        yield 'int din JSON' => [3, 3];
        yield 'zecimal' => ['1.0', null];
        yield 'sufix' => ['12abc', null];
        yield 'zerouri in fata' => ['012', null];
        yield 'overflow' => ['99999999999999999999', null];
        yield 'array' => [['1'], null];
        yield 'bool' => [true, null];
    }

    #[DataProvider('intProvider')]
    public function test_int(mixed $value, ?int $expected): void
    {
        $request = new Request(query: ['id' => $value]);

        if ($expected === null) {
            $this->assertThrowsMessage(static fn (): int => $request->int('id'), "Request::int(): parameter 'id' must be an integer.");
        } else {
            $this->assertSame($expected, $request->int('id'));
            $this->assertSame($expected, $request->intOrNull('id'));
        }
    }

    public function test_int_missing(): void
    {
        $request = new Request(query: ['page' => '']);

        $this->assertNull($request->intOrNull('page'));
        $this->assertNull($request->intOrNull('lipsa'));
        $this->assertThrowsMessage(static fn (): int => $request->int('page'), "Request::int(): parameter 'page' is missing or empty.");
    }

    public function test_float(): void
    {
        $request = new Request(query: ['a' => '1.50', 'b' => '-3', 'c' => '1,5', 'd' => '1e3', 'e' => 2]);

        $this->assertEqualsWithDelta(1.5, $request->float('a'), PHP_FLOAT_EPSILON);
        $this->assertSame(-3.0, $request->floatOrNull('b'));
        $this->assertEqualsWithDelta(2.0, $request->float('e'), PHP_FLOAT_EPSILON);
        $this->assertNull($request->floatOrNull('lipsa'));
        $this->assertThrowsMessage(static fn (): float => $request->float('c'), "Request::float(): parameter 'c' must be a number with \".\" as decimal separator.");
        $this->assertThrowsMessage(static fn (): ?float => $request->floatOrNull('d'), "Request::floatOrNull(): parameter 'd' must be a number");
        $this->assertThrowsMessage(static fn (): float => $request->float('lipsa'), "Request::float(): parameter 'lipsa' is missing or empty.");
    }

    public function test_bool(): void
    {
        $request = new Request(query: ['a' => 'on', 'b' => 'NU', 'c' => '1', 'd' => 'poate', 'e' => true, 'f' => 0]);

        $this->assertTrue($request->bool('a'));
        $this->assertFalse($request->bool('b'));
        $this->assertTrue($request->boolOrNull('c'));
        $this->assertTrue($request->bool('e'));
        $this->assertFalse($request->bool('f'));
        $this->assertNull($request->boolOrNull('checkbox'));
        $this->assertThrowsMessage(static fn (): bool => $request->bool('d'), "Request::bool(): parameter 'd' must be a boolean");
        $this->assertThrowsMessage(static fn (): bool => $request->bool('checkbox'), "Request::bool(): parameter 'checkbox' is missing or empty.");
    }

    public function test_date(): void
    {
        $request = new Request(query: ['a' => '15.03.2026', 'b' => '31.02.2026', 'c' => '2026-03-15', 'd' => '15.03.26', 'e' => ['x']]);

        $this->assertSame('2026-03-15 00:00:00', $request->date('a', 'd.m.Y')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-15', $request->dateOrNull('c')?->format('Y-m-d'));
        $this->assertNull($request->dateOrNull('lipsa'));
        $this->assertThrowsMessage(static fn (): \DateTimeImmutable => $request->date('b', 'd.m.Y'), "Request::date(): parameter 'b' must be a valid date in the format 'd.m.Y'.");
        $this->assertThrowsMessage(static fn (): \DateTimeImmutable => $request->date('d', 'd.m.Y'), "Request::date(): parameter 'd' must be a valid date");
        $this->assertThrowsMessage(static fn (): ?\DateTimeImmutable => $request->dateOrNull('e'), "Request::dateOrNull(): parameter 'e' must be a valid date");
        $this->assertThrowsMessage(static fn (): \DateTimeImmutable => $request->date('lipsa'), "Request::date(): parameter 'lipsa' is missing or empty.");
    }

    public function test_array(): void
    {
        $request = new Request(query: ['ids' => ['1', '2'], 'gol' => '', 'text' => 'a']);

        $this->assertSame(['1', '2'], $request->array('ids'));
        $this->assertSame([], $request->array('gol'));
        $this->assertSame([], $request->array('lipsa'));
        $this->assertThrowsMessage(static fn (): array => $request->array('text'), "Request::array(): parameter 'text' must be a list.");
    }

    // Fisiere

    public function test_file(): void
    {
        $upload = ['name' => 'a.pdf', 'type' => 'application/pdf', 'tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_OK, 'size' => 10];
        $request = new Request(files: [
            'doc' => $upload,
            'gol' => ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0],
        ]);

        $this->assertSame($upload, $request->file('doc'));
        $this->assertNull($request->file('gol'));
        $this->assertNull($request->file('lipsa'));
    }

    public function test_from_globals(): void
    {
        $backup = [$_GET, $_SERVER];
        $_GET = ['id' => '7'];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        try {
            $this->assertSame(7, Request::fromGlobals()->int('id'));
        } finally {
            [$_GET, $_SERVER] = $backup;
        }
    }

    /**
     * @param  callable(): mixed  $callback
     * @param  non-empty-string  $message
     */
    private function assertThrowsMessage(callable $callback, string $message): void
    {
        try {
            $callback();
            $this->fail('Expected exception.');
        } catch (UnexpectedValueException $e) {
            $this->assertStringStartsWith($message, $e->getMessage());
        }
    }
}
