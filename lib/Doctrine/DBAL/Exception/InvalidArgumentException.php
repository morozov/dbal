<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Exception;

use Doctrine\DBAL\DBALException;

/**
 * Exception to be thrown when invalid arguments are passed to any DBAL API
 */
class InvalidArgumentException extends DBALException
{
    public static function fromEmptyCriteria() : self
    {
        return new self('Empty criteria was used, expected non-empty criteria');
    }
}
