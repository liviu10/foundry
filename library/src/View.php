<?php

declare(strict_types=1);

namespace Foundry;

use InvalidArgumentException;
use RuntimeException;
use Stringable;
use Throwable;
use UnexpectedValueException;

/**
 * View Class
 *
 * Renders PHP templates from a fixed directory with isolated variables and output buffering.
 * Natively, an include with an externally supplied name can read arbitrary files (../../config.php),
 * and an exception midway through rendering leaves partial output on screen with open buffers.
 *
 * Rules:
 *   - template name is a relative path without extension ('policies/list' -> policies/list.php);
 *     '..', absolute paths, and symlinks traversing outside the directory are rejected;
 *   - the template only sees passed variables, plus $e (HTML escaping) and $view (for partials);
 *     variable names starting with "__" are reserved;
 *   - on exception within a template, partial output is discarded and the exception is rethrown;
 *   - escaping rejects invalid UTF-8 text (throws an exception rather than corrupting text).
 *
 * Usage:
 *   require_once 'View.php';
 *   use Foundry\View;
 *
 *   $view = new View(__DIR__.'/views', shared: ['application' => 'Policies']);
 *   echo $view->render('policies/list', ['policies' => $policies]);
 *
 *   // views/policies/list.php
 *   <h1><?= $e($application) ?></h1>
 *   <?php foreach ($policies as $policy): ?>
 *       <?= $view->render('policies/row', ['policy' => $policy]) ?>
 *   <?php endforeach ?>
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
final readonly class View
{
    /** @var list<string> Variable names that cannot be passed to templates. */
    private const array RESERVED = ['this', 'e', 'view', 'GLOBALS', '_SERVER', '_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_REQUEST', '_ENV'];

    private string $directory;

    /** @var array<string, mixed> */
    private array $shared;

    /**
     * @param  string  $directory  Template directory.
     * @param  array<string, mixed>  $shared  Variables available across all templates.
     *
     * @throws RuntimeException If the directory does not exist.
     * @throws InvalidArgumentException If a variable name is invalid or reserved.
     */
    public function __construct(string $directory, array $shared = [])
    {
        $real = realpath($directory);
        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException("View: template directory '{$directory}' does not exist.");
        }

        $this->assertVariables('__construct', $shared);

        $this->directory = $real;
        $this->shared = $shared;
    }

    /**
     * Renders a template and returns the rendered HTML.
     *
     * @param  array<string, mixed>  $data  Template variables (override shared variables).
     *
     * @throws InvalidArgumentException If template name or any variable name is invalid.
     * @throws RuntimeException If template does not exist or resolves outside the directory.
     * @throws Throwable Exception thrown by the template, after partial output has been discarded.
     */
    public function render(string $name, array $data = []): string
    {
        $file = $this->resolve('render', $name);
        $this->assertVariables('render', $data);

        $variables = array_replace($this->shared, $data);
        $variables['e'] = self::escape(...);
        $variables['view'] = $this;

        $level = ob_get_level();
        ob_start();

        try {
            // Closure statica: template-ul nu vede $this si nici variabilele locale ale metodei
            // (doar $__file; numele cu "__" sunt rezervate, deci datele nu il pot inlocui).
            (static function (string $__file, array $__data): void {
                extract($__data, EXTR_SKIP);
                unset($__data);
                include $__file;
            })($file, $variables);
        } catch (Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw $e;
        }

        // Buffer-ele lasate deschise de template sunt incluse in rezultat, apoi inchise.
        $output = '';
        while (ob_get_level() > $level) {
            $output = ob_get_clean().$output;
        }

        return $output;
    }

    /**
     * Checks whether a template exists (invalid name throws an exception, rather than returning false).
     *
     * @throws InvalidArgumentException If the name is invalid.
     */
    public function exists(string $name): bool
    {
        $this->assertName('exists', $name);

        return is_file($this->directory.DIRECTORY_SEPARATOR.$name.'.php');
    }

    /**
     * Escapes text for HTML (content and attributes); null becomes an empty string.
     *
     * @throws UnexpectedValueException If text is not valid UTF-8.
     */
    public static function escape(string|int|float|Stringable|null $value): string
    {
        $text = (string) $value;
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new UnexpectedValueException('View::escape(): text is not valid UTF-8 (length '.strlen($text).' bytes).');
        }

        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function resolve(string $method, string $name): string
    {
        $this->assertName($method, $name);

        $path = $this->directory.DIRECTORY_SEPARATOR.$name.'.php';
        $real = realpath($path);
        if ($real === false || ! is_file($real)) {
            throw new RuntimeException("View::{$method}(): template '{$name}' not found in '{$this->directory}'.");
        }

        // Un symlink din director poate indica in afara lui.
        if (! str_starts_with($real, $this->directory.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("View::{$method}(): template '{$name}' resolves outside the template directory.");
        }

        return $real;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertName(string $method, string $name): void
    {
        if (preg_match('#^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*$#', $name) !== 1) {
            throw new InvalidArgumentException("View::{$method}(): invalid template name '{$name}' (expected a relative path like 'polite/lista', without extension).");
        }
    }

    /**
     * @param  array<array-key, mixed>  $variables
     *
     * @throws InvalidArgumentException
     */
    private function assertVariables(string $method, array $variables): void
    {
        foreach (array_keys($variables) as $key) {
            $key = (string) $key;
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
                throw new InvalidArgumentException("View::{$method}(): '{$key}' is not a valid variable name.");
            }

            if (in_array($key, self::RESERVED, true) || str_starts_with($key, '__')) {
                throw new InvalidArgumentException("View::{$method}(): the variable name '{$key}' is reserved.");
            }
        }
    }
}
