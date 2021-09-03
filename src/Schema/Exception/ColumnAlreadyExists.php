<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema\Exception;

use Doctrine\DBAL\Schema\Name;
use Doctrine\DBAL\Schema\SchemaException;

use function sprintf;

/**
 * @psalm-immutable
 */
final class ColumnAlreadyExists extends SchemaException
{
    public static function new(Name $tableName, Name $columnName): self
    {
        return new self(
            sprintf(
                'The column "%s" on table "%s" already exists.',
                $columnName->toString(),
                $tableName->toString()
            ),
            self::COLUMN_ALREADY_EXISTS
        );
    }
}
