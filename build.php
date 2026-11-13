<?php

declare(strict_types=1);

/*
 * Foundry documentation site generator.
 *
 *   php build.php                                  -> public/ for https://foundry.insightdevhub.ro
 *   php build.php --base-url=http://localhost:8080 -> local preview (php -S localhost:8080 -t public)
 *
 * Reads ../foundry (README catalog + src/*.php via Reflection) and content/guides/*.md,
 * then writes a fully static site into public/. Requires PHP 8.3+ with the zip extension.
 */

if (PHP_VERSION_ID < 80300) {
    fwrite(STDERR, "PHP 8.3+ is required (found ".PHP_VERSION.").\n");
    exit(1);
}
foreach (['zip', 'mbstring', 'tokenizer'] as $ext) {
    if (! extension_loaded($ext)) {
        fwrite(STDERR, "Missing PHP extension: {$ext}\n");
        exit(1);
    }
}

foreach (['Support', 'DocBlock', 'Highlighter', 'Linker', 'Library', 'Renderer', 'Markdown', 'Site'] as $file) {
    require __DIR__."/build/{$file}.php";
}

/** Escapes a value for HTML output in templates. */
function esc(string|int|float|null $value): string
{
    return FoundrySite\Support::e($value);
}

exit(FoundrySite\Site::main($argv));
