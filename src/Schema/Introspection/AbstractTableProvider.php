<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema\Introspection;

use Doctrine\DBAL\Schema\Table;

abstract class AbstractTableProvider implements TableProvider
{
    public function __construct(
        private ColumnProvider $columnProvider,
        private IndexProvider $indexProvider,
        private UniqueConstraintProvider $uniqueConstraintProvider,
        private ForeignKeyProvider $foreignKeyProvider,
    ) {
    }

    public function getTable(string $databaseName, string $tableName): ?Table
    {
        $tableOptions = $this->getDatabaseTableOptions($databaseName, $tableName);

        return new Table(
            $tableName,
            $this->columnProvider->getTableColumns($databaseName, $tableName),
            $this->indexProvider->getTableIndexes($databaseName, $tableName),
            $this->uniqueConstraintProvider->getTableUniqueConstraints($databaseName, $tableName),
            $this->foreignKeyProvider->getTableForeignKeys($databaseName, $tableName),
            $tableOptions[$tableName] ?? [],
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getDatabaseTables(string $databaseName): array
    {
        $indexes           = $this->indexProvider->getDatabaseIndexes($databaseName);
        $uniqueConstraints = $this->uniqueConstraintProvider->getDatabaseUniqueConstraints($databaseName);
        $foreignKeys       = $this->foreignKeyProvider->getDatabaseForeignKeys($databaseName);
        $options           = $this->getDatabaseTableOptions($databaseName);

        $tables = [];

        foreach ($this->columnProvider->getDatabaseColumns($databaseName) as $tableName => $columns) {
            $tables[] = new Table(
                $tableName,
                $columns,
                $indexes[$tableName] ?? [],
                $uniqueConstraints[$tableName] ?? [],
                $foreignKeys[$tableName] ?? [],
                $options[$tableName] ?? [],
            );
        }

        return $tables;
    }

    /**
     * Returns table options for the tables in the specified database. If the table name is specified, narrows down
     * the selection to this table.
     *
     * @return array<string,array<string,mixed>>
     */
    abstract protected function getDatabaseTableOptions(string $databaseName, ?string $tableName = null): array;
}
