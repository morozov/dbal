<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema\Exception;

use Doctrine\DBAL\Schema\Name;
use Doctrine\DBAL\Schema\SchemaException;

use function sprintf;

/**
 * @psalm-immutable
 */
final class ForeignKeyDoesNotExist extends SchemaException
{
    public static function new(string $foreignKeyName, Name $tableName): self
    {
        return new self(
            sprintf(
                'There exists no foreign key with the name "%s" on table "%s".',
                $foreignKeyName,
                $tableName->getValue()
            ),
            self::FOREIGNKEY_DOESNT_EXIST
        );
    }
}
