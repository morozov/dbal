<?php

declare(strict_types=1);

namespace Doctrine\DBAL;

use function sprintf;

/**
 * Doctrine\DBAL\ConnectionException
 */
class SQLParserUtilsException extends DBALException
{
    public static function missingParam(string $paramName) : self
    {
        return new self(sprintf('Value for :%1$s not found in params array. Params array key should be "%1$s"', $paramName));
    }

    public static function missingType(string $typeName) : self
    {
        return new self(sprintf('Value for :%1$s not found in types array. Types array key should be "%1$s"', $typeName));
    }
}
