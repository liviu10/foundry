<?php

declare(strict_types=1);

namespace Foundry;

use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Url Class
 *
 * Reliable URL building and parsing without surprises.
 * Natively, parse_url() returns false or partial results for malformed URLs,
 * and absolute URLs constructed from the Host header can be spoofed by clients
 * (e.g. password reset links directed to an attacker's domain).
 *
 * Rules:
 *   - URLs with spaces, control characters, or backslashes ("\") are rejected;
 *   - query parameters are added or replaced without modifying other parameters (null removes a parameter);
 *   - current() only accepts hosts from the provided trusted host list;
 *   - isLocal() determines whether a client-provided redirect target (e.g. ?next=) is safe.
 *
 * Usage:
 *   require_once 'Url.php';
 *   use Foundry\Url;
 *
 *   Url::with('/policies?page=2', ['page' => 3, 'q' => 'auto']);   // '/policies?page=3&q=auto'
 *   Url::with('/policies?page=2&q=x', ['q' => null]);              // '/policies?page=2'
 *   Url::absolute('https://app.example.com/', 'reset', ['token' => $token]);
 *   Url::current(['app.example.com']);                             // 'https://app.example.com/policies?page=2'
 *   $next = Url::isLocal($_GET['next'] ?? '') ? $_GET['next'] : '/';
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
final class Url
{
    private function __construct() {}

    /**
     * Components of a URL. Missing components are null (path is '' when missing).
     *
     * @return array{scheme: ?string, host: ?string, port: ?int, user: ?string, pass: ?string, path: string, query: ?string, fragment: ?string}
     *
     * @throws InvalidArgumentException If the URL is empty or malformed.
     */
    public static function parse(string $url): array
    {
        self::assertClean('parse', $url);

        $parts = parse_url($url);
        if ($parts === false) {
            throw new InvalidArgumentException('Url::parse(): malformed URL (length '.strlen($url).').');
        }

        return [
            'scheme' => isset($parts['scheme']) ? strtolower($parts['scheme']) : null,
            'host' => isset($parts['host']) ? strtolower($parts['host']) : null,
            'port' => $parts['port'] ?? null,
            'user' => $parts['user'] ?? null,
            'pass' => $parts['pass'] ?? null,
            'path' => $parts['path'] ?? '',
            'query' => $parts['query'] ?? null,
            'fragment' => $parts['fragment'] ?? null,
        ];
    }

    /**
     * Adds or replaces query parameters. Other parameters remain unchanged;
     * null removes a parameter; arrays are formatted as a[]=1&a[]=2. The fragment (#...) is preserved.
     *
     * @param  array<string, mixed>  $query
     *
     * @throws InvalidArgumentException If the URL is malformed or a value cannot be placed in a URL.
     */
    public static function with(string $url, array $query): string
    {
        self::assertClean('with', $url);

        [$base, $existing, $fragment] = self::split($url);

        $kept = self::removeKeys($existing, array_map(strval(...), array_keys($query)));
        $added = self::buildQuery('with', array_filter($query, static fn (mixed $value): bool => $value !== null));

        $all = implode('&', array_filter([$kept, $added], static fn (string $part): bool => $part !== ''));

        return $base.($all === '' ? '' : '?'.$all).($fragment === null ? '' : '#'.$fragment);
    }

    /**
     * Removes query parameters (including array forms such as a[]=..., a[x]=...).
     *
     * @throws InvalidArgumentException If the URL is malformed.
     */
    public static function without(string $url, string ...$keys): string
    {
        self::assertClean('without', $url);

        [$base, $existing, $fragment] = self::split($url);
        $kept = self::removeKeys($existing, $keys);

        return $base.($kept === '' ? '' : '?'.$kept).($fragment === null ? '' : '#'.$fragment);
    }

    /**
     * Absolute URL built from the application base address (from configuration, not from the request).
     * absolute('https://app.ro/portal/', '/policies', ['id' => 5]) -> 'https://app.ro/portal/policies?id=5'
     *
     * @param  array<string, mixed>  $query
     *
     * @throws InvalidArgumentException If the base is not an absolute http(s) URL without query, or if
     *                                  $path is an absolute URL (which would leave the application).
     */
    public static function absolute(string $base, string $path = '', array $query = []): string
    {
        $parts = self::parse($base);
        if (! in_array($parts['scheme'], ['http', 'https'], true) || $parts['host'] === null || $parts['host'] === '') {
            throw new InvalidArgumentException('Url::absolute(): the base must be an absolute http(s) URL.');
        }

        if ($parts['query'] !== null || $parts['fragment'] !== null) {
            throw new InvalidArgumentException('Url::absolute(): the base must not contain a query string or a fragment.');
        }

        if ($path !== '') {
            self::assertClean('absolute', $path);
            if (str_starts_with($path, '//') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $path) === 1) {
                throw new InvalidArgumentException('Url::absolute(): the path must be relative to the base, not an absolute URL.');
            }
        }

        $url = rtrim($base, '/').'/'.ltrim($path, '/');

        return $query === [] ? $url : self::with($url, $query);
    }

    /**
     * Absolute URL of the current request. The host (without port) must be present in $trustedHosts:
     * exact names ('app.example.com') or wildcards ('*.example.com').
     * With $trustForwarded, the scheme is read from X-Forwarded-Proto (only behind a trusted proxy).
     *
     * @param  list<string>  $trustedHosts
     * @param  array<array-key, mixed>|null  $server  Defaults to $_SERVER.
     *
     * @throws InvalidArgumentException If the trusted hosts list is empty.
     * @throws UnexpectedValueException If the request host is missing or not in the trusted list.
     */
    public static function current(array $trustedHosts, ?array $server = null, bool $trustForwarded = false): string
    {
        if ($trustedHosts === []) {
            throw new InvalidArgumentException('Url::current(): the list of trusted hosts cannot be empty.');
        }

        $server ??= $_SERVER;

        $hostHeader = self::serverString($server, 'HTTP_HOST') ?? self::serverString($server, 'SERVER_NAME') ?? '';
        if (preg_match('/^([a-z0-9.-]+)(?::(\d{1,5}))?$/i', $hostHeader, $match) !== 1) {
            throw new UnexpectedValueException('Url::current(): the request has no valid host.');
        }

        $host = strtolower($match[1]);
        if (! self::hostMatches($host, $trustedHosts)) {
            throw new UnexpectedValueException('Url::current(): the request host is not in the list of trusted hosts.');
        }

        $https = self::serverString($server, 'HTTPS');
        $secure = ($https !== null && $https !== '' && strtolower($https) !== 'off')
            || self::serverString($server, 'SERVER_PORT') === '443';
        if ($trustForwarded) {
            $proto = self::serverString($server, 'HTTP_X_FORWARDED_PROTO');
            if ($proto !== null) {
                $secure = strtolower(trim(explode(',', $proto)[0])) === 'https';
            }
        }

        $scheme = $secure ? 'https' : 'http';
        $port = isset($match[2]) ? (int) $match[2] : null;
        $defaultPort = $secure ? 443 : 80;

        return $scheme.'://'.$host.($port === null || $port === $defaultPort ? '' : ':'.$port).self::requestTarget($server);
    }

    /**
     * Determines whether the address is safe for redirecting within the application: local absolute path ('/policies?id=1').
     * Rejects external URLs, '//evil.ro' (scheme-relative), '/\evil.ro' (treated as '//' by browsers),
     * 'javascript:', and control characters.
     */
    public static function isLocal(string $url): bool
    {
        return preg_match('/^\/(?![\/\\\\])[^\\\\\x00-\x20\x7F]*$/', $url) === 1;
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function assertClean(string $method, string $url): void
    {
        if ($url === '') {
            throw new InvalidArgumentException("Url::{$method}(): URL is empty.");
        }

        // Spatiile si caracterele de control trebuie codate (%20); "\" este interpretat diferit de browsere.
        if (preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            throw new InvalidArgumentException("Url::{$method}(): URL contains spaces, control characters or backslashes (they must be percent-encoded).");
        }
    }

    /**
     * @return array{string, string, ?string} Base URL without query and fragment, query string, fragment.
     */
    private static function split(string $url): array
    {
        $fragment = null;
        $hash = strpos($url, '#');
        if ($hash !== false) {
            $fragment = substr($url, $hash + 1);
            $url = substr($url, 0, $hash);
        }

        $question = strpos($url, '?');
        if ($question === false) {
            return [$url, '', $fragment];
        }

        return [substr($url, 0, $question), substr($url, $question + 1), $fragment];
    }

    /**
     * Removes pairs whose name (without [..]) is in $keys; other pairs remain untouched.
     *
     * @param  array<array-key, string>  $keys
     */
    private static function removeKeys(string $query, array $keys): string
    {
        if ($query === '' || $keys === []) {
            return $query;
        }

        $kept = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            $name = urldecode(explode('=', $pair, 2)[0]);
            $bracket = strpos($name, '[');
            $base = $bracket === false ? $name : substr($name, 0, $bracket);

            if (! in_array($base, $keys, true)) {
                $kept[] = $pair;
            }
        }

        return implode('&', $kept);
    }

    /**
     * @param  array<array-key, mixed>  $query
     *
     * @throws InvalidArgumentException If a value is not scalar, null, or array.
     */
    private static function buildQuery(string $method, array $query): string
    {
        array_walk_recursive($query, static function (mixed $value) use ($method): void {
            if (! is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException("Url::{$method}(): query values must be scalars or arrays, got ".get_debug_type($value).'.');
            }
        });

        // true / false devin 1 / 0.
        return http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param  list<string>  $trustedHosts
     */
    private static function hostMatches(string $host, array $trustedHosts): bool
    {
        foreach ($trustedHosts as $trusted) {
            $trusted = strtolower($trusted);
            if ($trusted === $host) {
                return true;
            }

            if (str_starts_with($trusted, '*.') && str_ends_with($host, substr($trusted, 1))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Request path and query (REQUEST_URI); absolute form 'http://x/path' is reduced to '/path'.
     *
     * @param  array<array-key, mixed>  $server
     */
    private static function requestTarget(array $server): string
    {
        $uri = self::serverString($server, 'REQUEST_URI') ?? '/';
        if (preg_match('#^[a-z][a-z0-9+.-]*://[^/]*(/.*)?$#i', $uri, $match) === 1) {
            $uri = $match[1] ?? '/';
        }

        if (! str_starts_with($uri, '/') || preg_match('/[\x00-\x20\x7F]/', $uri) === 1) {
            return '/';
        }

        return $uri;
    }

    /**
     * @param  array<array-key, mixed>  $server
     */
    private static function serverString(array $server, string $key): ?string
    {
        $value = $server[$key] ?? null;

        return is_string($value) ? $value : (is_int($value) ? (string) $value : null);
    }
}
