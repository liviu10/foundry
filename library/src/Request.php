<?php

declare(strict_types=1);

namespace Foundry;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use UnexpectedValueException;

/**
 * Request Class
 *
 * Current HTTP request reading with strict types.
 * Natively, $_GET['id'] might be missing, could be an array (?id[]=1), or arbitrary text, while (int) 'abc' = 0.
 * Here, typed getters return exactly the requested type or throw an exception.
 *
 * Rules:
 *   - int('id'), string('name'), ... : missing, empty, or invalid parameter = UnexpectedValueException
 *     (in application: 400 response); OrNull variants return null when missing or empty;
 *   - input() looks in the request body (form, JSON, urlencoded PUT/PATCH/DELETE), then in the query string;
 *   - invalid UTF-8 strings and arrays passed where a string is expected are rejected;
 *   - ip() only uses X-Forwarded-For from explicitly specified trusted proxies;
 *   - error messages contain the parameter name, not the value.
 *
 * Usage:
 *   require_once 'Request.php';
 *   use Foundry\Request;
 *
 *   $request = Request::fromGlobals();
 *   $id = $request->int('id');                        // '12' -> 12; missing, '12abc', ?id[]=1 -> throws exception
 *   $page = $request->intOrNull('page') ?? 1;
 *   $name = $request->string('name');
 *   $date = $request->date('date', 'd.m.Y');          // DateTimeImmutable, real calendar date
 *   $active = $request->boolOrNull('active') ?? false; // checkbox
 *   if ($request->isPost() && $request->wantsJson()) { ... }
 *   $ip = $request->ip(['10.0.0.0/8']);               // behind an internal proxy
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
final class Request
{
    /** @var list<string> Accepted truthy string values (as in Cast::toBool). */
    private const array TRUE_VALUES = ['1', 'true', 'yes', 'on', 'da'];

    private const array FALSE_VALUES = ['0', 'false', 'no', 'off', 'nu'];

    /** @var string Decimal number with dot: 12, -3, 1.50. */
    private const string FLOAT_PATTERN = '/^[+-]?\d+(\.\d+)?$/';

    /** @var array<array-key, mixed>|null Parsed body parameters, computed once. */
    private ?array $bodyParams = null;

    /**
     * @param  array<array-key, mixed>  $query  URL query parameters ($_GET).
     * @param  array<array-key, mixed>  $post  Form parameters ($_POST).
     * @param  array<array-key, mixed>  $server  $_SERVER variables.
     * @param  array<array-key, mixed>  $files  Uploaded files ($_FILES).
     * @param  array<array-key, mixed>  $cookies  Cookie values ($_COOKIE).
     * @param  string|null  $body  Raw request body; null = read from php://input on first access.
     */
    public function __construct(
        private readonly array $query = [],
        private readonly array $post = [],
        private readonly array $server = [],
        private readonly array $files = [],
        private readonly array $cookies = [],
        private ?string $body = null,
    ) {}

    public static function fromGlobals(): self
    {
        return new self($_GET, $_POST, $_SERVER, $_FILES, $_COOKIE);
    }

    // Metoda, adresa, header-e

    /**
     * HTTP method in uppercase (defaults to GET).
     *
     * @throws UnexpectedValueException If the method is not a valid HTTP method name.
     */
    public function method(): string
    {
        $method = strtoupper($this->serverString('REQUEST_METHOD') ?? 'GET');
        if (preg_match('/^[A-Z]{1,20}$/', $method) !== 1) {
            throw new UnexpectedValueException('Request::method(): invalid HTTP method.');
        }

        return $method;
    }

    public function isMethod(string $method): bool
    {
        return $this->method() === strtoupper($method);
    }

    public function isGet(): bool
    {
        return $this->isMethod('GET');
    }

    public function isPost(): bool
    {
        return $this->isMethod('POST');
    }

    /**
     * Request initiated via JavaScript (X-Requested-With: XMLHttpRequest).
     */
    public function isAjax(): bool
    {
        return $this->header('X-Requested-With') === 'XMLHttpRequest';
    }

    /**
     * The request body is JSON (Content-Type application/json or +json).
     */
    public function isJson(): bool
    {
        return preg_match('#^application/([a-z0-9.-]+\+)?json\b#i', $this->header('Content-Type') ?? '') === 1;
    }

    /**
     * The client expects JSON (Accept header contains json or the request is AJAX).
     */
    public function wantsJson(): bool
    {
        return str_contains(strtolower($this->header('Accept') ?? ''), 'json') || $this->isAjax();
    }

    /**
     * HTTPS connection. With $trustForwarded, X-Forwarded-Proto is used (only behind a trusted proxy).
     */
    public function isSecure(bool $trustForwarded = false): bool
    {
        if ($trustForwarded) {
            $proto = $this->header('X-Forwarded-Proto');
            if ($proto !== null) {
                return strtolower(trim(explode(',', $proto)[0])) === 'https';
            }
        }

        $https = $this->serverString('HTTPS');

        return ($https !== null && $https !== '' && strtolower($https) !== 'off') || $this->serverString('SERVER_PORT') === '443';
    }

    /**
     * Request URI path and query ('/polite?page=2'), without scheme and host.
     */
    public function uri(): string
    {
        $uri = $this->serverString('REQUEST_URI') ?? '/';
        if (preg_match('#^[a-z][a-z0-9+.-]*://[^/]*(/.*)?$#i', $uri, $match) === 1) {
            $uri = $match[1] ?? '/';
        }

        return str_starts_with($uri, '/') ? $uri : '/';
    }

    /**
     * Request path without query string ('/polite'), unencoded.
     */
    public function path(): string
    {
        return explode('?', $this->uri(), 2)[0];
    }

    /**
     * Header value (case-insensitive) or null.
     */
    public function header(string $name): ?string
    {
        $key = strtoupper(str_replace('-', '_', $name));

        // Content-Type si Content-Length apar in $_SERVER fara prefixul HTTP_.
        return in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $this->serverString($key) : $this->serverString('HTTP_'.$key);
    }

    /**
     * All headers, with names in normalized 'content-type' format.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        $headers = [];
        foreach ($this->server as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            $key = (string) $key;
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[strtolower(str_replace('_', '-', $key))] = $value;
            }
        }

        return $headers;
    }

    /**
     * Token extracted from 'Authorization: Bearer ...' or null.
     */
    public function bearerToken(): ?string
    {
        $authorization = $this->header('Authorization') ?? $this->serverString('REDIRECT_HTTP_AUTHORIZATION') ?? '';

        return preg_match('/^Bearer\s+(\S+)$/i', trim($authorization), $match) === 1 ? $match[1] : null;
    }

    public function userAgent(): ?string
    {
        return $this->header('User-Agent');
    }

    public function cookie(string $name): ?string
    {
        $value = $this->cookies[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Client IP address. If REMOTE_ADDR matches one of $trustedProxies (IPs or CIDR: '10.0.0.0/8'),
     * X-Forwarded-For is traversed from right to left, returning the first non-trusted IP.
     * Without trusted proxies, the header is ignored (it could be spoofed by the client).
     *
     * @param  list<string>  $trustedProxies
     *
     * @throws InvalidArgumentException If an entry in $trustedProxies is invalid.
     * @throws UnexpectedValueException If REMOTE_ADDR is missing or not a valid IP address.
     */
    public function ip(array $trustedProxies = []): string
    {
        $ip = $this->serverString('REMOTE_ADDR') ?? '';
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new UnexpectedValueException('Request::ip(): REMOTE_ADDR is missing or is not a valid IP address.');
        }

        $networks = $this->networks($trustedProxies);
        if (! $this->ipInNetworks($ip, $networks)) {
            return $ip;
        }

        $forwarded = array_reverse(array_map(trim(...), explode(',', $this->header('X-Forwarded-For') ?? '')));
        foreach ($forwarded as $hop) {
            if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                break;
            }

            $ip = $hop;
            if (! $this->ipInNetworks($hop, $networks)) {
                break;
            }
        }

        return $ip;
    }

    // Parametri

    /**
     * Parameter from query string as received (string, array, or null if missing).
     */
    public function query(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->query) ? $this->query[$key] : $default;
    }

    /**
     * Parameter from request body (form, JSON, urlencoded PUT/PATCH/DELETE) as received.
     *
     * @throws UnexpectedValueException If JSON body is invalid.
     */
    public function post(string $key, mixed $default = null): mixed
    {
        $body = $this->bodyParams();

        return array_key_exists($key, $body) ? $body[$key] : $default;
    }

    /**
     * Parameter from body or, if missing, from query string, as received.
     *
     * @throws UnexpectedValueException If JSON body is invalid.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /**
     * All parameters: query string overridden by body.
     *
     * @return array<array-key, mixed>
     *
     * @throws UnexpectedValueException If JSON body is invalid.
     */
    public function all(): array
    {
        return array_replace($this->query, $this->bodyParams());
    }

    /**
     * @return array<array-key, mixed> Only requested keys that exist.
     *
     * @throws UnexpectedValueException If JSON body is invalid.
     */
    public function only(string ...$keys): array
    {
        return array_intersect_key($this->all(), array_flip($keys));
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws UnexpectedValueException If JSON body is invalid.
     */
    public function except(string ...$keys): array
    {
        return array_diff_key($this->all(), array_flip($keys));
    }

    /**
     * Checks if the parameter exists (even if empty).
     *
     * @throws UnexpectedValueException If JSON body is invalid.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    /**
     * Checks if the parameter exists and is not blank (null, '', whitespace-only, []).
     *
     * @throws UnexpectedValueException If JSON body is invalid.
     */
    public function filled(string $key): bool
    {
        return ! $this->isBlank($this->input($key));
    }

    // Getter-e tipizate

    /**
     * String value (unmodified: whitespace is preserved).
     *
     * @throws UnexpectedValueException If missing, empty, not a string, or invalid UTF-8.
     */
    public function string(string $key): string
    {
        return $this->stringValue('string', $key) ?? throw $this->missing('string', $key);
    }

    /**
     * @throws UnexpectedValueException If not a string or invalid UTF-8.
     */
    public function stringOrNull(string $key): ?string
    {
        return $this->stringValue('stringOrNull', $key);
    }

    /**
     * Integer value: '12', '-5' (trimmed whitespace; '012', '1.0', '12abc' rejected).
     *
     * @throws UnexpectedValueException If missing, empty, or not an integer.
     */
    public function int(string $key): int
    {
        return $this->intValue('int', $key) ?? throw $this->missing('int', $key);
    }

    /**
     * @throws UnexpectedValueException If not an integer.
     */
    public function intOrNull(string $key): ?int
    {
        return $this->intValue('intOrNull', $key);
    }

    /**
     * Float value with decimal point: '1.5', '-3' (no comma, exponent, or thousands separator).
     *
     * @throws UnexpectedValueException If missing, empty, or not a number.
     */
    public function float(string $key): float
    {
        return $this->floatValue('float', $key) ?? throw $this->missing('float', $key);
    }

    /**
     * @throws UnexpectedValueException If not a number.
     */
    public function floatOrNull(string $key): ?float
    {
        return $this->floatValue('floatOrNull', $key);
    }

    /**
     * Boolean: true/false, 1/0, yes/no, on/off, da/nu (case-insensitive).
     *
     * @throws UnexpectedValueException If missing, empty, or not a recognized boolean value.
     */
    public function bool(string $key): bool
    {
        return $this->boolValue('bool', $key) ?? throw $this->missing('bool', $key);
    }

    /**
     * An unchecked checkbox is omitted entirely: boolOrNull('activ') ?? false.
     *
     * @throws UnexpectedValueException If not a recognized boolean value.
     */
    public function boolOrNull(string $key): ?bool
    {
        return $this->boolValue('boolOrNull', $key);
    }

    /**
     * Real calendar date strictly matching the format (e.g. 31.02 and 2-digit years rejected).
     *
     * @throws UnexpectedValueException If missing, empty, or not a valid date.
     */
    public function date(string $key, string $format = 'Y-m-d'): DateTimeImmutable
    {
        return $this->dateValue('date', $key, $format) ?? throw $this->missing('date', $key);
    }

    /**
     * @throws UnexpectedValueException If not a valid date in the given format.
     */
    public function dateOrNull(string $key, string $format = 'Y-m-d'): ?DateTimeImmutable
    {
        return $this->dateValue('dateOrNull', $key, $format);
    }

    /**
     * List of values (multi-select, checkboxes name="x[]"). Missing or empty = [].
     *
     * @return array<array-key, mixed>
     *
     * @throws UnexpectedValueException If value is not an array.
     */
    public function array(string $key): array
    {
        $value = $this->input($key);
        if ($this->isBlank($value)) {
            return [];
        }

        if (! is_array($value)) {
            throw $this->invalid('array', $key, 'a list');
        }

        return $value;
    }

    // Corp si fisiere

    /**
     * Raw request body.
     *
     * @throws RuntimeException If php://input cannot be read.
     */
    public function body(): string
    {
        if ($this->body === null) {
            $body = file_get_contents('php://input');
            if ($body === false) {
                throw new RuntimeException('Request::body(): cannot read php://input.');
            }

            $this->body = $body;
        }

        return $this->body;
    }

    /**
     * Decoded JSON body. Must be a JSON object or array.
     *
     * @return array<array-key, mixed>
     *
     * @throws UnexpectedValueException If body is empty, invalid JSON, or a scalar.
     */
    public function json(): array
    {
        $body = $this->body();
        if (trim($body) === '') {
            throw new UnexpectedValueException('Request::json(): the request body is empty.');
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $e) {
            throw new UnexpectedValueException("Request::json(): {$e->getMessage()} (body length: ".strlen($body).' bytes).', 0, $e);
        }

        if (! is_array($data)) {
            throw new UnexpectedValueException('Request::json(): expected a JSON object or array, got '.get_debug_type($data).'.');
        }

        return $data;
    }

    /**
     * The $_FILES entry for a field or null if no file was uploaded.
     * Validation (MIME inspection, size, storage) is performed via Upload.
     *
     * @return array<array-key, mixed>|null
     */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        if (! is_array($file) || ($file['error'] ?? null) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return $file;
    }

    /**
     * Parameters from the body: $_POST, JSON, or for PUT/PATCH/DELETE, urlencoded form from php://input.
     *
     * @return array<array-key, mixed>
     *
     * @throws UnexpectedValueException If JSON body is invalid.
     */
    private function bodyParams(): array
    {
        if ($this->bodyParams !== null) {
            return $this->bodyParams;
        }

        if ($this->isJson()) {
            $params = trim($this->body()) === '' ? [] : $this->json();
        } elseif ($this->post !== [] || $this->isMethod('POST')) {
            $params = $this->post;
        } elseif (str_starts_with(strtolower($this->header('Content-Type') ?? ''), 'application/x-www-form-urlencoded')) {
            parse_str($this->body(), $params);
        } else {
            $params = [];
        }

        return $this->bodyParams = $params;
    }

    private function stringValue(string $method, string $key): ?string
    {
        $value = $this->input($key);
        if ($this->isBlank($value)) {
            return null;
        }

        if (! is_string($value)) {
            throw $this->invalid($method, $key, 'text');
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            throw $this->invalid($method, $key, 'valid UTF-8 text');
        }

        return $value;
    }

    private function intValue(string $method, string $key): ?int
    {
        $value = $this->input($key);
        if ($this->isBlank($value)) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        $int = is_string($value) ? filter_var(trim($value), FILTER_VALIDATE_INT) : false;
        if ($int === false) {
            throw $this->invalid($method, $key, 'an integer');
        }

        return $int;
    }

    private function floatValue(string $method, string $key): ?float
    {
        $value = $this->input($key);
        if ($this->isBlank($value)) {
            return null;
        }

        if (is_int($value) || (is_float($value) && is_finite($value))) {
            return (float) $value;
        }

        if (! is_string($value) || preg_match(self::FLOAT_PATTERN, trim($value)) !== 1) {
            throw $this->invalid($method, $key, 'a number with "." as decimal separator');
        }

        return (float) trim($value);
    }

    private function boolValue(string $method, string $key): ?bool
    {
        $value = $this->input($key);
        if ($this->isBlank($value)) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        $text = is_string($value) || is_int($value) ? strtolower(trim((string) $value)) : null;

        return match (true) {
            in_array($text, self::TRUE_VALUES, true) => true,
            in_array($text, self::FALSE_VALUES, true) => false,
            default => throw $this->invalid($method, $key, 'a boolean (true/false, 1/0, yes/no, on/off, da/nu)'),
        };
    }

    private function dateValue(string $method, string $key, string $format): ?DateTimeImmutable
    {
        $value = $this->input($key);
        if ($this->isBlank($value)) {
            return null;
        }

        $invalid = $this->invalid($method, $key, "a valid date in the format '{$format}'");
        if (! is_string($value)) {
            throw $invalid;
        }

        // '!' pune la zero tot ce nu apare in format (altfel ora lipsa ar fi ora curenta).
        $date = DateTimeImmutable::createFromFormat('!'.$format, trim($value));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['error_count'] > 0 || $errors['warning_count'] > 0))) {
            throw $invalid;
        }

        if (str_contains($format, 'Y') && (int) $date->format('Y') < 1000) {
            throw $invalid;
        }

        return $date;
    }

    private function serverString(string $key): ?string
    {
        $value = $this->server[$key] ?? null;

        return is_string($value) ? $value : (is_int($value) ? (string) $value : null);
    }

    /**
     * Trusted networks as [binary address, prefix length].
     *
     * @param  list<string>  $list  IP addresses or CIDR networks.
     * @return list<array{string, int}>
     *
     * @throws InvalidArgumentException If an entry is not a valid IP or CIDR network.
     */
    private function networks(array $list): array
    {
        $networks = [];
        foreach ($list as $entry) {
            $slash = strpos($entry, '/');
            $address = $slash === false ? $entry : substr($entry, 0, $slash);
            $bits = $slash === false ? null : substr($entry, $slash + 1);
            $binary = filter_var($address, FILTER_VALIDATE_IP) === false ? false : inet_pton($address);
            $max = $binary === false ? 0 : strlen($binary) * 8;

            if ($binary === false || ($bits !== null && (preg_match('/^\d{1,3}$/', $bits) !== 1 || (int) $bits > $max))) {
                throw new InvalidArgumentException("Request::ip(): invalid trusted proxy '{$entry}' (expected an IP address or a CIDR network).");
            }

            $networks[] = [$binary, $bits === null ? $max : (int) $bits];
        }

        return $networks;
    }

    /**
     * @param  list<array{string, int}>  $networks
     */
    private function ipInNetworks(string $ip, array $networks): bool
    {
        $binary = inet_pton($ip);
        if ($binary === false) {
            return false;
        }

        foreach ($networks as [$network, $prefix]) {
            if (strlen($network) !== strlen($binary)) {
                continue;
            }

            $bytes = intdiv($prefix, 8);
            $rest = $prefix % 8;
            if (substr($binary, 0, $bytes) !== substr($network, 0, $bytes)) {
                continue;
            }

            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ($rest === 0 || (ord($binary[$bytes]) & $mask) === (ord($network[$bytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || $value === [] || (is_string($value) && trim($value) === '');
    }

    private function missing(string $method, string $key): UnexpectedValueException
    {
        return new UnexpectedValueException("Request::{$method}(): parameter '{$key}' is missing or empty.");
    }

    private function invalid(string $method, string $key, string $expected): UnexpectedValueException
    {
        return new UnexpectedValueException("Request::{$method}(): parameter '{$key}' must be {$expected}.");
    }
}
