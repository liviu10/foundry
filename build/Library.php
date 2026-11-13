<?php

declare(strict_types=1);

namespace FoundrySite;

use ReflectionClass;
use ReflectionClassConstant;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/**
 * Reads the library (README catalog + source files via Reflection) into plain arrays.
 * Nothing in the library is modified; its files are only required (they have no side effects).
 */
final class Library
{
    /** @var array<string, array<string, mixed>> class name => model, in catalog order */
    public array $classes = [];

    /** @var list<array{name: string, slug: string, classes: list<string>}> */
    public array $categories = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @param  array<string, mixed>  $config */
    public function __construct(private readonly array $config) {}

    public function load(): void
    {
        $catalog = $this->parseReadme((string) $this->config['readme']);
        $files = glob(rtrim((string) $this->config['source_dir'], '/\\').'/*.php') ?: [];
        if ($files === []) {
            throw new RuntimeException('No source files found in '.$this->config['source_dir']);
        }
        sort($files);
        foreach ($files as $file) {
            require_once $file;
        }

        $models = [];
        foreach ($files as $file) {
            $name = basename($file, '.php');
            $fqcn = 'Foundry\\'.$name;
            if (! class_exists($fqcn, false)) {
                $this->warnings[] = "{$name}.php does not declare class {$fqcn}; skipped.";

                continue;
            }
            $models[$name] = $this->describeClass(new ReflectionClass($fqcn), $file);
        }

        // Categories in README order; classes missing from the catalog go to "Other".
        foreach ($catalog as $category) {
            $names = array_values(array_filter($category['classes'], static fn (string $n): bool => isset($models[$n])));
            $this->categories[] = ['name' => $category['name'], 'slug' => Support::slug($category['name']), 'classes' => $names];
        }
        $listed = array_merge(...array_map(static fn (array $c): array => $c['classes'], $this->categories));
        $orphans = array_values(array_diff(array_keys($models), $listed));
        if ($orphans !== []) {
            $this->warnings[] = 'Not in README catalog (shown under "Other"): '.implode(', ', $orphans);
            $this->categories[] = ['name' => 'Other', 'slug' => 'other', 'classes' => $orphans];
        }

        $overviews = array_merge(...array_map(static fn (array $c): array => $c['overviews'], $catalog));
        foreach ($this->categories as $category) {
            foreach ($category['classes'] as $name) {
                $model = $models[$name];
                $model['category'] = ['name' => $category['name'], 'slug' => $category['slug']];
                $model['overview'] = $overviews[$name] ?? $model['lead'];
                $this->classes[$name] = $model;
            }
        }
    }

    public function slugFor(string $class): string
    {
        return $this->config['slug_overrides'][$class] ?? Support::kebab($class);
    }

    public function methodCount(): int
    {
        return array_sum(array_map(static fn (array $c): int => count($c['methods']) + count($c['functions']), $this->classes));
    }

    // ------------------------------------------------------------------ README

    /** @return list<array{name: string, classes: list<string>, overviews: array<string, string>}> */
    private function parseReadme(string $path): array
    {
        $readme = (string) file_get_contents($path);
        $categories = [];

        if (preg_match('/^## Catalog\s*$(.*?)^## /ms', $readme, $m)) {
            $current = null;
            foreach (explode("\n", $m[1]) as $line) {
                if (preg_match('/^### (.+)$/', trim($line), $h)) {
                    $categories[] = ['name' => trim($h[1]), 'classes' => [], 'overviews' => []];
                    $current = array_key_last($categories);

                    continue;
                }
                if ($current !== null && preg_match('/^\|\s*`(\w+)`\s*\|\s*(.+?)\s*\|\s*$/', trim($line), $row)) {
                    $categories[$current]['classes'][] = $row[1];
                    $categories[$current]['overviews'][$row[1]] = $row[2];
                }
            }
        } else {
            $this->warnings[] = 'README has no "## Catalog" section; categories unavailable.';
        }

        return $categories;
    }

    // ------------------------------------------------------------------ classes

