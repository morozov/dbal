<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Platforms\Exception;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\Name;

use function sprintf;

/**
 * @psalm-immutable
 */
final class NoColumnsSpecifiedForTable extends Exception implements PlatformException
{
    public static function new(Name $tableName): self
    {
        return new self(sprintf('No columns specified for table "%s".', $tableName->toString()));
    }
}
