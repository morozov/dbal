<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema\Exception;

use Doctrine\DBAL\Schema\Name;
use Doctrine\DBAL\Schema\SchemaException;

use function sprintf;

/**
 * @psalm-immutable
 */
final class IndexAlreadyExists extends SchemaException
{
    public static function new(string $indexName, Name $tableName): self
    {
        return new self(
            sprintf('An index with name "%s" was already defined on table "%s".', $indexName, $tableName->getValue()),
            self::INDEX_ALREADY_EXISTS
        );
    }
}
