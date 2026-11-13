<?php

declare(strict_types=1);

namespace FoundrySite;

/**
 * HTML and Markdown fragments shared by pages: code blocks, headings and the class reference.
 */
final class Renderer
{
    private const PHP_CLASSES = [
        'Exception', 'ErrorException', 'Error', 'TypeError', 'ValueError', 'JsonException', 'ArithmeticError',
        'DivisionByZeroError', 'InvalidArgumentException', 'RuntimeException', 'UnexpectedValueException',
        'LogicException', 'DomainException', 'LengthException', 'OutOfRangeException', 'OutOfBoundsException',
        'RangeException', 'OverflowException', 'UnderflowException', 'BadFunctionCallException',
        'BadMethodCallException', 'PDOException', 'Throwable', 'Random\\RandomException',
    ];

    public function __construct(private readonly Linker $linker) {}

    public function codeBlock(string $code, string $lang = 'php', ?string $caption = null): string
    {
        $label = match (strtolower($lang)) {
            'php' => 'PHP', 'json' => 'JSON', 'bash', 'sh', 'shell' => 'Shell', 'powershell', 'ps1' => 'PowerShell',
            'ini', 'env' => strtoupper($lang), 'text', '' => 'Text', 'nginx' => 'Nginx', 'apache' => 'Apache',
            default => ucfirst($lang),
        };

        return '<div class="codeblock" data-lang="'.Support::e($lang).'">'
            .'<div class="codeblock-bar"><span class="codeblock-lang">'.Support::e($caption ?? $label).'</span>'
            .'<button type="button" class="copy-btn" data-copy hidden>Copy</button></div>'
            .'<pre><code>'.Highlighter::highlight(rtrim($code), $lang).'</code></pre></div>';
    }

    public function heading(int $level, string $id, string $innerHtml): string
    {
        return "<h{$level} id=\"".Support::e($id).'">'.$innerHtml
            .'<a class="anchor" href="#'.Support::e($id).'" aria-label="Permalink to this section">#</a>'
            ."</h{$level}>";
    }

    public function type(string $type): string
    {
        return $type === '' ? '<span class="muted">—</span>' : '<code class="type">'.Support::e($type).'</code>';
    }

    public function exceptionLink(string $type): string
    {
        $short = ltrim($type, '\\');
        if (in_array($short, self::PHP_CLASSES, true)) {
            $url = 'https://www.php.net/manual/en/class.'.strtolower(str_replace('\\', '-', $short)).'.php';

            return '<a class="code-link" href="'.$url.'" rel="noopener" target="_blank"><code>'.Support::e($short).'</code></a>';
        }

        return '<code>'.Support::e($short).'</code>';
    }

