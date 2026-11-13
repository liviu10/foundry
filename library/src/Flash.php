<?php

declare(strict_types=1);

namespace Foundry;

use InvalidArgumentException;
use LogicException;
use UnexpectedValueException;

/**
 * Flash Class
 *
 * User notification messages stored in the session until displayed (typically after a redirect):
 * "Policy was saved successfully.", "Access denied.", etc.
 * Returns raw data, not HTML: type, title, message, and metadata (e.g. toast duration);
 * the application template determines presentation.
 *
 * Rules:
 *   - the PHP session must be active beforehand (Session::start() or session_start());
 *   - types: success, error, warning, info; multiple messages can be queued in a single request;
 *   - pull() retrieves messages and clears them from session (display once); peek() inspects them without clearing;
 *   - strings are unescaped: HTML escaping must be performed at render time;
 *   - meta only accepts simple values (string, number, bool, null, array) to ensure safe session serialization.
 *
 * Usage:
 *   require_once 'Flash.php';
 *   use Foundry\Flash;
 *
 *   Flash::success('Policy was saved successfully.');
 *   Flash::error('Could not send email.', 'Error', ['delay' => 10000]);
 *   Response::redirect('/policies')->send();
 *
 *   // on the next page
 *   foreach (Flash::pull() as $flash) { ... $flash['type'], $flash['title'], $flash['message'], $flash['meta'] ... }
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
final class Flash
{
    /** @var string Session key. */
    private const string KEY = '__flash_messages';

    private const array TYPES = ['success', 'error', 'warning', 'info'];

    private function __construct() {}

    /**
     * @param  array<string, mixed>  $meta  Display metadata (e.g. ['delay' => 5000, 'redirect' => '/x']).
     *
     * @throws InvalidArgumentException If type, message, or meta are invalid.
     * @throws LogicException If the session is not started.
     */
    public static function add(string $type, string $message, ?string $title = null, array $meta = []): void
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Flash::add(): unknown type '{$type}' (expected ".implode(', ', self::TYPES).').');
        }

        if (trim($message) === '' || ! mb_check_encoding($message, 'UTF-8')) {
            throw new InvalidArgumentException('Flash::add(): the message is empty or not valid UTF-8.');
        }

        if ($title !== null && (trim($title) === '' || ! mb_check_encoding($title, 'UTF-8'))) {
            throw new InvalidArgumentException('Flash::add(): the title is empty or not valid UTF-8 (use null for no title).');
        }

        self::assertMeta($meta, 'meta');

        $messages = self::messages('add');
        $messages[] = ['type' => $type, 'title' => $title, 'message' => $message, 'meta' => $meta];
        $_SESSION[self::KEY] = $messages;
    }

    /**
     * @param  array<string, mixed>  $meta
     *
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    public static function success(string $message, ?string $title = null, array $meta = []): void
    {
        self::add('success', $message, $title, $meta);
    }

    /**
     * @param  array<string, mixed>  $meta
     *
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    public static function error(string $message, ?string $title = null, array $meta = []): void
    {
        self::add('error', $message, $title, $meta);
    }

    /**
     * @param  array<string, mixed>  $meta
     *
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    public static function warning(string $message, ?string $title = null, array $meta = []): void
    {
        self::add('warning', $message, $title, $meta);
    }

    /**
     * @param  array<string, mixed>  $meta
     *
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    public static function info(string $message, ?string $title = null, array $meta = []): void
    {
        self::add('info', $message, $title, $meta);
    }

    /**
     * Retrieves messages in the order they were added and clears them from the session.
     *
     * @return list<array{type: string, title: ?string, message: string, meta: array<string, mixed>}>
     *
     * @throws LogicException If the session is not started.
     * @throws UnexpectedValueException If session flash data is corrupted.
     */
    public static function pull(): array
    {
        $messages = self::messages('pull');
        unset($_SESSION[self::KEY]);

        return $messages;
    }

    /**
     * Retrieves messages without clearing them from the session.
     *
     * @return list<array{type: string, title: ?string, message: string, meta: array<string, mixed>}>
     *
     * @throws LogicException If the session is not started.
     * @throws UnexpectedValueException If session flash data is corrupted.
     */
    public static function peek(): array
    {
        return self::messages('peek');
    }

    /**
     * Checks if any messages exist (optionally filtered by type).
     *
     * @throws LogicException If the session is not started.
     * @throws UnexpectedValueException If session flash data is corrupted.
     */
    public static function has(?string $type = null): bool
    {
        foreach (self::messages('has') as $message) {
            if ($type === null || $message['type'] === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws LogicException If the session is not started.
     */
    public static function clear(): void
    {
        self::assertSession('clear');

        unset($_SESSION[self::KEY]);
    }

    /**
     * @return list<array{type: string, title: ?string, message: string, meta: array<string, mixed>}>
     *
     * @throws LogicException
     * @throws UnexpectedValueException
     */
    private static function messages(string $method): array
    {
        self::assertSession($method);

        $stored = $_SESSION[self::KEY] ?? [];
        if (! is_array($stored) || ! array_is_list($stored)) {
            throw self::corrupt($method);
        }

        $messages = [];
        foreach ($stored as $message) {
            if (! is_array($message)) {
                throw self::corrupt($method);
            }

            $type = $message['type'] ?? null;
            $title = $message['title'] ?? null;
            $text = $message['message'] ?? null;
            $meta = $message['meta'] ?? null;
            if (! in_array($type, self::TYPES, true) || ! is_string($text) || ($title !== null && ! is_string($title)) || ! is_array($meta)) {
                throw self::corrupt($method);
            }

            /** @var array<string, mixed> $meta */
            $messages[] = ['type' => $type, 'title' => $title, 'message' => $text, 'meta' => $meta];
        }

        return $messages;
    }

    /**
     * @param  array<array-key, mixed>  $meta
     *
     * @throws InvalidArgumentException
     */
    private static function assertMeta(array $meta, string $path): void
    {
        foreach ($meta as $key => $value) {
            if (is_array($value)) {
                self::assertMeta($value, "{$path}.{$key}");

                continue;
            }

            if (! is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException("Flash::add(): {$path}.{$key} must be a string, number, bool, null or array, got ".get_debug_type($value).'.');
            }
        }
    }

    /**
     * @throws LogicException
     */
    private static function assertSession(string $method): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new LogicException("Flash::{$method}(): the session is not started; start it before using flash messages.");
        }
    }

    private static function corrupt(string $method): UnexpectedValueException
    {
        return new UnexpectedValueException("Flash::{$method}(): the flash data in the session ('".self::KEY."') is corrupt.");
    }
}
