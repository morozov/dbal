<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Query;

use Doctrine\DBAL\DBALException;
use function implode;

class QueryException extends DBALException
{
    /**
     * @param string[] $registeredAliases
     */
    public static function unknownAlias(string $alias, array $registeredAliases) : self
    {
        return new self("The given alias '" . $alias . "' is not part of " .
            'any FROM or JOIN clause table. The currently registered ' .
            'aliases are: ' . implode(', ', $registeredAliases) . '.');
    }

    /**
     * @param string[] $registeredAliases
     */
    public static function nonUniqueAlias(string $alias, array $registeredAliases) : self
    {
        return new self("The given alias '" . $alias . "' is not unique " .
            'in FROM and JOIN clause table. The currently registered ' .
            'aliases are: ' . implode(', ', $registeredAliases) . '.');
    }
}