    /**
     * Class reference body.
     *
     * @param  array<string, mixed>  $c
     * @return array{html: string, toc: list<array{id: string, text: string, level: int}>}
     */
    public function classBody(array $c): array
    {
        $html = [];
        $toc = [];
        $used = ['installation' => true, 'constants' => true, 'properties' => true, 'methods' => true, 'functions' => true];
        $add = function (string $text, int $level = 2) use (&$toc, &$used): string {
            $id = Support::slug($text);
            for ($k = 2; isset($used[$id]); $k++) {
                $id = Support::slug($text).'-'.$k;
            }
            $used[$id] = true;
            $toc[] = ['id' => $id, 'text' => $text, 'level' => $level];

            return $id;
        };

        foreach ($c['blocks'] as $block) {
            $html[] = match ($block['type']) {
                'heading' => $this->heading(2, $add($block['text']), Support::e($block['text'])),
                'p' => '<p>'.$this->linker->prose($block['text']).'</p>',
                'list' => '<ul>'.implode('', array_map(fn (string $i): string => '<li>'.$this->linker->prose($i).'</li>', $block['items'])).'</ul>',
                'spec' => '<pre class="spec">'.Support::e($block['text']).'</pre>',
                'code' => $this->codeBlock($block['text'], $block['lang']),
                default => '',
            };
        }

        // Installation
        $toc[] = ['id' => 'installation', 'text' => 'Installation', 'level' => 2];
        $html[] = $this->heading(2, 'installation', 'Installation');
        $req = "require_once __DIR__.'/lib/Utilities/{$c['file']}';\n\nuse {$c['fqcn']};";
        if ($c['functions'] !== []) {
            $req .= "\n".implode("\n", array_map(static fn (array $f): string => 'use function Foundry\\'.$f['name'].';', $c['functions']));
        }
        $html[] = '<p>Copy <code>'.Support::e($c['file']).'</code> from the <a href="/docs/installation/#download">download archive</a> into your project and load it (or register the <a href="/docs/installation/#loading-the-classes">autoloader</a>):</p>';
        $html[] = $this->codeBlock($req, 'php');
        $html[] = $c['extensions'] === []
            ? '<p class="muted-note">No PHP extensions required beyond a default PHP '.Support::e(str_replace('PHP ', '', $c['since'])).'+ installation.</p>'
            : '<p class="muted-note">Requires the PHP extension'.(count($c['extensions']) > 1 ? 's' : '').' '
                .implode(', ', array_map(static fn (string $e): string => '<code>'.Support::e($e).'</code>', $c['extensions']))
                .' (check with <code>php -m</code>).</p>';

        if ($c['constants'] !== []) {
            $toc[] = ['id' => 'constants', 'text' => 'Constants', 'level' => 2];
            $html[] = $this->heading(2, 'constants', 'Constants');
            $rows = '';
            foreach ($c['constants'] as $k) {
                $rows .= '<tr><td><code>'.Support::e($k['name']).'</code></td><td>'.$this->type($k['type']).'</td>'
                    .'<td><code class="value">'.Support::e($k['value']).'</code></td><td>'.$this->linker->prose($k['desc']).'</td></tr>';
            }
            $html[] = '<div class="table-wrap"><table class="ref-table"><thead><tr><th scope="col">Constant</th><th scope="col">Type</th><th scope="col">Value</th><th scope="col">Description</th></tr></thead><tbody>'.$rows.'</tbody></table></div>';
        }

        if ($c['properties'] !== []) {
            $toc[] = ['id' => 'properties', 'text' => 'Properties', 'level' => 2];
            $html[] = $this->heading(2, 'properties', 'Properties');
            $rows = '';
            foreach ($c['properties'] as $p) {
                $flags = trim(($p['static'] ? 'static ' : '').($p['readonly'] ? 'readonly' : ''));
                $rows .= '<tr><td><code>$'.Support::e($p['name']).'</code>'.($flags !== '' ? ' <span class="pill">'.Support::e($flags).'</span>' : '').'</td>'
                    .'<td>'.$this->type($p['type']).'</td><td>'.$this->linker->prose($p['desc']).'</td></tr>';
            }
            $html[] = '<div class="table-wrap"><table class="ref-table"><thead><tr><th scope="col">Property</th><th scope="col">Type</th><th scope="col">Description</th></tr></thead><tbody>'.$rows.'</tbody></table></div>';
        }

        if ($c['methods'] !== []) {
            $toc[] = ['id' => 'methods', 'text' => 'Methods', 'level' => 2];
            $html[] = $this->heading(2, 'methods', 'Methods');
            $html[] = '<nav class="method-index" aria-label="'.Support::e($c['name']).' methods"><ul>'
                .implode('', array_map(static fn (array $m): string => '<li><a href="#'.$m['id'].'"><code>'.Support::e($m['name']).'()</code></a></li>', $c['methods']))
                .'</ul></nav>';
            foreach ($c['methods'] as $m) {
                $toc[] = ['id' => $m['id'], 'text' => $m['name'].'()', 'level' => 3];
                $html[] = $this->member($m, $c['name']);
            }
        }

        if ($c['functions'] !== []) {
            $toc[] = ['id' => 'functions', 'text' => 'Functions', 'level' => 2];
            $html[] = $this->heading(2, 'functions', 'Functions');
            $html[] = '<p>Global helpers defined in the <code>Foundry</code> namespace by <code>'.Support::e($c['file']).'</code>. Import them with <code>use function</code>.</p>';
            foreach ($c['functions'] as $f) {
                $toc[] = ['id' => $f['id'], 'text' => $f['name'].'()', 'level' => 3];
                $html[] = $this->member($f, $c['name']);
            }
        }

        return ['html' => implode("\n", $html), 'toc' => $toc];
    }

    /** @param  array<string, mixed>  $m */
    private function member(array $m, string $class): string
    {
        $badge = ['constructor' => 'Constructor', 'static' => 'Static', 'method' => 'Instance', 'function' => 'Function'][$m['kind']];
        $title = $m['kind'] === 'function' ? $m['name'].'()' : ($m['kind'] === 'static' ? $class.'::'.$m['name'].'()' : ($m['kind'] === 'constructor' ? 'new '.$class.'()' : '$'.lcfirst($class).'->'.$m['name'].'()'));

        $out = '<section class="member" id="'.$m['id'].'" aria-labelledby="'.$m['id'].'-title">';
        $out .= '<header class="member-head"><h3 id="'.$m['id'].'-title"><code>'.Support::e($title).'</code></h3>'
            .'<span class="badge badge-'.$m['kind'].'">'.$badge.'</span>'
            .($m['deprecated'] ? '<span class="badge badge-deprecated">Deprecated</span>' : '')
            .'<a class="anchor" href="#'.$m['id'].'" aria-label="Permalink to '.Support::e($m['name']).'">#</a></header>';
        $out .= '<pre class="signature"><code>'.Highlighter::php($m['signature']).'</code></pre>';
        if ($m['summary'] !== '') {
            $out .= '<p>'.$this->linker->prose($m['summary']).'</p>';
        }
        foreach ($m['description'] as $p) {
            $out .= '<p>'.$this->linker->prose($p).'</p>';
        }

        if ($m['params'] !== []) {
            $rows = '';
            foreach ($m['params'] as $p) {
                $default = $p['variadic'] ? '<span class="pill">variadic</span>'
                    : ($p['optional'] ? '<code class="value">'.Support::e($p['default'] ?? '').'</code>' : '<span class="pill pill-req">required</span>');
                $rows .= '<tr><td><code>$'.Support::e($p['name']).'</code></td><td>'.$this->type($p['type']).'</td><td>'.$default.'</td><td>'.$this->linker->prose($p['desc']).'</td></tr>';
            }
            $out .= '<div class="table-wrap"><table class="ref-table params"><caption>Parameters</caption><thead><tr><th scope="col">Name</th><th scope="col">Type</th><th scope="col">Default</th><th scope="col">Description</th></tr></thead><tbody>'.$rows.'</tbody></table></div>';
        }

        if ($m['return'] !== null && $m['return']['type'] !== '' && $m['return']['type'] !== 'void') {
            $out .= '<dl class="returns"><dt>Returns</dt><dd>'.$this->type($m['return']['type'])
                .($m['return']['desc'] !== '' ? ' <span>'.$this->linker->prose($m['return']['desc']).'</span>' : '').'</dd></dl>';
        }

        if ($m['throws'] !== []) {
            $out .= '<div class="throws"><p class="throws-title">Throws</p><ul>';
            foreach ($m['throws'] as $t) {
                $out .= '<li>'.$this->exceptionLink($t['type']).($t['desc'] !== '' ? ' — '.$this->linker->prose($t['desc']) : '').'</li>';
            }
            $out .= '</ul></div>';
        }

        return $out.'</section>';
    }

