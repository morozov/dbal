<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Platforms\MySQL;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Introspection\AbstractIndexProvider;

use function implode;
use function strtolower;

class IndexProvider extends AbstractIndexProvider
{
    public function __construct(private Connection $connection)
    {
    }

    protected function selectIndexColumns(string $databaseName, ?string $tableName = null): Result
    {
        $sql = 'SELECT';

        if ($tableName === null) {
            $sql .= ' TABLE_NAME,';
        }

        $sql .= <<<'SQL'
        NON_UNIQUE,
        INDEX_NAME,
        COLUMN_NAME,
        SUB_PART,
        INDEX_TYPE
FROM information_schema.STATISTICS
SQL;

        $conditions = ['TABLE_SCHEMA = ?'];
        $params     = [$databaseName];

        if ($tableName !== null) {
            $conditions[] = 'TABLE_NAME = ?';
            $params[]     = $tableName;
        }

        $sql .= ' WHERE ' . implode(' AND ', $conditions)
            . ' ORDER BY SEQ_IN_INDEX';

        return $this->connection->executeQuery($sql, $params);
    }

    /**
     * {@inheritDoc}
     */
    protected function createIndex(array $row): Index
    {
        $indexName = $keyName = $row['key_name'];
        if ($row['primary']) {
            $keyName = 'primary';
        }

        $keyName = strtolower($keyName);

        if (! isset($result[$keyName])) {
            $options = [
                'lengths' => [],
            ];

            if (isset($row['where'])) {
                $options['where'] = $row['where'];
            }

            $result[$keyName] = [
                'name' => $indexName,
                'columns' => [],
                'unique' => ! $row['non_unique'],
                'primary' => $row['primary'],
                'flags' => $row['flags'] ?? [],
                'options' => $options,
            ];
        }

        $result[$keyName]['columns'][] = $row['column_name'];

        $result[$keyName]['options']['lengths'][] = $row['length'] ?? null;

        return new Index(
            $data['name'],
            $data['columns'],
            $data['unique'],
            $data['primary'],
            $data['flags'],
            $data['options'],
        );
    }
}
