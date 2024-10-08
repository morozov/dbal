<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema\Exception;

use Doctrine\DBAL\Schema\Name;
use Doctrine\DBAL\Schema\SchemaException;

use function sprintf;

/**
 * @psalm-immutable
 */
final class ColumnDoesNotExist extends SchemaException
{
    public static function new(string $columnName, Name $tableName): self
    {
        return new self(
            sprintf('There is no column with name "%s" on table "%s".', $columnName, $tableName->getValue()),
            self::COLUMN_DOESNT_EXIST
        );
    }
}
