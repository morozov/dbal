<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Platforms\MySQL;

use Doctrine\DBAL\Schema\Introspection\AbstractTableProvider;

use function explode;
use function implode;

class TableProvider extends AbstractTableProvider
{
    /**
     * {@inheritDoc}
     */
    protected function getDatabaseTableOptions(string $databaseName, ?string $tableName = null): array
    {
        $sql = <<<'SQL'
    SELECT t.TABLE_NAME,
           t.ENGINE,
           t.AUTO_INCREMENT,
           t.TABLE_COMMENT,
           t.CREATE_OPTIONS,
           t.TABLE_COLLATION,
           ccsa.CHARACTER_SET_NAME
      FROM information_schema.TABLES t
        INNER JOIN information_schema.COLLATION_CHARACTER_SET_APPLICABILITY ccsa
            ON ccsa.COLLATION_NAME = t.TABLE_COLLATION
SQL;

        $conditions = ['t.TABLE_SCHEMA = ?'];
        $params     = [$databaseName];

        if ($tableName !== null) {
            $conditions[] = 't.TABLE_NAME = ?';
            $params[]     = $tableName;
        }

        $conditions[] = "t.TABLE_TYPE = 'BASE TABLE'";

        $sql .= ' WHERE ' . implode(' AND ', $conditions);

        $metadata = $this->_conn->executeQuery($sql, $params)
            ->fetchAllAssociativeIndexed();

        $tableOptions = [];
        foreach ($metadata as $table => $data) {
            $tableOptions[(string) $table] = [
                'engine'         => $data['ENGINE'],
                'collation'      => $data['TABLE_COLLATION'],
                'charset'        => $data['CHARACTER_SET_NAME'],
                'autoincrement'  => $data['AUTO_INCREMENT'],
                'comment'        => $data['TABLE_COMMENT'],
                'create_options' => $this->parseCreateOptions($data['CREATE_OPTIONS']),
            ];
        }

        return $tableOptions;
    }

    /** @return string[]|true[] */
    private function parseCreateOptions(?string $string): array
    {
        $options = [];

        if ($string === null || $string === '') {
            return $options;
        }

        foreach (explode(' ', $string) as $pair) {
            $parts = explode('=', $pair, 2);

            $options[$parts[0]] = $parts[1] ?? true;
        }

        return $options;
    }
}
