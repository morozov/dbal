<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Platforms\MySQL;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Introspection\AbstractColumnProvider;
use Doctrine\DBAL\Types\Type;

use function array_change_key_case;
use function assert;
use function implode;
use function is_string;
use function preg_match;
use function strpos;
use function strtok;
use function strtolower;

use const CASE_LOWER;

class ColumnProvider extends AbstractColumnProvider
{
    /** @throws Exception */
    public function __construct(private Connection $connection)
    {
        parent::__construct($connection->getDatabasePlatform());
    }

    /**
     * Selects column definitions of the tables in the specified database. If the table name is specified, narrows down
     * the selection to this table.
     *
     * @throws Exception
     */
    protected function selectColumns(string $databaseName, ?string $tableName = null): Result
    {
        $sql = 'SELECT';

        if ($tableName === null) {
            $sql .= ' TABLE_NAME,';
        }

        $sql .= <<<'SQL'
       COLUMN_NAME,
       COLUMN_TYPE,
       IS_NULLABLE,
       COLUMN_DEFAULT,
       EXTRA,
       COLUMN_COMMENT,
       CHARACTER_SET_NAME,
       COLLATION_NAME
FROM information_schema.COLUMNS
SQL;

        $conditions = ['TABLE_SCHEMA = ?'];
        $params     = [$databaseName];
        $order      = [];

        if ($tableName !== null) {
            $conditions[] = 'TABLE_NAME = ?';
            $params[]     = $tableName;
        } else {
            $order[] = 'TABLE_NAME';
        }

        $order[] = 'ORDINAL_POSITION';

        $sql .= ' WHERE ' . implode(' AND ', $conditions) . ' ORDER BY ' . implode(', ', $order);

        return $this->connection->executeQuery($sql, $params);
    }

    /**
     * {@inheritDoc}
     */
    protected function createColumn(array $row): Column
    {
        $row = array_change_key_case($row, CASE_LOWER);

        $dbType = strtolower($row['column_type']);
        $dbType = strtok($dbType, '(), ');
        assert(is_string($dbType));

        $length = $row['length'] ?? strtok('(), ');

        $fixed = null;

        $scale     = null;
        $precision = null;

        $type = $this->platform->getDoctrineTypeMapping($dbType);

        // In cases where not connected to a database DESCRIBE $table does not return 'COLUMN_COMMENT'
        if (isset($row['column_comment'])) {
            $type                  = $this->extractDoctrineTypeFromComment($row['column_comment'], $type);
            $row['column_comment'] = $this->removeDoctrineTypeFromComment($row['column_comment'], $type);
        }

        switch ($dbType) {
            case 'char':
            case 'binary':
                $fixed = true;
                break;

            case 'float':
            case 'double':
            case 'real':
            case 'numeric':
            case 'decimal':
                if (
                    preg_match(
                        '([A-Za-z]+\(([0-9]+),([0-9]+)\))',
                        $row['column_type'],
                        $match,
                    ) === 1
                ) {
                    $precision = $match[1];
                    $scale     = $match[2];
                    $length    = null;
                }

                break;

            case 'tinytext':
                $length = AbstractMySQLPlatform::LENGTH_LIMIT_TINYTEXT;
                break;

            case 'text':
                $length = AbstractMySQLPlatform::LENGTH_LIMIT_TEXT;
                break;

            case 'mediumtext':
                $length = AbstractMySQLPlatform::LENGTH_LIMIT_MEDIUMTEXT;
                break;

            case 'tinyblob':
                $length = AbstractMySQLPlatform::LENGTH_LIMIT_TINYBLOB;
                break;

            case 'blob':
                $length = AbstractMySQLPlatform::LENGTH_LIMIT_BLOB;
                break;

            case 'mediumblob':
                $length = AbstractMySQLPlatform::LENGTH_LIMIT_MEDIUMBLOB;
                break;

            case 'tinyint':
            case 'smallint':
            case 'mediumint':
            case 'int':
            case 'integer':
            case 'bigint':
            case 'year':
                $length = null;
                break;
        }

        $options = [
            'length'        => $length !== null ? (int) $length : null,
            'unsigned'      => strpos($row['column_type'], 'unsigned') !== false,
            'fixed'         => (bool) $fixed,
            'default'       => $this->getDefault($row['column_default']),
            'notnull'       => $row['is_nullable'] !== 'YES',
            'scale'         => null,
            'precision'     => null,
            'autoincrement' => strpos($row['extra'], 'auto_increment') !== false,
            'comment'       => isset($row['column_comment']) && $row['column_comment'] !== ''
                ? $row['column_comment']
                : null,
        ];

        if ($scale !== null && $precision !== null) {
            $options['scale']     = (int) $scale;
            $options['precision'] = (int) $precision;
        }

        $column = new Column($row['column_name'], Type::getType($type), $options);

        if (isset($row['character_set_name'])) {
            $column->setPlatformOption('charset', $row['character_set_name']);
        }

        if (isset($row['collation_name'])) {
            $column->setPlatformOption('collation', $row['collation_name']);
        }

        return $column;
    }

    protected function getDefault(?string $default): ?string
    {
        return $default;
    }
}
