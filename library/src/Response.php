<?php

declare(strict_types=1);

namespace Foundry;

use InvalidArgumentException;
use JsonException;
use LogicException;
use RuntimeException;

/**
 * Response Class
 *
 * HTTP response constructed as an object and explicitly emitted with send().
 * Natively, header() after output emits only the "headers already sent" warning and the response
 * is sent improperly, while a "\r\n" inside a header value allows header injection.
 * Here, send() throws an exception with the file and line where output began, and headers,
 * cookies, and redirects are validated upon construction.
 *
 * Rules:
 *   - The object is immutable: with...() returns a copy; nothing is sent until send();
 *   - send() does not terminate the script (no exit): the application decides what follows;
 *   - Cookies default to HttpOnly, Secure, SameSite=Lax, Path=/;
 *   - redirect() only accepts paths and http(s) URLs; for addresses received from clients see Url::isLocal();
 *   - Downloaded files are streamed in chunks without buffering entire contents in memory.
 *
 * Usage:
 *   require_once 'Response.php';
 *   use Foundry\Response;
 *
 *   Response::json(['id' => 5], 201)->send();
 *   Response::redirect('/login')->send();
 *   Response::html($html)->noCache()->withCookie('tema', 'dark', expires: time() + 86400)->send();
 *   Response::download('/data/raport.pdf', 'Raport martie.pdf')->send();
 *   Response::error(404)->send();
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
final class Response
{
    /** @var array<int, string> Standard reason phrases for error(). */
    private const array REASONS = [
        400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found',
        405 => 'Method Not Allowed', 409 => 'Conflict', 410 => 'Gone', 413 => 'Content Too Large',
        415 => 'Unsupported Media Type', 419 => 'Page Expired', 422 => 'Unprocessable Content',
        429 => 'Too Many Requests', 500 => 'Internal Server Error', 502 => 'Bad Gateway',
        503 => 'Service Unavailable', 504 => 'Gateway Timeout',
    ];

    private const array REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    private const int CHUNK_SIZE = 1_048_576;

    /** @var array<string, array{name: string, values: list<string>}> Headers indexed by lowercase name. */
    private array $headers = [];

    /** @var array<string, array{value: string, options: array{expires: int, path: string, domain: string, secure: bool, httponly: bool, samesite: 'Lax'|'Strict'|'None'}}> */
    private array $cookies = [];

    private ?string $file = null;

    private function __construct(private int $status, private string $body)
    {
        $this->assertStatus($status);
    }

    // Constructori

    /**
     * Generic response.
     *
     * @param  array<string, string>  $headers
     *
     * @throws InvalidArgumentException If status code or a header is invalid.
     */
    public static function make(string $body = '', int $status = 200, array $headers = []): self
    {
        $response = new self($status, $body);
        foreach ($headers as $name => $value) {
            $response->setHeader($name, $value, true);
        }

        return $response;
    }

    public static function html(string $html, int $status = 200): self
    {
        return self::make($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function text(string $text, int $status = 200): self
    {
        return self::make($text, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * JSON in UTF-8 with human-readable unicode and unescaped slashes; 1.0 remains 1.0.
     *
     * @throws InvalidArgumentException If data cannot be encoded (invalid UTF-8, resources, NAN).
     */
    public static function json(mixed $data, int $status = 200): self
    {
        try {
            $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $e) {
            throw new InvalidArgumentException("Response::json(): cannot encode data: {$e->getMessage()}.", 0, $e);
        }

        return self::make($json, $status, ['Content-Type' => 'application/json; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * Redirects to a path ('/login', 'polite?id=1') or an http(s) URL.
     *
     * @throws InvalidArgumentException If the URL is empty, contains control characters, specifies an unsupported scheme
     *                                  (javascript:, data:), or if the status code is not a redirect status.
     */
    public static function redirect(string $url, int $status = 302): self
    {
        if (! in_array($status, self::REDIRECT_STATUSES, true)) {
            throw new InvalidArgumentException("Response::redirect(): status {$status} is not a redirect status (301, 302, 303, 307, 308).");
        }

        if ($url === '' || preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            throw new InvalidArgumentException('Response::redirect(): URL is empty or contains spaces, control characters or backslashes.');
        }

        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $match) === 1 && ! in_array(strtolower($match[1]), ['http', 'https'], true)) {
            throw new InvalidArgumentException("Response::redirect(): scheme '{$match[1]}' is not allowed (only http and https).");
        }

        return self::make('', $status, ['Location' => $url]);
    }

    /**
     * Plain text error response; without a message, the standard reason phrase is used (e.g. 'Not Found').
     *
     * @throws InvalidArgumentException If the status code is not 4xx or 5xx.
     */
    public static function error(int $status, ?string $message = null): self
    {
        if ($status < 400 || $status > 599) {
            throw new InvalidArgumentException("Response::error(): status {$status} is not an error status (400-599).");
        }

        return self::text($message ?? self::REASONS[$status] ?? 'Error', $status);
    }

    public static function noContent(): self
    {
        return new self(204, '');
    }

    /**
     * Downloads a file from disk, streamed in chunks on send().
     * The displayed name defaults to the file name; default MIME type is application/octet-stream.
     *
     * @throws RuntimeException If the file does not exist or is not readable.
     * @throws InvalidArgumentException If the name or MIME type is invalid.
     */
    public static function download(string $path, ?string $name = null, string $mimeType = 'application/octet-stream'): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Response::download(): file '{$path}' does not exist or is not readable.");
        }

        $size = filesize($path);
        if ($size === false) {
            throw new RuntimeException("Response::download(): cannot read the size of '{$path}'.");
        }

        $response = self::attachment('download', $name ?? basename($path), $mimeType, $size);
        $response->file = $path;

        return $response;
    }

    /**
     * Downloads dynamically generated content (CSV, PDF, etc.) as a file.
     *
     * @throws InvalidArgumentException If the name or MIME type is invalid.
     */
    public static function downloadContent(string $content, string $name, string $mimeType = 'application/octet-stream'): self
    {
        $response = self::attachment('downloadContent', $name, $mimeType, strlen($content));
        $response->body = $content;

        return $response;
    }

    // Modificari (returneaza o copie)

    /**
     * @throws InvalidArgumentException If status is not between 100 and 599.
     */
    public function withStatus(int $status): self
    {
        $this->assertStatus($status);
        $copy = clone $this;
        $copy->status = $status;

        return $copy;
    }

    /**
     * Sets a header; with $replace = false, appends an additional value (e.g. Link).
     *
     * @throws InvalidArgumentException If the name is invalid or the value contains newlines.
     */
    public function withHeader(string $name, string $value, bool $replace = true): self
    {
        $copy = clone $this;
        $copy->setHeader($name, $value, $replace);

        return $copy;
    }

    public function withoutHeader(string $name): self
    {
        $copy = clone $this;
        unset($copy->headers[strtolower($name)]);

        return $copy;
    }

    /**
     * Adds a cookie. Defaults: session cookie (expires 0), HttpOnly, Secure, SameSite=Lax, Path=/.
     *
     * @param  string  $sameSite  Lax, Strict, or None (None requires secure: true).
     *
     * @throws InvalidArgumentException If the name, value, or options are invalid.
     */
    public function withCookie(
        string $name,
        string $value,
        int $expires = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = true,
        bool $httpOnly = true,
        string $sameSite = 'Lax',
    ): self {
        if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $name) !== 1) {
            throw new InvalidArgumentException("Response::withCookie(): invalid cookie name '{$name}'.");
        }

        if (! in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            throw new InvalidArgumentException("Response::withCookie(): SameSite must be Lax, Strict or None, got '{$sameSite}'.");
        }

        if ($sameSite === 'None' && ! $secure) {
            throw new InvalidArgumentException('Response::withCookie(): SameSite=None requires secure: true (browsers reject it otherwise).');
        }

        foreach (['path' => $path, 'domain' => $domain] as $option => $text) {
            if (preg_match('/[\x00-\x20\x7F;,]/', $text) === 1) {
                throw new InvalidArgumentException("Response::withCookie(): invalid characters in the {$option} of cookie '{$name}'.");
            }
        }

        $copy = clone $this;
        $copy->cookies[$name] = [
            'value' => $value,
            'options' => ['expires' => $expires, 'path' => $path, 'domain' => $domain, 'secure' => $secure, 'httponly' => $httpOnly, 'samesite' => $sameSite],
        ];

        return $copy;
    }

    /**
     * Deletes a cookie from the browser (using the same path and domain as when created).
     *
     * @throws InvalidArgumentException If the cookie name is invalid.
     */
    public function withoutCookie(string $name, string $path = '/', string $domain = ''): self
    {
        return $this->withCookie($name, '', 1, $path, $domain);
    }

    /**
     * Disallows caching (pages with private data, forms with CSRF tokens).
     */
    public function noCache(): self
    {
        return $this
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0');
    }

    // Citire

    public function status(): int
    {
        return $this->status;
    }

    /**
     * The first value of a header or null.
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)]['values'][0] ?? null;
    }

    /**
     * @return array<string, list<string>> Header names as provided -> values.
     */
    public function headers(): array
    {
        $headers = [];
        foreach ($this->headers as $header) {
            $headers[$header['name']] = $header['values'];
        }

        return $headers;
    }

    /**
     * @return array<string, array{value: string, options: array{expires: int, path: string, domain: string, secure: bool, httponly: bool, samesite: 'Lax'|'Strict'|'None'}}>
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    /**
     * The response body; for download() this is the file content (read on demand).
     *
     * @throws RuntimeException If the file cannot be read.
     */
    public function body(): string
    {
        if ($this->file === null) {
            return $this->body;
        }

        $content = file_get_contents($this->file);
        if ($content === false) {
            throw new RuntimeException("Response::body(): cannot read '{$this->file}'.");
        }

        return $content;
    }

    // Trimitere

    /**
     * Sends the status code, headers, cookies, and body. Does not terminate the script.
     *
     * @throws LogicException If headers have already been sent (including output file and line).
     * @throws RuntimeException If a cookie cannot be set or the file cannot be read.
     */
    public function send(): void
    {
        if (headers_sent($file, $line)) {
            throw new LogicException("Response::send(): headers already sent (output started at {$file}:{$line}).");
        }

        http_response_code($this->status);

        foreach ($this->headers as $header) {
            $first = true;
            foreach ($header['values'] as $value) {
                header("{$header['name']}: {$value}", $first);
                $first = false;
            }
        }

        foreach ($this->cookies as $name => $cookie) {
            if (! setcookie($name, $cookie['value'], $cookie['options'])) {
                throw new RuntimeException("Response::send(): cannot set cookie '{$name}'.");
            }
        }

        if ($this->file === null) {
            echo $this->body;

            return;
        }

        $this->sendFile($this->file);
    }

    /**
     * @throws RuntimeException
     */
    private function sendFile(string $path): void
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Response::send(): cannot open '{$path}'.");
        }

        try {
            while (! feof($handle)) {
                $chunk = fread($handle, self::CHUNK_SIZE);
                if ($chunk === false) {
                    throw new RuntimeException("Response::send(): cannot read '{$path}'.");
                }

                echo $chunk;
                if (ob_get_level() === 0) {
                    flush();
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function attachment(string $method, string $name, string $mimeType, int $size): self
    {
        // Fara cai, ghilimele si caractere de control; numele original UTF-8 merge in filename*.
        $clean = trim(str_replace(['/', '\\', '"'], '_', preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? ''));
        if ($clean === '' || ! mb_check_encoding($clean, 'UTF-8')) {
            throw new InvalidArgumentException("Response::{$method}(): the file name is empty or not valid UTF-8.");
        }

        if (preg_match('#^[a-z0-9!\#$&^_.+-]+/[a-z0-9!\#$&^_.+-]+(\s*;\s*[a-z0-9-]+=[a-z0-9-]+)?$#i', $mimeType) !== 1) {
            throw new InvalidArgumentException("Response::{$method}(): invalid MIME type '{$mimeType}'.");
        }

        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $clean) ?? 'download';

        return self::make('', 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => "attachment; filename=\"{$ascii}\"; filename*=UTF-8''".rawurlencode($clean),
            'Content-Length' => (string) $size,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @throws InvalidArgumentException
     */
    private function setHeader(string $name, string $value, bool $replace): void
    {
        if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $name) !== 1) {
            throw new InvalidArgumentException("Response: invalid header name '{$name}'.");
        }

        // CR / LF ar permite injectarea altor header-e (sau a corpului).
        if (preg_match('/[\r\n\x00]/', $value) === 1) {
            throw new InvalidArgumentException("Response: the value of header '{$name}' contains line breaks or NUL.");
        }

        $key = strtolower($name);
        if ($replace || ! isset($this->headers[$key])) {
            $this->headers[$key] = ['name' => $name, 'values' => [$value]];

            return;
        }

        $this->headers[$key]['values'][] = $value;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertStatus(int $status): void
    {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException("Response: invalid HTTP status {$status} (expected 100-599).");
        }
    }
}
