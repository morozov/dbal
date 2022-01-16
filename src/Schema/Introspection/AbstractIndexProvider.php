<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema\Introspection;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Schema\Index;

abstract class AbstractIndexProvider implements IndexProvider
{
    /**
     * {@inheritDoc}
     */
    public function getTableIndexes(string $databaseName, string $tableName): array
    {
        return $this->createIndexes(
            $this->selectIndexColumns($databaseName, $tableName)
                ->fetchAllAssociative(),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getDatabaseIndexes(string $databaseName): array
    {
        /** @var array<string,list<array<string,mixed>>> $data */
        $data = $this->selectIndexColumns($databaseName)
            ->fetchAllAssociativeGrouped();

        $indexes = [];

        foreach ($data as $tableName => $rows) {
            $indexes[$tableName] = $this->createIndexes($rows);
        }

        return $indexes;
    }

    /**
     * Selects index definitions of the tables in the specified database. If the table name is specified, narrows down
     * the selection to this table.
     *
     * @throws Exception
     */
    abstract protected function selectIndexColumns(string $databaseName, ?string $tableName = null): Result;

    /**
     * @param list<array<string,mixed>> $rows
     *
     * @return list<Index>
     *
     * @throws Exception
     */
    protected function createIndexes(array $rows): array
    {
        $indexes = [];
        foreach ($rows as $row) {
            $indexes[] = $this->createIndex($row);
        }

        return $indexes;
    }

    /**
     * @param array<string,mixed> $row
     *
     * @throws Exception
     */
    abstract protected function createIndex(array $row): Index;
}
