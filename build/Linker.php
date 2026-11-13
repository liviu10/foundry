<?php

declare(strict_types=1);

namespace FoundrySite;

/**
 * Turns plain docblock prose into safe HTML: escapes it, marks code-like fragments
 * (Class::method(), $variables, func(), 'literals', CONSTANTS) as <code>, and links
 * references to library classes and methods (internal linking).
 */
final class Linker
{
    /** @var array<string, string> class name => URL */
    private array $classes = [];

    /** @var array<string, array<string, true>> class => [method => true] */
    private array $methods = [];

    /** @var list<string> unresolved Class::method references found in content */
    public array $unresolved = [];

    public function addClass(string $name, string $url, array $methodNames): void
    {
        $this->classes[$name] = $url;
        $this->methods[$name] = array_fill_keys(array_map('strtolower', $methodNames), true);
    }

    public function classUrl(string $name): ?string
    {
        return $this->classes[$name] ?? null;
    }

    public function methodUrl(string $class, string $method): ?string
    {
        if (! isset($this->methods[$class][strtolower($method)])) {
            return null;
        }

        return $this->classes[$class].'#method-'.strtolower($method);
    }

    /** Checks a Class::method reference written in hand-made content; remembers the broken ones. */
    public function verify(string $class, string $method): void
    {
        if (isset($this->classes[$class]) && $this->methodUrl($class, $method) === null) {
            $this->unresolved[] = "{$class}::{$method}()";
        }
    }

    /** Prose from docblocks -> HTML. */
    public function prose(string $text): string
    {
        $pattern = '/'
            .'(?<cm>\b(?<c1>[A-Z][A-Za-z0-9]+)::(?<m1>[A-Za-z_]\w*)\((?<a1>[^()\n]{0,40})\))'
            .'|(?<cc>\b[A-Z][A-Za-z0-9]+::[A-Z_][A-Z0-9_]+\b)'
            .'|(?<see>\b[Ss]ee (?<c2>[A-Z][A-Za-z0-9]+)\b)'
            .'|(?<var>\$[A-Za-z_]\w*)'
            .'|(?<fn>\b[a-z_][A-Za-z0-9_]*\([^()\n]{0,40}\))'
            .'|(?<lit>(?<![A-Za-z])\'[^\'\n]{1,40}\'(?![A-Za-z]))'
            .'|(?<const>\b[A-Z][A-Z0-9]*(?:_[A-Z0-9]+)+\b)'
            .'/';

        if (! preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL)) {
            return Support::e($text);
        }

        $html = '';
        $pos = 0;
        foreach ($matches as $m) {
            [$whole, $offset] = $m[0];
            $html .= Support::e(substr($text, $pos, $offset - $pos));
            $pos = $offset + strlen($whole);

            if ($m['cm'][0] !== null) {
                $url = $this->methodUrl($m['c1'][0], $m['m1'][0]);
                $html .= $this->code($whole, $url);
            } elseif ($m['see'][0] !== null) {
                $class = $m['c2'][0];
                $url = $this->classUrl($class);
                $html .= $url === null
                    ? Support::e($whole)
                    : Support::e(substr($whole, 0, 4)).$this->code($class, $url);
            } else {
                $html .= $this->code($whole, null);
            }
        }

        return $html.Support::e(substr($text, $pos));
    }

    /** Inline code written explicitly (Markdown `...`): link it when it names a class or a method. */
    public function inlineCode(string $code): string
    {
        if (preg_match('/^([A-Z][A-Za-z0-9]+)::([A-Za-z_]\w*)\(.*\)$/s', $code, $m)) {
            $this->verify($m[1], $m[2]);

            return $this->code($code, $this->methodUrl($m[1], $m[2]));
        }
        if (preg_match('/^(?:Foundry\\\\)?([A-Z][A-Za-z0-9]+)$/', $code, $m) && isset($this->classes[$m[1]])) {
            return $this->code($code, $this->classes[$m[1]]);
        }

        return $this->code($code, null);
    }

    private function code(string $text, ?string $url): string
    {
        $code = '<code>'.Support::e($text).'</code>';

        return $url === null ? $code : '<a class="code-link" href="'.Support::e($url).'">'.$code.'</a>';
    }
}
