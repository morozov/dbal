<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema\Introspection;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;

interface ForeignKeyProvider
{
    /**
     * @return list<ForeignKeyConstraint>
     *
     * @throws Exception
     */
    public function getTableForeignKeys(string $databaseName, string $tableName): array;

    /**
     * @return array<string,list<ForeignKeyConstraint>>
     *
     * @throws Exception
     */
    public function getDatabaseForeignKeys(string $databaseName): array;
}
