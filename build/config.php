<?php

declare(strict_types=1);

/*
 * Site configuration. Paths are absolute; everything else is plain data.
 * The library itself (../foundry) is only READ at build time and never modified.
 */

$root = dirname(__DIR__);

return [
    'name' => 'Foundry',
    'tagline' => 'Standalone, zero-dependency PHP utility classes',
    'base_url' => 'https://foundry.insightdevhub.ro',
    'language' => 'en',
    'license' => 'Proprietary',

    'source_dir' => dirname($root).'/foundry/src',
    'readme' => dirname($root).'/foundry/README.md',
    'content_dir' => $root.'/content',
    'templates_dir' => $root.'/templates',
    'assets_dir' => $root.'/assets',
    'output_dir' => $root.'/public',

    'download' => [
        'file' => 'foundry-php83.zip',
        'folder' => 'foundry',          // top-level folder inside the archive
    ],

    // Sidebar groups for hand-written pages (content/guides/<slug>.md), in display order.
    'guides' => [
        'Getting started' => ['installation', 'usage', 'bootstrap', 'design-principles', 'php-versions'],
        'Guides' => ['form-handling', 'imports-exports', 'databases'],
    ],

    // URL slugs that the automatic kebab-case conversion would get wrong.
    'slug_overrides' => [
        'MySQL' => 'mysql',
        'SQLite' => 'sqlite',
    ],
];
