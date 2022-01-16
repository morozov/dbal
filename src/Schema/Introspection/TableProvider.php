<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema\Introspection;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\Table;

interface TableProvider
{
    /** @throws Exception */
    public function getTable(string $databaseName, string $tableName): ?Table;

    /**
     * @return list<Table>
     *
     * @throws Exception
     */
    public function getDatabaseTables(string $databaseName): array;
}