    /** @return array<string, mixed> */
    private function describeClass(ReflectionClass $rc, string $file): array
    {
        $name = $rc->getShortName();
        $source = str_replace("\r\n", "\n", (string) file_get_contents($file));
        $lines = explode("\n", $source);
        $doc = DocBlock::parse($rc->getDocComment());
        $blocks = $this->parseHeader($doc->text, $name);

        $lead = '';
        foreach ($blocks as $i => $block) {
            if ($block['type'] === 'p') {
                $lead = $block['text'];
                unset($blocks[$i]);
            }
            break;
        }

        $methods = [];
        foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $rc->getName()) {
                continue;
            }
            if (str_starts_with($method->getName(), '__') && ! $method->isConstructor() && $method->getDocComment() === false) {
                continue;
            }
            $methods[] = $this->describeFunction($method, $lines);
        }
        usort($methods, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);

        $functions = [];
        foreach (get_defined_functions()['user'] as $fn) {
            $rf = new ReflectionFunction($fn);
            if ($rf->getFileName() !== false && realpath($rf->getFileName()) === realpath($file)) {
                $functions[] = $this->describeFunction($rf, $lines);
            }
        }

        return [
            'name' => $name,
            'fqcn' => $rc->getName(),
            'slug' => $this->slugFor($name),
            'file' => basename($file),
            'path' => $file,
            'version' => trim($doc->tags['version'][0] ?? '1.0'),
            'since' => trim($doc->tags['since'][0] ?? 'PHP 8.3'),
            'kind' => $rc->isEnum() ? 'enum' : ($rc->isInterface() ? 'interface' : 'class'),
            'final' => $rc->isFinal(),
            'readonly' => $rc->isReadOnly(),
            'lead' => $lead,
            'blocks' => array_values($blocks),
            'constants' => $this->describeConstants($rc, $source),
            'properties' => $this->describeProperties($rc),
            'methods' => $methods,
            'functions' => $functions,
            'extensions' => $this->detectExtensions($source),
            'lastmod' => Support::lastModified($file),
            'lines' => count($lines),
        ];
    }

    /**
     * Class header text -> blocks: p, heading, list, spec (preformatted), code.
     *
     * @param  list<string>  $lines
     * @return list<array<string, mixed>>
     */
    private function parseHeader(array $lines, string $class): array
    {
        if ($lines !== [] && preg_match('/^'.preg_quote($class, '/').'\s+(Class|Enum|Interface)\s*$/i', trim($lines[0]))) {
            array_shift($lines);
        }

        $indent = static fn (string $l): int => strlen($l) - strlen(ltrim($l, ' '));
        $raw = [];
        $n = count($lines);
        for ($i = 0; $i < $n;) {
            if (trim($lines[$i]) === '') {
                $i++;

                continue;
            }
            if ($indent($lines[$i]) >= 2) {
                $block = [];
                while ($i < $n) {
                    if (trim($lines[$i]) === '') {
                        $j = $i;
                        while ($j < $n && trim($lines[$j]) === '') {
                            $j++;
                        }
                        if ($j < $n && $indent($lines[$j]) >= 2) {
                            for (; $i < $j; $i++) {
                                $block[] = '';
                            }

                            continue;
                        }
                        break;
                    }
                    if ($indent($lines[$i]) < 2) {
                        break;
                    }
                    $block[] = $lines[$i++];
                }
                $raw[] = ['type' => 'indented', 'lines' => Support::dedent($block)];

                continue;
            }
            $para = [];
            while ($i < $n && trim($lines[$i]) !== '' && $indent($lines[$i]) < 2) {
                $para[] = trim($lines[$i++]);
            }
            $raw[] = ['type' => 'p', 'text' => implode(' ', $para)];
        }

        $blocks = [];
        $count = count($raw);
        for ($i = 0; $i < $count; $i++) {
            $block = $raw[$i];
            $next = $raw[$i + 1] ?? null;
            if ($block['type'] === 'p') {
                $text = $block['text'];
                $introducesBlock = str_ends_with($text, ':') && $next !== null && $next['type'] === 'indented';
                if ($introducesBlock && preg_match('/^(Usage|Examples?):$/i', $text)) {
                    $blocks[] = ['type' => 'heading', 'text' => 'Usage'];
                    $blocks[] = ['type' => 'code', 'lang' => 'php', 'text' => implode("\n", $next['lines'])];
                    $i++;

                    continue;
                }
                if ($introducesBlock && mb_strlen($text) <= 48 && ! preg_match('/[(\[.,;\'"`=]/', rtrim($text, ':'))) {
                    $blocks[] = ['type' => 'heading', 'text' => rtrim($text, ':')];

                    continue;
                }
                $blocks[] = ['type' => 'p', 'text' => $text];

                continue;
            }
            $blocks[] = $this->classifyIndented($block['lines']);
        }

        return $blocks;
    }

    /**
     * @param  list<string>  $lines
     * @return array<string, mixed>
     */
    private function classifyIndented(array $lines): array
    {
        $looksLikeCode = (bool) preg_grep('/^(use Foundry\\\\|require_once|\$\w+\s*=|foreach \()/', $lines);
        if ($looksLikeCode) {
            return ['type' => 'code', 'lang' => 'php', 'text' => implode("\n", $lines)];
        }

        $isList = isset($lines[0]) && str_starts_with($lines[0], '- ');
        $items = [];
        foreach ($lines as $line) {
            if (str_starts_with($line, '- ')) {
                $items[] = substr($line, 2);
            } elseif ($isList && $items !== [] && (str_starts_with($line, ' ') || trim($line) === '')) {
                $items[array_key_last($items)] .= ' '.trim($line);
            } else {
                $isList = false;
                break;
            }
        }
        if ($isList) {
            return ['type' => 'list', 'items' => array_map(static fn (string $s): string => trim(rtrim(trim($s), ';')), $items)];
        }

        return ['type' => 'spec', 'text' => implode("\n", $lines)];
    }

    /**
     * @param  list<string>  $lines
     * @return array<string, mixed>
     */
    private function describeFunction(ReflectionFunctionAbstract $fn, array $lines): array
    {
        $doc = DocBlock::parse($fn->getDocComment());
        $params = [];
        foreach ($fn->getParameters() as $p) {
            $native = $p->hasType() ? (string) $p->getType() : '';
            $params[] = [
                'name' => $p->getName(),
                'type' => $doc->params[$p->getName()]['type'] ?? $native,
                'native' => $native,
                'desc' => $doc->params[$p->getName()]['desc'] ?? '',
                'optional' => $p->isOptional(),
                'variadic' => $p->isVariadic(),
                'default' => $this->defaultValue($p),
            ];
        }

        $paragraphs = $doc->paragraphs();
        $isMethod = $fn instanceof ReflectionMethod;
        $returnNative = $fn->hasReturnType() ? (string) $fn->getReturnType() : '';

        return [
            'name' => $fn->getShortName(),
            'id' => ($isMethod ? 'method-' : 'function-').strtolower($fn->getShortName()),
            'kind' => $isMethod ? ($fn->isConstructor() ? 'constructor' : ($fn->isStatic() ? 'static' : 'method')) : 'function',
            'line' => (int) $fn->getStartLine(),
            'signature' => $this->signature($lines, (int) $fn->getStartLine()),
            'summary' => $paragraphs[0] ?? '',
            'description' => array_slice($paragraphs, 1),
            'params' => $params,
            'return' => $fn instanceof ReflectionMethod && $fn->isConstructor() ? null : [
                'type' => $doc->return['type'] ?? $returnNative,
                'desc' => $doc->return['desc'] ?? '',
            ],
            'throws' => $doc->throws,
            'deprecated' => isset($doc->tags['deprecated']),
        ];
    }

    private function defaultValue(ReflectionParameter $p): ?string
    {
        if (! $p->isDefaultValueAvailable()) {
            return null;
        }
        try {
            if ($p->isDefaultValueConstant()) {
                return str_replace('Foundry\\', '', (string) $p->getDefaultValueConstantName());
            }
            $value = $p->getDefaultValue();
        } catch (Throwable) {
            return '…';
        }

        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => $value === [] ? '[]' : '[…]',
            is_string($value) => "'".addcslashes($value, "'\\")."'",
            is_int($value), is_float($value) => var_export($value, true),
            default => '…',
        };
    }

    /** @param  list<string>  $lines */
    private function signature(array $lines, int $start): string
    {
        $out = [];
        $total = count($lines);
        for ($i = max(0, $start - 1); $i < $total && count($out) < 60; $i++) {
            $line = rtrim($lines[$i]);
            $trim = trim($line);
            if ($trim === '{') {
                break;
            }
            if (str_contains($trim, '{')) {
                $out[] = rtrim(substr($line, 0, (int) strpos($line, '{')));
                break;
            }
            $out[] = $line;
            if (str_ends_with($trim, ';')) {
                break;
            }
        }

        return implode("\n", Support::dedent($out));
    }

    /** @return list<array<string, string>> */
    private function describeConstants(ReflectionClass $rc, string $source): array
    {
        $out = [];
        foreach ($rc->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $c) {
            if ($c->getDeclaringClass()->getName() !== $rc->getName()) {
                continue;
            }
            $doc = DocBlock::parse($c->getDocComment());
            [$docType, $desc] = DocBlock::splitType($doc->tags['var'][0] ?? '');
            if ($desc === '' && $docType !== '' && ! preg_match('/^[a-z\\\\<>|\[\]?,{}:\s]+$/i', $docType)) {
                [$docType, $desc] = ['', $doc->tags['var'][0] ?? ''];
            }
            $native = method_exists($c, 'getType') && $c->getType() !== null ? (string) $c->getType() : '';
            $expr = '';
            if (preg_match('/const\s+(?:[\w\\\\|?]+\s+)?'.preg_quote($c->getName(), '/').'\s*=\s*(.+?);\s*$/ms', $source, $m)) {
                $expr = trim((string) preg_replace('/\s+/', ' ', $m[1]));
            }
            $out[] = [
                'name' => $c->getName(),
                'type' => $docType !== '' ? $docType : $native,
                'value' => Support::excerpt($expr, 90),
                'desc' => trim((string) preg_replace('/\s+/', ' ', $desc !== '' ? $desc : implode(' ', $doc->paragraphs()))),
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function describeProperties(ReflectionClass $rc): array
    {
        $ctorDoc = $rc->getConstructor() !== null ? DocBlock::parse($rc->getConstructor()->getDocComment()) : new DocBlock;
        $out = [];
        foreach ($rc->getProperties(ReflectionProperty::IS_PUBLIC) as $p) {
            if ($p->getDeclaringClass()->getName() !== $rc->getName()) {
                continue;
            }
            $doc = DocBlock::parse($p->getDocComment());
            [$type, $desc] = DocBlock::splitType($doc->tags['var'][0] ?? '');
            $out[] = [
                'name' => $p->getName(),
                'type' => $type !== '' ? $type : ($p->hasType() ? (string) $p->getType() : 'mixed'),
                'readonly' => $p->isReadOnly(),
                'static' => $p->isStatic(),
                'desc' => trim($desc !== '' ? $desc : ($ctorDoc->params[$p->getName()]['desc'] ?? implode(' ', $doc->paragraphs()))),
            ];
        }

        return $out;
    }

    /**
     * PHP extensions a file needs, detected from the identifiers it actually uses
     * (comments and docblocks are ignored, so examples do not count).
     *
     * @return list<string>
     */
    private function detectExtensions(string $source): array
    {
        $idents = [];
        $literals = [];
        foreach (token_get_all($source) as $t) {
            if (! is_array($t)) {
                continue;
            }
            if (in_array($t[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)) {
                $idents[strtolower(ltrim($t[1], '\\'))] = true;
            } elseif ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
                $literals[] = strtolower($t[1]);
            }
        }
        $has = static fn (string $regex): bool => (bool) preg_grep($regex, array_keys($idents));
        $literal = static fn (string $needle): bool => (bool) array_filter($literals, static fn (string $l): bool => str_contains($l, $needle));

        $rules = [
            'mbstring' => $has('/^mb_/'),
            'iconv' => $has('/^iconv(_|$)/'),
            'fileinfo' => $has('/^(finfo|finfo_\w+|mime_content_type)$/'),
            'session' => $has('/^session_/'),
            'bcmath' => $has('/^bc(add|sub|mul|div|comp|mod|pow|powmod|scale|sqrt|round|floor|ceil)$/'),
            'curl' => $has('/^curl_/'),
            'openssl' => $has('/^(openssl_\w+|stream_socket_enable_crypto)$/'),
            'sodium' => $has('/^sodium_/'),
            'zip' => isset($idents['ziparchive']),
            'xmlreader' => isset($idents['xmlreader']),
            'xmlwriter' => isset($idents['xmlwriter']),
            'dom' => $has('/^dom(document|xpath|element|node)$/'),
            'simplexml' => $has('/^(simplexml_\w+|simplexmlelement)$/'),
            'gd' => $has('/^(gdimage|image(create\w*|copy\w*|destroy|png|jpeg|gif|webp|avif|bmp|sx|sy|colorallocate\w*|alphablending|savealpha|scale|rotate|fill\w*|istruecolor))$/'),
            'intl' => $has('/^(normalizer|intldateformatter|numberformatter|collator|transliterator_\w+)$/'),
            'pdo_mysql' => $literal('mysql:'),
            'pdo_sqlite' => $literal('sqlite:'),
            'oci8' => $has('/^oci_/'),
        ];

        return array_keys(array_filter($rules));
    }
}