    /**
     * Markdown version of a class page (for /docs/<slug>.md, llms-full.txt and AI crawlers).
     *
     * @param  array<string, mixed>  $c
     */
    public function classMarkdown(array $c, string $pageUrl): string
    {
        $md = ["# {$c['name']}", '', '> '.Support::plain($c['overview']), '',
            "Canonical: {$pageUrl}  ", "Namespace: `{$c['fqcn']}` · File: `{$c['file']}` · Version {$c['version']} · {$c['since']}+"
            .($c['extensions'] !== [] ? ' · Extensions: '.implode(', ', $c['extensions']) : ''), '', $c['lead'], ''];

        foreach ($c['blocks'] as $block) {
            $md[] = match ($block['type']) {
                'heading' => "## {$block['text']}\n",
                'p' => $block['text']."\n",
                'list' => implode("\n", array_map(static fn (string $i): string => "- {$i}", $block['items']))."\n",
                'spec' => "```text\n{$block['text']}\n```\n",
                'code' => "```php\n{$block['text']}\n```\n",
                default => '',
            };
        }

        $md[] = "## Installation\n\n```php\nrequire_once __DIR__.'/lib/Utilities/{$c['file']}';\n\nuse {$c['fqcn']};\n```\n";

        if ($c['constants'] !== []) {
            $md[] = "## Constants\n\n| Constant | Type | Value | Description |\n| --- | --- | --- | --- |";
            foreach ($c['constants'] as $k) {
                $md[] = '| `'.$k['name'].'` | `'.($k['type'] ?: '-').'` | `'.str_replace('|', '\|', $k['value']).'` | '.str_replace('|', '\|', $k['desc']).' |';
            }
            $md[] = '';
        }
        if ($c['properties'] !== []) {
            $md[] = "## Properties\n";
            foreach ($c['properties'] as $p) {
                $md[] = "- `\${$p['name']}` (`{$p['type']}`".($p['readonly'] ? ', readonly' : '').')'.($p['desc'] !== '' ? ": {$p['desc']}" : '');
            }
            $md[] = '';
        }

        foreach (['methods' => 'Methods', 'functions' => 'Functions'] as $key => $title) {
            if ($c[$key] === []) {
                continue;
            }
            $md[] = "## {$title}\n";
            foreach ($c[$key] as $m) {
                $md[] = "### {$m['name']}()\n\n```php\n{$m['signature']}\n```\n";
                if ($m['summary'] !== '') {
                    $md[] = $m['summary']."\n";
                }
                foreach ($m['description'] as $p) {
                    $md[] = $p."\n";
                }
                if ($m['params'] !== []) {
                    $md[] = 'Parameters:';
                    foreach ($m['params'] as $p) {
                        $md[] = "- `\${$p['name']}` (`{$p['type']}`".($p['optional'] && $p['default'] !== null ? ", default `{$p['default']}`" : ($p['optional'] ? '' : ', required')).')'
                            .($p['desc'] !== '' ? ": {$p['desc']}" : '');
                    }
                    $md[] = '';
                }
                if ($m['return'] !== null && $m['return']['type'] !== '' && $m['return']['type'] !== 'void') {
                    $md[] = "Returns `{$m['return']['type']}`".($m['return']['desc'] !== '' ? ": {$m['return']['desc']}" : '.')."\n";
                }
                if ($m['throws'] !== []) {
                    $md[] = 'Throws:';
                    foreach ($m['throws'] as $t) {
                        $md[] = "- `{$t['type']}`".($t['desc'] !== '' ? ": {$t['desc']}" : '');
                    }
                    $md[] = '';
                }
            }
        }

        return implode("\n", $md)."\n";
    }
}
