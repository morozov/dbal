<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Core\ValueObject\PhpVersion;
use Rector\Php81\Rector\FuncCall\NullToStrictStringFuncCallArgRector;
use Rector\Set\ValueObject\SetList;
use Rector\TypeDeclaration\Rector\ClassMethod\ReturnNeverTypeRector;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([__DIR__ . '/src', __DIR__ . '/tests']);
    $rectorConfig->sets([SetList::PHP_81]);
    $rectorConfig->skip([
        NullToStrictStringFuncCallArgRector::class,
        ReturnNeverTypeRector::class,
    ]);
    $rectorConfig->phpVersion(PhpVersion::PHP_81);
    $rectorConfig->phpstanConfig(__DIR__ . '/phpstan.neon.dist');
};
