<?php

declare(strict_types=1);

namespace FoundrySite;

use RuntimeException;

final class Support
{
    public static function e(string|int|float|null $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    public static function slug(string $text): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $text), '-'));

        return $slug === '' ? 'section' : $slug;
    }

    public static function kebab(string $name): string
    {
        return strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '-', $name));
    }

    /** Shortens text to $max characters on a word boundary. */
    public static function excerpt(string $text, int $max): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > $max * 0.6) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, " ,;:.-(").'…';
    }

    /** Removes backticks used as inline-code markers in README text. */
    public static function plain(string $markdown): string
    {
        return str_replace('`', '', $markdown);
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    public static function dedent(array $lines): array
    {
        $min = PHP_INT_MAX;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $min = min($min, strlen($line) - strlen(ltrim($line, ' ')));
        }
        if ($min === PHP_INT_MAX || $min === 0) {
            return array_map(rtrim(...), $lines);
        }

        return array_map(static fn (string $l): string => rtrim(substr($l, min($min, strlen($l) - strlen(ltrim($l, ' '))))), $lines);
    }

    public static function write(string $path, string $content): void
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0o775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create directory {$dir}.");
        }
        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException("Cannot write {$path}.");
        }
    }

    public static function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    /** Last commit date of a file (ISO 8601), falling back to its modification time. */
    public static function lastModified(string $path): string
    {
        static $git = null;
        $git ??= self::gitAvailable();
        if ($git) {
            $cmd = 'git -C '.escapeshellarg(dirname($path)).' log -1 --format=%cI -- '.escapeshellarg(basename($path)).' 2>'.(PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
            $out = trim((string) shell_exec($cmd));
            if (preg_match('/^\d{4}-\d{2}-\d{2}T/', $out)) {
                return $out;
            }
        }

        return date('c', (int) filemtime($path));
    }

    private static function gitAvailable(): bool
    {
        return function_exists('shell_exec') && trim((string) shell_exec('git --version')) !== '';
    }

    public static function humanBytes(int $bytes): string
    {
        return $bytes >= 1048576
            ? number_format($bytes / 1048576, 1).' MB'
            : number_format($bytes / 1024, 0).' KB';
    }
}
