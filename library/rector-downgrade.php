<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

/**
 * Downgrade PHP 8.3 -> 7.4, rulat de tools/build.php peste copia din dist/php74/.
 * Nu rula direct pe src/: Rector modifica fisierele pe loc.
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/dist/php74',
    ])
    ->withDowngradeSets(php74: true);
