<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema\Introspection;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\UniqueConstraint;

interface UniqueConstraintProvider
{
    /**
     * @return list<UniqueConstraint>
     *
     * @throws Exception
     */
    public function getTableUniqueConstraints(string $databaseName, string $tableName): array;

    /**
     * @return array<string,list<UniqueConstraint>>
     *
     * @throws Exception
     */
    public function getDatabaseUniqueConstraints(string $databaseName): array;
}
