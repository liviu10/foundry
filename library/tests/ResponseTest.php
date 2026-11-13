<?php

declare(strict_types=1);

namespace Foundry\Tests;

use Foundry\Response;
use Foundry\Tests\Support\WebServer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Obiectul se testeaza direct; send() se testeaza prin serverul integrat PHP (tests/fixtures/web-router.php),
 * pentru ca in CLI header() nu are efect.
 */
final class ResponseTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/response-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    // Constructori

    public function test_make(): void
    {
        $response = Response::make('corp', 202, ['X-Test' => 'a']);

        $this->assertSame(202, $response->status());
        $this->assertSame('corp', $response->body());
        $this->assertSame('a', $response->header('x-test'));
        $this->assertSame(['X-Test' => ['a']], $response->headers());
    }

    public function test_html_and_text(): void
    {
        $this->assertSame('text/html; charset=UTF-8', Response::html('<p>x</p>')->header('Content-Type'));
        $this->assertSame('text/plain; charset=UTF-8', Response::text('x', 500)->header('Content-Type'));
        $this->assertSame(500, Response::text('x', 500)->status());
    }

    public function test_json(): void
    {
        $response = Response::json(['oras' => "Ia\u{0219}i", 'cale' => 'a/b', 'suma' => 1.0], 201);

        $this->assertSame(201, $response->status());
        $this->assertSame("{\"oras\":\"Ia\u{0219}i\",\"cale\":\"a/b\",\"suma\":1.0}", $response->body());
        $this->assertSame('application/json; charset=UTF-8', $response->header('Content-Type'));
        $this->assertSame('nosniff', $response->header('X-Content-Type-Options'));
    }

    public function test_json_rejects_invalid_utf8(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Response::json(): cannot encode data: Malformed UTF-8 characters');

        Response::json(['x' => "\xE2"]);
    }

    public function test_redirect(): void
    {
        $response = Response::redirect('/login');
        $this->assertSame(302, $response->status());
        $this->assertSame('/login', $response->header('Location'));

        $this->assertSame(301, Response::redirect('https://a.ro/x', 301)->status());
        $this->assertSame('polite?id=1', Response::redirect('polite?id=1')->header('Location'));
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function invalidRedirectProvider(): iterable
    {
        yield 'gol' => ['', 302, 'URL is empty'];
        yield 'header injection' => ["/a\r\nSet-Cookie: x=1", 302, 'control characters'];
        yield 'backslash' => ['/\\evil.ro', 302, 'backslashes'];
        yield 'javascript' => ['javascript:alert(1)', 302, "scheme 'javascript' is not allowed"];
        yield 'data' => ['data:text/html,x', 302, "scheme 'data' is not allowed"];
        yield 'status 200' => ['/x', 200, 'status 200 is not a redirect status'];
    }

    #[DataProvider('invalidRedirectProvider')]
    public function test_redirect_rejects(string $url, int $status, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Response::redirect($url, $status);
    }

    public function test_error(): void
    {
        $this->assertSame('Not Found', Response::error(404)->body());
        $this->assertSame(404, Response::error(404)->status());
        $this->assertSame('Polita nu exista.', Response::error(404, 'Polita nu exista.')->body());
        $this->assertSame('Error', Response::error(499)->body());

        $this->expectException(InvalidArgumentException::class);
        Response::error(302);
    }

    public function test_no_content(): void
    {
        $this->assertSame(204, Response::noContent()->status());
        $this->assertSame('', Response::noContent()->body());
    }

    public function test_download(): void
    {
        file_put_contents($this->dir.'/raport.pdf', '%PDF-1.4');
        $response = Response::download($this->dir.'/raport.pdf', "Raport \u{0219}ef \"final\".pdf", 'application/pdf');

        $this->assertSame('application/pdf', $response->header('Content-Type'));
        $this->assertSame("attachment; filename=\"Raport __ef _final_.pdf\"; filename*=UTF-8''Raport%20%C8%99ef%20_final_.pdf", $response->header('Content-Disposition'));
        $this->assertSame('8', $response->header('Content-Length'));
        $this->assertSame('%PDF-1.4', $response->body());
        $this->assertSame('attachment; filename="raport.pdf"; filename*=UTF-8\'\'raport.pdf', Response::download($this->dir.'/raport.pdf')->header('Content-Disposition'));
    }

    public function test_download_missing_file(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist or is not readable');

        Response::download($this->dir.'/lipsa.pdf');
    }

    public function test_download_content(): void
    {
        $response = Response::downloadContent("a;b\n", '../../etc/export.csv', 'text/csv; charset=UTF-8');

        $this->assertSame("a;b\n", $response->body());
        $this->assertSame('4', $response->header('Content-Length'));
        $this->assertStringContainsString('filename=".._.._etc_export.csv"', (string) $response->header('Content-Disposition'));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function invalidDownloadProvider(): iterable
    {
        yield 'nume gol' => ['', 'text/plain', 'the file name is empty'];
        yield 'nume doar control' => ["\r\n", 'text/plain', 'the file name is empty'];
        yield 'nume UTF-8 invalid' => ["a\xE2.txt", 'text/plain', 'not valid UTF-8'];
        yield 'mime invalid' => ['a.txt', "text/plain\r\nX: y", 'invalid MIME type'];
        yield 'mime fara subtip' => ['a.txt', 'text', 'invalid MIME type'];
    }

    #[DataProvider('invalidDownloadProvider')]
    public function test_download_content_rejects(string $name, string $mime, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Response::downloadContent('x', $name, $mime);
    }

    // Modificari

    public function test_responses_are_immutable(): void
    {
        $original = Response::text('x');
        $changed = $original->withStatus(201)->withHeader('X-A', '1')->withCookie('c', 'v');

        $this->assertSame(200, $original->status());
        $this->assertNull($original->header('X-A'));
        $this->assertSame([], $original->cookies());
        $this->assertSame(201, $changed->status());
        $this->assertSame('1', $changed->header('X-A'));
    }

    public function test_headers_replace_add_remove(): void
    {
        $response = Response::make()
            ->withHeader('Link', 'a', false)
            ->withHeader('link', 'b', false)
            ->withHeader('X-A', '1')
            ->withHeader('X-A', '2')
            ->withHeader('X-B', '1')
            ->withoutHeader('x-b');

        $this->assertSame(['Link' => ['a', 'b'], 'X-A' => ['2']], $response->headers());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function invalidHeaderProvider(): iterable
    {
        yield 'CRLF in valoare' => ['X-A', "a\r\nSet-Cookie: x=1", "the value of header 'X-A' contains line breaks"];
        yield 'LF in valoare' => ['X-A', "a\nb", 'contains line breaks'];
        yield 'NUL in valoare' => ['X-A', "a\x00b", 'contains line breaks or NUL'];
        yield 'spatiu in nume' => ['X A', 'a', "invalid header name 'X A'"];
        yield 'doua puncte in nume' => ['X:A', 'a', 'invalid header name'];
        yield 'nume gol' => ['', 'a', 'invalid header name'];
    }

    #[DataProvider('invalidHeaderProvider')]
    public function test_with_header_rejects(string $name, string $value, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Response::make()->withHeader($name, $value);
    }

    public function test_invalid_status(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Response: invalid HTTP status 600 (expected 100-599).');

        Response::make()->withStatus(600);
    }

    public function test_no_cache(): void
    {
        $response = Response::html('x')->noCache();

        $this->assertSame('no-store, no-cache, must-revalidate, max-age=0', $response->header('Cache-Control'));
        $this->assertSame('no-cache', $response->header('Pragma'));
    }

    public function test_cookie_defaults_are_secure(): void
    {
        $cookies = Response::make()->withCookie('sesiune', 'abc')->cookies();

        $this->assertSame([
            'sesiune' => [
                'value' => 'abc',
                'options' => ['expires' => 0, 'path' => '/', 'domain' => '', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax'],
            ],
        ], $cookies);
    }

    /**
     * @return iterable<string, array{array{string, bool, string, string}, string}>
     */
    public static function invalidCookieProvider(): iterable
    {
        yield 'nume cu spatiu' => [['a b', true, 'Lax', '/'], "invalid cookie name 'a b'"];
        yield 'nume cu =' => [['a=b', true, 'Lax', '/'], 'invalid cookie name'];
        yield 'samesite gresit' => [['a', true, 'lax', '/'], "SameSite must be Lax, Strict or None, got 'lax'"];
        yield 'None fara secure' => [['a', false, 'None', '/'], 'SameSite=None requires secure: true'];
        yield 'path cu ;' => [['a', true, 'Lax', '/;x'], 'invalid characters in the path'];
    }

    /**
     * @param  array{string, bool, string, string}  $args
     */
    #[DataProvider('invalidCookieProvider')]
    public function test_with_cookie_rejects(array $args, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        [$name, $secure, $sameSite, $path] = $args;
        Response::make()->withCookie($name, 'v', path: $path, secure: $secure, sameSite: $sameSite);
    }

    // send() prin server real

    public function test_send_json(): void
    {
        $response = WebServer::request('GET', '/response/json');

        $this->assertSame(201, $response['status']);
        $this->assertSame(['application/json; charset=UTF-8'], $response['headers']['content-type']);
        $this->assertSame("{\"oras\":\"Ia\u{0219}i\",\"url\":\"a/b\"}", $response['body']);
    }

    public function test_send_redirect(): void
    {
        $response = WebServer::request('GET', '/response/redirect');

        $this->assertSame(303, $response['status']);
        $this->assertSame(['/login?next=%2Fpolite'], $response['headers']['location']);
    }

    public function test_send_multiple_header_values(): void
    {
        $response = WebServer::request('GET', '/response/headers');

        $this->assertSame(['</a.css>; rel=preload', '</b.js>; rel=preload'], $response['headers']['link']);
        $this->assertSame(['no-store, no-cache, must-revalidate, max-age=0'], $response['headers']['cache-control']);
    }

    public function test_send_cookies(): void
    {
        $cookies = WebServer::request('GET', '/response/cookie')['headers']['set-cookie'];

        $this->assertCount(3, $cookies);
        $this->assertStringStartsWith('tema=dark%20mode; expires=', $cookies[0]);
        $this->assertStringEndsWith('; path=/; secure; HttpOnly; SameSite=Lax', $cookies[0]);
        $this->assertSame('lax=1; path=/; SameSite=Strict', $cookies[1]);
        $this->assertStringStartsWith('vechi=deleted; expires=Thu, 01 Jan 1970', $cookies[2]);
    }

    public function test_send_download_streams_file(): void
    {
        $response = WebServer::request('GET', '/response/download');

        $this->assertSame(200, $response['status']);
        $this->assertSame(3_000_000, strlen($response['body']));
        $this->assertSame(['3000000'], $response['headers']['content-length']);
        $this->assertSame(["attachment; filename=\"Raport __ef.bin\"; filename*=UTF-8''Raport%20%C8%99ef.bin"], $response['headers']['content-disposition']);
    }

    public function test_send_download_content(): void
    {
        $response = WebServer::request('GET', '/response/download-content');

        $this->assertSame("a;b\n1;2\n", $response['body']);
        $this->assertSame(['text/csv; charset=UTF-8'], $response['headers']['content-type']);
    }

    public function test_send_error(): void
    {
        $response = WebServer::request('GET', '/response/error');

        $this->assertSame(404, $response['status']);
        $this->assertSame('Not Found', $response['body']);
    }

    public function test_send_after_output_reports_where_output_started(): void
    {
        $body = WebServer::request('GET', '/response/headers-sent')['body'];

        $this->assertMatchesRegularExpression('/^output\|LogicException: Response::send\(\): headers already sent \(output started at .+web-router\.php:\d+\)\.$/', $body);
    }
}
