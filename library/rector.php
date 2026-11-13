<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\TypeDeclaration\Rector\ClassMethod\ReturnUnionTypeRector;

/**
 * Calitatea codului sursa (PHP 8.3): `composer refactor`.
 * Downgrade-ul spre PHP 7.4 este in rector-downgrade.php.
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withPhpSets(php83: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        earlyReturn: true,
        phpunitCodeQuality: true,
    )
    ->withSkip([
        // Fara tip nativ GdImage: pe PHP 7.4 functiile gd returneaza resurse.
        ReturnUnionTypeRector::class => [__DIR__.'/src/Image.php'],
    ]);
