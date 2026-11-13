<?php

declare(strict_types=1);

namespace FoundrySite;

/**
 * Build-time syntax highlighting. PHP is tokenized with PHP's own lexer (exact, no regex guessing);
 * other languages are escaped only. Output uses short CSS classes: t-k keyword, t-t type, t-s string,
 * t-n number/constant, t-v variable, t-c comment, t-f function, t-C class name.
 */
final class Highlighter
{
    private const KEYWORDS = [
        'T_ABSTRACT', 'T_ARRAY', 'T_AS', 'T_BREAK', 'T_CASE', 'T_CATCH', 'T_CLASS', 'T_CLONE', 'T_CONST',
        'T_CONTINUE', 'T_DECLARE', 'T_DEFAULT', 'T_DO', 'T_ECHO', 'T_ELSE', 'T_ELSEIF', 'T_EMPTY', 'T_ENUM',
        'T_EXIT', 'T_EXTENDS', 'T_FINAL', 'T_FINALLY', 'T_FN', 'T_FOR', 'T_FOREACH', 'T_FUNCTION', 'T_GLOBAL',
        'T_IF', 'T_IMPLEMENTS', 'T_INCLUDE', 'T_INCLUDE_ONCE', 'T_INSTANCEOF', 'T_INTERFACE', 'T_ISSET',
        'T_LIST', 'T_MATCH', 'T_NAMESPACE', 'T_NEW', 'T_PRINT', 'T_PRIVATE', 'T_PROTECTED', 'T_PUBLIC',
        'T_READONLY', 'T_REQUIRE', 'T_REQUIRE_ONCE', 'T_RETURN', 'T_STATIC', 'T_SWITCH', 'T_THROW', 'T_TRAIT',
        'T_TRY', 'T_UNSET', 'T_USE', 'T_VAR', 'T_WHILE', 'T_YIELD', 'T_YIELD_FROM', 'T_LOGICAL_AND',
        'T_LOGICAL_OR', 'T_OPEN_TAG', 'T_CLOSE_TAG', 'T_ATTRIBUTE',
    ];

    private const TYPES = [
        'string', 'int', 'float', 'bool', 'array', 'mixed', 'void', 'never', 'iterable', 'callable',
        'object', 'self', 'static', 'parent', 'null', 'true', 'false',
    ];

    public static function highlight(string $code, string $lang): string
    {
        $lang = strtolower($lang);

        return in_array($lang, ['php', ''], true) ? self::php($code) : Support::e($code);
    }

    public static function php(string $code): string
    {
        $prefixed = ! preg_match('/^\s*<\?php/', $code);
        $tokens = token_get_all($prefixed ? "<?php\n".$code : $code);
        if ($prefixed) {
            array_shift($tokens);
        }

        $out = '';
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_string($token)) {
                $out .= $token === '"' ? '<span class="t-s">"</span>' : Support::e($token);

                continue;
            }
            [$id, $text] = $token;
            $class = self::classify($id, $text, $tokens, $i);
            $out .= $class === null ? Support::e($text) : '<span class="'.$class.'">'.Support::e($text).'</span>';
        }

        return $out;
    }

    /** @param  array<int, array{0:int,1:string,2:int}|string>  $tokens */
    private static function classify(int $id, string $text, array $tokens, int $i): ?string
    {
        $name = token_name($id);

        return match (true) {
            $id === T_VARIABLE => 't-v',
            in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_START_HEREDOC, T_END_HEREDOC], true) => 't-s',
            in_array($id, [T_LNUMBER, T_DNUMBER], true) => 't-n',
            in_array($id, [T_COMMENT, T_DOC_COMMENT], true) => 't-c',
            in_array($id, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) => 't-C',
            $id === T_STRING => self::classifyString($text, $tokens, $i),
            in_array($name, self::KEYWORDS, true) => 't-k',
            default => null,
        };
    }

    /** @param  array<int, array{0:int,1:string,2:int}|string>  $tokens */
    private static function classifyString(string $text, array $tokens, int $i): ?string
    {
        if (in_array(strtolower($text), self::TYPES, true)) {
            return 't-t';
        }
        $next = $tokens[$i + 1] ?? null;
        if (is_array($next) && $next[0] === T_WHITESPACE) {
            $next = $tokens[$i + 2] ?? null;
        }
        if ($next === '(') {
            return 't-f';
        }
        if (preg_match('/^[A-Z][A-Z0-9]*(_[A-Z0-9]+)+$/', $text)) {
            return 't-n';
        }

        return ctype_upper($text[0]) ? 't-C' : null;
    }
}
