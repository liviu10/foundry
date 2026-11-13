<?php

declare(strict_types=1);

namespace Foundry;

use InvalidArgumentException;
use JsonException;
use LogicException;
use RuntimeException;

/**
 * HttpClient Class
 *
 * HTTP client built on cURL, with clear error handling and safe defaults.
 *
 * Natively, curl_exec() returns false (errors must be retrieved separately), while a 404/500 response
 * is treated as "successful". Here:
 *   - network error (DNS, timeout, SSL) -> RuntimeException;
 *   - non-2xx response -> RuntimeException (or returned as response with throwOnError: false);
 *   - only http/https protocols allowed, including across redirects (no file://, gopher://, etc.);
 *   - SSL certificate is verified by default (disabling requires explicit verifySsl: false);
 *   - mandatory timeout (default 30s total, 10s connection), covering the whole redirect chain;
 *   - response body capped by maxBytes (default 50 MB, counted after gzip/deflate decompression);
 *   - redirects (only when followRedirects > 0): https -> http is refused; 303, and 301/302 after a POST,
 *     continue as GET without a body; when the origin (scheme, host, port) changes, auth, bearer and
 *     custom headers are not sent to the new host;
 *   - unknown option (e.g. 'timout') -> InvalidArgumentException.
 *
 * Error messages omit the query string (which may contain sensitive tokens) and response
 * body (which may contain personal data), including only method, query-stripped URL, status code, and size.
 *
 * Options:
 *   query           array        parameters appended to URL
 *   headers         array        ['Name' => 'value']
 *   json            mixed        JSON payload (sets Content-Type: application/json)
 *   form            array        application/x-www-form-urlencoded payload
 *   body            string       raw body payload
 *   timeout         float        total timeout in seconds (default 30)
 *   connectTimeout  float        connection timeout in seconds (default 10)
 *   followRedirects int          max redirects to follow (default 0 = do not follow)
 *   maxBytes        int          maximum response body size in bytes (default 50 MB)
 *   auth            array        [username, password] for Basic Auth
 *   bearer          string       token for "Authorization: Bearer ..."
 *   proxy           string       e.g. 'http://proxy.example.com:8080'
 *   verifySsl       bool         default true
 *   caInfo          string       CA bundle file path (when PHP has no curl.cainfo configured)
 *   throwOnError    bool         default true; false = return non-2xx responses without throwing
 *
 * Usage:
 *   require_once 'HttpClient.php';
 *   use Foundry\HttpClient;
 *
 *   $response = HttpClient::get('https://api.example.com/policies', ['query' => ['page' => 2], 'bearer' => $token]);
 *   $data = HttpClient::json('POST', 'https://api.example.com/payments', ['json' => ['amount' => 150]]);
 *
 *   $response = HttpClient::post($url, ['json' => $payload, 'throwOnError' => false]);
 *   if ($response['status'] === 422) { $errors = Json::decodeArray($response['body']); }
 *
 * @category Utilities
 *
 * @version  1.2
 *
 * @since    PHP 8.3
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
final class HttpClient
{
    /** @var array<string, string> Accepted options and their expected types (for validation). */
    private const array OPTIONS = [
        'query' => 'array',
        'headers' => 'array',
        'json' => 'mixed',
        'form' => 'array',
        'body' => 'string',
        'timeout' => 'number',
        'connectTimeout' => 'number',
        'followRedirects' => 'int',
        'maxBytes' => 'int',
        'auth' => 'array',
        'bearer' => 'string',
        'proxy' => 'string',
        'verifySsl' => 'bool',
        'caInfo' => 'string',
        'throwOnError' => 'bool',
    ];

    /** @var list<string> Accepted HTTP methods. */
    private const array METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    /** @var list<int> */
    private const array REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    /** @var int Default response body limit (bytes, after decompression): 50 MB. */
    public const int DEFAULT_MAX_BYTES = 52_428_800;

    private function __construct() {}

    /**
     * @param  array<string, mixed>  $options
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public static function get(string $url, array $options = []): array
    {
        return self::request('GET', $url, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public static function post(string $url, array $options = []): array
    {
        return self::request('POST', $url, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public static function put(string $url, array $options = []): array
    {
        return self::request('PUT', $url, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public static function patch(string $url, array $options = []): array
    {
        return self::request('PATCH', $url, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public static function delete(string $url, array $options = []): array
    {
        return self::request('DELETE', $url, $options);
    }

    /**
     * Sends the request and decodes the JSON response.
     *
     * @param  array<string, mixed>  $options
     * @return mixed The decoded response (objects become associative arrays).
     *
     * @throws RuntimeException On network error, non-2xx response, or invalid JSON.
     */
    public static function json(string $method, string $url, array $options = []): mixed
    {
        $options['headers'] = ['Accept' => 'application/json'] + (is_array($options['headers'] ?? null) ? $options['headers'] : []);
        $response = self::request($method, $url, $options);

        try {
            return json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf(
                'HttpClient: %s %s returned invalid JSON: %s (%d bytes).',
                strtoupper($method),
                self::safeUrl($url),
                $e->getMessage(),
                strlen($response['body']),
            ), 0, $e);
        }
    }

    /**
     * Sends an HTTP request.
     *
     * @param  string  $method  GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS.
     * @param  string  $url  Absolute http(s) URL.
     * @param  array<string, mixed>  $options  See class docblock for available options.
     * @return array{status: int, headers: array<string, string>, body: string} Header names are lowercased.
     *
     * @throws InvalidArgumentException On invalid method, URL, or options.
     * @throws RuntimeException On network error or non-2xx response (when throwOnError is true).
     * @throws LogicException If the curl extension is not enabled.
     */
    public static function request(string $method, string $url, array $options = []): array
    {
        if (! function_exists('curl_init')) {
            throw new LogicException('HttpClient: the PHP curl extension is not enabled.');
        }

        $method = strtoupper($method);
        if (! in_array($method, self::METHODS, true)) {
            throw new InvalidArgumentException("HttpClient: unsupported HTTP method '{$method}'.");
        }

        self::assertOptions($options);
        $url = self::buildUrl($url, $options);

        $maxRedirects = is_int($options['followRedirects'] ?? null) ? $options['followRedirects'] : 0;
        $deadline = microtime(true) + self::number($options, 'timeout', 30);
        $redirects = 0;

        while (true) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new RuntimeException(sprintf('HttpClient: %s %s failed: the total timeout was reached while following redirects.', $method, self::safeUrl($url)));
            }

            $response = self::send($method, $url, $options, $remaining);
            $location = $response['location'];
            unset($response['location']);

            if ($location === null || $maxRedirects === 0 || ! in_array($response['status'], self::REDIRECT_STATUSES, true)) {
                break;
            }

            if ($redirects >= $maxRedirects) {
                throw new RuntimeException(sprintf('HttpClient: %s %s failed: more than %d redirects.', $method, self::safeUrl($url), $maxRedirects));
            }

            $redirects++;
            [$method, $url, $options] = self::redirect($method, $url, $location, $response['status'], $options);
        }

        $status = $response['status'];
        $responseHeaders = $response['headers'];
        $body = $response['body'];

        if (($options['throwOnError'] ?? true) && ($status < 200 || $status >= 300)) {
            throw new RuntimeException(sprintf(
                'HttpClient: %s %s returned HTTP %d (%s, %d bytes).',
                $method,
                self::safeUrl($url),
                $status,
                $responseHeaders['content-type'] ?? 'no content-type',
                strlen($body),
            ));
        }

        return $response;
    }

    /**
     * Sends a single request (no redirect following) and collects the body up to the maxBytes limit.
     *
     * @param  array<string, mixed>  $options
     * @return array{status: int, headers: array<string, string>, body: string, location: string|null}
     *
     * @throws RuntimeException On network error or when the body exceeds maxBytes.
     */
    private static function send(string $method, string $url, array $options, float $timeout): array
    {
        $handle = curl_init();
        $responseHeaders = [];
        $body = '';
        $tooLarge = false;
        $limit = is_int($options['maxBytes'] ?? null) ? $options['maxBytes'] : self::DEFAULT_MAX_BYTES;

        curl_setopt_array($handle, self::curlOptions($method, $url, $options, $responseHeaders, $timeout));
        // Counts the bytes after decompression, so a small gzip body that expands to gigabytes is stopped too.
        curl_setopt($handle, CURLOPT_WRITEFUNCTION, static function (mixed $curl, string $chunk) use (&$body, &$tooLarge, $limit): int {
            if (strlen($body) + strlen($chunk) > $limit) {
                $tooLarge = true;

                return 0; // a length different from the chunk's aborts the transfer
            }
            $body .= $chunk;

            return strlen($chunk);
        });

        $completed = curl_exec($handle);

        if ($tooLarge) {
            throw new RuntimeException(sprintf('HttpClient: %s %s failed: the response body is larger than the maxBytes limit of %d bytes.', $method, self::safeUrl($url), $limit));
        }

        if ($completed === false) {
            throw new RuntimeException(sprintf(
                'HttpClient: %s %s failed: %s (curl error %d).',
                $method,
                self::safeUrl($url),
                curl_error($handle),
                curl_errno($handle),
            ));
        }

        $location = curl_getinfo($handle, CURLINFO_REDIRECT_URL);

        return [
            'status' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            'headers' => $responseHeaders,
            'body' => $body,
            'location' => is_string($location) && $location !== '' ? $location : null,
        ];
    }

    /**
     * Prepares the next hop of a redirect, applying the rules browsers follow plus two safety rules:
     * https never downgrades to http, and credentials / custom headers never leave the original origin.
     *
     * @param  array<string, mixed>  $options
     * @return array{0: string, 1: string, 2: array<string, mixed>} [method, url, options]
     *
     * @throws RuntimeException If the redirect target is not allowed.
     */
    private static function redirect(string $method, string $from, string $to, int $status, array $options): array
    {
        $scheme = strtolower((string) parse_url($to, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException(sprintf('HttpClient: %s %s failed: redirect to a non-http(s) URL is not allowed.', $method, self::safeUrl($from)));
        }

        if (strtolower((string) parse_url($from, PHP_URL_SCHEME)) === 'https' && $scheme === 'http') {
            throw new RuntimeException(sprintf('HttpClient: %s %s failed: redirect from https to http is not allowed (the request would travel unencrypted).', $method, self::safeUrl($from)));
        }

        // 303 always, and 301/302 after a POST, continue as GET without a body; 307/308 keep method and body.
        if ($status === 303 || ($method === 'POST' && in_array($status, [301, 302], true))) {
            $method = $method === 'HEAD' ? 'HEAD' : 'GET';
            unset($options['json'], $options['form'], $options['body']);
            if (is_array($options['headers'] ?? null)) {
                $options['headers'] = array_filter($options['headers'], static fn (mixed $name): bool => ! is_string($name) || strtolower($name) !== 'content-type', ARRAY_FILTER_USE_KEY);
            }
        }

        if (self::origin($from) !== self::origin($to)) {
            unset($options['auth'], $options['bearer'], $options['headers']);
        }

        return [$method, $to, $options];
    }

    /** scheme://host:port, with default ports filled in, for same-origin comparisons. */
    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return $scheme.'://'.strtolower((string) ($parts['host'] ?? '')).':'.$port;
    }

    /**
     * @param  array<string, mixed>  $options
     *
     * @throws InvalidArgumentException On unknown, invalid-type, or incompatible options.
     */
    private static function assertOptions(array $options): void
    {
        foreach ($options as $name => $value) {
            $type = self::OPTIONS[$name] ?? null;
            if ($type === null) {
                throw new InvalidArgumentException("HttpClient: unknown option '{$name}' (allowed: ".implode(', ', array_keys(self::OPTIONS)).').');
            }

            $valid = match ($type) {
                'array' => is_array($value),
                'string' => is_string($value),
                'bool' => is_bool($value),
                'int' => is_int($value) && $value >= 0,
                'number' => (is_int($value) || is_float($value)) && $value > 0,
                default => true,
            };

            if (! $valid) {
                throw new InvalidArgumentException("HttpClient: option '{$name}' must be of type {$type}".($type === 'number' ? ' greater than 0' : '').'.');
            }
        }

        $bodies = array_intersect(['json', 'form', 'body'], array_keys($options));
        if (count($bodies) > 1) {
            throw new InvalidArgumentException('HttpClient: options '.implode(', ', $bodies).' cannot be combined; use only one.');
        }

        if (isset($options['auth']) && (! is_array($options['auth']) || count($options['auth']) !== 2
            || ! is_string($options['auth'][0] ?? null) || ! is_string($options['auth'][1] ?? null))) {
            throw new InvalidArgumentException("HttpClient: option 'auth' must be [username, password].");
        }

        if (isset($options['auth'], $options['bearer'])) {
            throw new InvalidArgumentException("HttpClient: options 'auth' and 'bearer' cannot be combined.");
        }
    }

    /**
     * @param  array<string, mixed>  $options
     *
     * @throws InvalidArgumentException If the URL is not an absolute http(s) URL.
     */
    private static function buildUrl(string $url, array $options): string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException("HttpClient: invalid URL '".self::safeUrl($url)."' (expected an absolute http:// or https:// URL).");
        }

        if (isset($options['query']) && is_array($options['query']) && $options['query'] !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($options['query'], '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, string>  $responseHeaders  Populated by cURL as headers are received.
     * @param  float  $timeout  Remaining total time for this request, in seconds.
     * @return array<int, mixed>
     */
    private static function curlOptions(string $method, string $url, array $options, array &$responseHeaders, float $timeout): array
    {
        $headers = [];
        foreach (is_array($options['headers'] ?? null) ? $options['headers'] : [] as $name => $value) {
            if (! is_string($name) || ! is_scalar($value) || preg_match('/[\r\n]/', $name.$value) === 1) {
                throw new InvalidArgumentException('HttpClient: headers must be [name => scalar value], without new lines.');
            }
            $headers[strtolower($name)] = $name.': '.$value;
        }

        $curl = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT_MS => max(1, (int) ($timeout * 1000)),
            CURLOPT_CONNECTTIMEOUT_MS => (int) (self::number($options, 'connectTimeout', 10) * 1000),
            CURLOPT_SSL_VERIFYPEER => ($options['verifySsl'] ?? true) === true,
            CURLOPT_SSL_VERIFYHOST => ($options['verifySsl'] ?? true) === true ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => false, // redirects are followed by request(), with the rules in redirect()
            CURLOPT_ENCODING => '', // accepta gzip/deflate si decomprima automat
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_HEADERFUNCTION => static function (mixed $handle, string $line) use (&$responseHeaders): int {
                if (str_starts_with($line, 'HTTP/')) {
                    $responseHeaders = []; // raspuns nou (dupa redirect sau 100 Continue)
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $name = strtolower(trim($name));
                    $value = trim($value);
                    $responseHeaders[$name] = isset($responseHeaders[$name]) ? $responseHeaders[$name].', '.$value : $value;
                }

                return strlen($line);
            },
        ];

        if (array_key_exists('json', $options)) {
            try {
                $curl[CURLOPT_POSTFIELDS] = json_encode($options['json'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (JsonException $e) {
                throw new InvalidArgumentException('HttpClient: option json cannot be encoded: '.$e->getMessage().'.', 0, $e);
            }
            $headers['content-type'] ??= 'Content-Type: application/json';
        } elseif (isset($options['form']) && is_array($options['form'])) {
            $curl[CURLOPT_POSTFIELDS] = http_build_query($options['form'], '', '&');
            $headers['content-type'] ??= 'Content-Type: application/x-www-form-urlencoded';
        } elseif (isset($options['body']) && is_string($options['body'])) {
            $curl[CURLOPT_POSTFIELDS] = $options['body'];
        }

        if (isset($options['bearer']) && is_string($options['bearer'])) {
            $headers['authorization'] = 'Authorization: Bearer '.$options['bearer'];
        }

        $user = is_array($options['auth'] ?? null) ? ($options['auth'][0] ?? null) : null;
        $password = is_array($options['auth'] ?? null) ? ($options['auth'][1] ?? null) : null;
        if (is_string($user) && is_string($password)) {
            $curl[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $curl[CURLOPT_USERPWD] = $user.':'.$password;
        }

        if (isset($options['proxy']) && is_string($options['proxy'])) {
            $curl[CURLOPT_PROXY] = $options['proxy'];
        }

        if (isset($options['caInfo']) && is_string($options['caInfo'])) {
            if (! is_file($options['caInfo'])) {
                throw new InvalidArgumentException("HttpClient: CA bundle '{$options['caInfo']}' does not exist.");
            }
            $curl[CURLOPT_CAINFO] = $options['caInfo'];
        }

        // Fara "Expect: 100-continue": unele servere/proxy-uri nu il suporta si adauga o intarziere.
        $headers['expect'] ??= 'Expect:';
        $curl[CURLOPT_HTTPHEADER] = array_values($headers);

        return $curl;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private static function number(array $options, string $name, float $default): float
    {
        $value = $options[$name] ?? $default;

        return is_int($value) || is_float($value) ? (float) $value : $default;
    }

    /**
     * Sanitizes URL for error messages (strips credentials and query string).
     */
    private static function safeUrl(string $url): string
    {
        $url = (string) preg_replace('#^([a-z][a-z0-9+.-]*://)[^/@]*@#i', '$1', $url);
        $position = strcspn($url, '?#');

        return substr($url, 0, $position).($position < strlen($url) ? '?...' : '');
    }
}
