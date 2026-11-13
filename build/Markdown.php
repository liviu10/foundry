<?php

declare(strict_types=1);

namespace FoundrySite;

/**
 * Small Markdown renderer covering what the guides use: headings (with optional {#id}),
 * paragraphs, fenced code, lists, tables, callouts (> **Note:** ...), raw HTML lines,
 * and inline code / bold / italic / links. Inline code naming a class or method becomes a link.
 */
final class Markdown
{
    /** @var list<array{id: string, text: string, level: int}> */
    public array $headings = [];

    /** @var array<string, true> */
    private array $ids = [];

    public function __construct(private readonly Linker $linker, private readonly Renderer $renderer) {}

    public function render(string $markdown): string
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $markdown));
        $html = [];
        $n = count($lines);

        for ($i = 0; $i < $n;) {
            $line = $lines[$i];
            $trim = trim($line);

            if ($trim === '') {
                $i++;

                continue;
            }

            // fenced code
            if (preg_match('/^```\s*([\w-]*)\s*$/', $trim, $m)) {
                $code = [];
                for ($i++; $i < $n && ! preg_match('/^```\s*$/', trim($lines[$i])); $i++) {
                    $code[] = $lines[$i];
                }
                $i++;
                $html[] = $this->renderer->codeBlock(implode("\n", $code), $m[1] ?: 'text');

                continue;
            }

            // heading
            if (preg_match('/^(#{1,4})\s+(.+?)(?:\s+\{#([\w-]+)\})?\s*$/', $trim, $m)) {
                $level = strlen($m[1]);
                $html[] = $this->heading($level, $m[2], $m[3] ?? null);
                $i++;

                continue;
            }

            // table
            if (str_starts_with($trim, '|') && isset($lines[$i + 1]) && preg_match('/^\|?\s*:?-{3,}/', trim($lines[$i + 1]))) {
                $rows = [];
                for (; $i < $n && str_starts_with(trim($lines[$i]), '|'); $i++) {
                    $rows[] = trim($lines[$i]);
                }
                $html[] = $this->table($rows);

                continue;
            }

            // callout / blockquote
            if (str_starts_with($trim, '>')) {
                $quote = [];
                for (; $i < $n && str_starts_with(trim($lines[$i]), '>'); $i++) {
                    $quote[] = (string) preg_replace('/^>\s?/', '', trim($lines[$i]));
                }
                $inner = implode("\n", $quote);
                $type = preg_match('/^\*\*(Note|Tip|Warning|Important):?\*\*:?/i', $inner, $t) ? strtolower($t[1]) : 'note';
                $sub = new self($this->linker, $this->renderer);
                $html[] = '<div class="callout callout-'.$type.'">'.$sub->render($inner).'</div>';

                continue;
            }

            // list
            if (preg_match('/^(\s*)([-*]|\d+\.)\s+/', $line, $m)) {
                $ordered = ctype_digit($m[2][0]);
                $items = [];
                for (; $i < $n; $i++) {
                    $l = $lines[$i];
                    if (preg_match('/^\s*(?:[-*]|\d+\.)\s+(.*)$/', $l, $item)) {
                        $items[] = $item[1];
                    } elseif ($items !== [] && trim($l) !== '' && preg_match('/^\s{2,}\S/', $l)) {
                        $items[array_key_last($items)] .= ' '.trim($l);
                    } else {
                        break;
                    }
                }
                $tag = $ordered ? 'ol' : 'ul';
                $html[] = "<{$tag}>".implode('', array_map(fn (string $s): string => '<li>'.$this->inline($s).'</li>', $items))."</{$tag}>";

                continue;
            }

            // raw HTML block
            if (str_starts_with($trim, '<')) {
                $raw = [];
                for (; $i < $n && trim($lines[$i]) !== ''; $i++) {
                    $raw[] = $lines[$i];
                }
                $html[] = implode("\n", $raw);

                continue;
            }

            // paragraph
            $para = [];
            for (; $i < $n; $i++) {
                $t = trim($lines[$i]);
                if ($t === '' || preg_match('/^(#{1,4}\s|```|>|\|)/', $t) || preg_match('/^([-*]|\d+\.)\s+/', $t)) {
                    break;
                }
                $para[] = $t;
            }
            $html[] = '<p>'.$this->inline(implode(' ', $para)).'</p>';
        }

        return implode("\n", $html);
    }

    public function inline(string $text, bool $links = true): string
    {
        $pattern = '/`([^`]+)`|\[([^\]]+)\]\(([^)\s]+)\)|\*\*(.+?)\*\*|(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/';
        if (! preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return Support::e($text);
        }

        $html = '';
        $pos = 0;
        foreach ($matches as $m) {
            [$whole, $offset] = $m[0];
            $html .= Support::e(substr($text, $pos, $offset - $pos));
            $pos = $offset + strlen($whole);

            if (($m[1][1] ?? -1) >= 0 && $m[1][0] !== '') {
                $html .= $links ? $this->linker->inlineCode($m[1][0]) : "<code>".Support::e($m[1][0])."</code>";
            } elseif (($m[2][1] ?? -1) >= 0 && $m[2][0] !== '') {
                $href = $m[3][0];
                $external = preg_match('#^https?://#', $href) === 1;
                $html .= '<a href="'.Support::e($href).'"'.($external ? ' rel="noopener" target="_blank"' : '').'>'.$this->inline($m[2][0], false).'</a>';
            } elseif (($m[4][1] ?? -1) >= 0 && $m[4][0] !== '') {
                $html .= '<strong>'.$this->inline($m[4][0], $links).'</strong>';
            } else {
                $html .= '<em>'.$this->inline($m[5][0], $links).'</em>';
            }
        }

        return $html.Support::e(substr($text, $pos));
    }

    private function heading(int $level, string $text, ?string $id): string
    {
        $plain = trim(strip_tags($this->inline($text)));
        $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $id = $this->uniqueId($id ?? Support::slug($plain));
        if ($level === 2 || $level === 3) {
            $this->headings[] = ['id' => $id, 'text' => $plain, 'level' => $level];
        }

        return $this->renderer->heading($level, $id, $this->inline($text, false));
    }

    /** @param  list<string>  $rows */
    private function table(array $rows): string
    {
        // split on pipes that are not inside `inline code`
        $cells = static fn (string $row): array => array_map('trim', preg_split('/\|(?=(?:[^`]*`[^`]*`)*[^`]*$)/', trim(trim($row), '|')) ?: []);
        $head = $cells($rows[0]);
        $html = '<div class="table-wrap"><table><thead><tr>';
        foreach ($head as $cell) {
            $html .= '<th scope="col">'.$this->inline($cell).'</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach (array_slice($rows, 2) as $row) {
            $html .= '<tr>'.implode('', array_map(fn (string $c): string => '<td>'.$this->inline($c).'</td>', $cells($row))).'</tr>';
        }

        return $html.'</tbody></table></div>';
    }

    private function uniqueId(string $id): string
    {
        $base = $id;
        for ($k = 2; isset($this->ids[$id]); $k++) {
            $id = $base.'-'.$k;
        }
        $this->ids[$id] = true;

        return $id;
    }
}
