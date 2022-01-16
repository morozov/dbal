<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema\Introspection;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Schema\Column;

use function preg_match;
use function str_replace;

abstract class AbstractColumnProvider implements ColumnProvider
{
    public function __construct(protected AbstractPlatform $platform)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function getTableColumns(string $databaseName, string $tableName): array
    {
        return $this->createColumns(
            $this->selectColumns($databaseName, $tableName)
                ->fetchAllAssociative(),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getDatabaseColumns(string $databaseName): array
    {
        /** @var array<string,list<array<string,mixed>>> $data */
        $data = $this->selectColumns($databaseName)
            ->fetchAllAssociativeGrouped();

        $columns = [];

        foreach ($data as $tableName => $rows) {
            $columns[$tableName] = $this->createColumns($rows);
        }

        return $columns;
    }

    /**
     * Selects column definitions of the tables in the specified database. If the table name is specified, narrows down
     * the selection to this table.
     *
     * @throws Exception
     */
    abstract protected function selectColumns(string $databaseName, ?string $tableName = null): Result;

    /**
     * @param list<array<string,mixed>> $rows
     *
     * @return list<Column>
     *
     * @throws Exception
     */
    protected function createColumns(array $rows): array
    {
        $columns = [];
        foreach ($rows as $row) {
            $columns[] = $this->createColumn($row);
        }

        return $columns;
    }

    /**
     * @param array<string,mixed> $row
     *
     * @throws Exception
     */
    abstract protected function createColumn(array $row): Column;

    /**
     * Given a table comment this method tries to extract a typehint for Doctrine Type, or returns
     * the type given as default.
     *
     * @internal This method should be only used from within the AbstractSchemaManager class hierarchy.
     */
    final protected function extractDoctrineTypeFromComment(?string $comment, string $currentType): string
    {
        if ($comment !== null && preg_match('(\(DC2Type:(((?!\)).)+)\))', $comment, $match) === 1) {
            return $match[1];
        }

        return $currentType;
    }

    /** @internal This method should be only used from within the AbstractSchemaManager class hierarchy. */
    final protected function removeDoctrineTypeFromComment(?string $comment, ?string $type): ?string
    {
        if ($comment === null) {
            return null;
        }

        return str_replace('(DC2Type:' . $type . ')', '', $comment);
    }
}
