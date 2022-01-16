<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema\Introspection;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\Index;

interface IndexProvider
{
    /**
     * @return list<Index>
     *
     * @throws Exception
     */
    public function getTableIndexes(string $databaseName, string $tableName): array;

    /**
     * @return array<string,list<Index>>
     *
     * @throws Exception
     */
    public function getDatabaseIndexes(string $databaseName): array;
}
