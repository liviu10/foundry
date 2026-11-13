<?php

declare(strict_types=1);

namespace FoundrySite;

/**
 * Minimal PHPDoc parser: free text (with indentation preserved) plus tags.
 */
final class DocBlock
{
    /** @var list<string> Text lines before the first tag, indentation preserved. */
    public array $text = [];

    /** @var array<string, array{type: string, desc: string}> */
    public array $params = [];

    /** @var array{type: string, desc: string}|null */
    public ?array $return = null;

    /** @var list<array{type: string, desc: string}> */
    public array $throws = [];

    /** @var array<string, list<string>> All tags, raw bodies. */
    public array $tags = [];

    public static function parse(string|false $comment): self
    {
        $doc = new self;
        if ($comment === false || $comment === '') {
            return $doc;
        }

        $body = preg_replace(['#^/\*\*#', '#\*/$#'], '', trim($comment)) ?? '';
        $lines = [];
        foreach (explode("\n", str_replace("\r\n", "\n", $body)) as $line) {
            $lines[] = rtrim((string) preg_replace('/^\s*\* ?/', '', $line));
        }
        // single-line form: /** @var int Foo */
        if (count($lines) === 1) {
            $lines[0] = trim($lines[0]);
        }

        $currentTag = null;
        foreach ($lines as $line) {
            if (preg_match('/^@([A-Za-z][\w-]*)\s*(.*)$/', $line, $m)) {
                $doc->tags[$m[1]][] = $m[2];
                $currentTag = [$m[1], array_key_last($doc->tags[$m[1]])];

                continue;
            }
            if ($currentTag !== null) {
                if (trim($line) === '') {
                    continue;
                }
                [$name, $index] = $currentTag;
                $doc->tags[$name][$index] .= "\n".trim($line);

                continue;
            }
            $doc->text[] = $line;
        }

        while ($doc->text !== [] && trim($doc->text[array_key_last($doc->text)]) === '') {
            array_pop($doc->text);
        }
        while ($doc->text !== [] && trim($doc->text[0]) === '') {
            array_shift($doc->text);
        }

        foreach ($doc->tags['param'] ?? [] as $raw) {
            [$type, $rest] = self::splitType($raw);
            if (preg_match('/^(?:&)?(?:\.\.\.)?\$(\w+)\s*(.*)$/s', $rest, $m)) {
                $doc->params[$m[1]] = ['type' => $type, 'desc' => self::flatten($m[2])];
            }
        }
        if (isset($doc->tags['return'][0])) {
            [$type, $rest] = self::splitType($doc->tags['return'][0]);
            $doc->return = ['type' => $type, 'desc' => self::flatten($rest)];
        }
        foreach ($doc->tags['throws'] ?? [] as $raw) {
            [$type, $rest] = self::splitType($raw);
            $doc->throws[] = ['type' => ltrim($type, '\\'), 'desc' => self::flatten($rest)];
        }

        return $doc;
    }

    /** Text paragraphs joined; first paragraph is the summary. */
    public function summary(): string
    {
        return $this->paragraphs()[0] ?? '';
    }

    /** @return list<string> */
    public function paragraphs(): array
    {
        $paragraphs = [];
        $buffer = [];
        foreach ($this->text as $line) {
            if (trim($line) === '') {
                if ($buffer !== []) {
                    $paragraphs[] = implode(' ', $buffer);
                    $buffer = [];
                }

                continue;
            }
            $buffer[] = trim($line);
        }
        if ($buffer !== []) {
            $paragraphs[] = implode(' ', $buffer);
        }

        return $paragraphs;
    }

    /**
     * Splits a leading type expression (which may contain spaces inside <>, {}, ()) from the rest.
     *
     * @return array{0: string, 1: string}
     */
    public static function splitType(string $raw): array
    {
        $raw = ltrim($raw);
        $depth = 0;
        $len = strlen($raw);
        for ($i = 0; $i < $len; $i++) {
            $ch = $raw[$i];
            if (str_contains('<({[', $ch)) {
                $depth++;
            } elseif (str_contains('>)}]', $ch)) {
                $depth = max(0, $depth - 1);
            } elseif ($depth === 0 && ctype_space($ch)) {
                // "array<int, string>" style: a comma right before the space keeps us inside the type
                if ($i > 0 && $raw[$i - 1] === ',') {
                    continue;
                }

                return [substr($raw, 0, $i), ltrim(substr($raw, $i))];
            }
        }

        return [$raw, ''];
    }

    private static function flatten(string $text): string
    {
        return trim((string) preg_replace('/\s*\n\s*/', ' ', $text));
    }
}
