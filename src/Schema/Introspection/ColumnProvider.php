<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema\Introspection;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\Column;

interface ColumnProvider
{
    /**
     * @return list<Column>
     *
     * @throws Exception
     */
    public function getTableColumns(string $databaseName, string $tableName): array;

    /**
     * @return array<string,list<Column>>
     *
     * @throws Exception
     */
    public function getDatabaseColumns(string $databaseName): array;
}
