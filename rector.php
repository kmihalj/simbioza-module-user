<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;

// EN: Optional Menu/Theme service IDs must stay strings in both minimal and all-module QA environments.
//
// HR: Opcionalni Menu/Theme servisni ID-jevi ostaju stringovi u minimalnom i all-module QA okruženju.

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/heartphrame-manifest.php',
    ])
    ->withPhpSets(php82: true)
    ->withSkip([
        StringClassNameToClassConstantRector::class => [
            __DIR__ . '/src/Service/SimbiozaUserMenuIntegration.php',
            __DIR__ . '/src/Service/UserThemePolicy.php',
        ],
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        instanceOf: true,
        earlyReturn: true,
        rectorPreset: true,
        phpunitCodeQuality: true,
    );
