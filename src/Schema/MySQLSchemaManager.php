<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQL;
use Doctrine\DBAL\Platforms\MySQL\CharsetMetadataProvider\CachingCharsetMetadataProvider;
use Doctrine\DBAL\Platforms\MySQL\CharsetMetadataProvider\ConnectionCharsetMetadataProvider;
use Doctrine\DBAL\Platforms\MySQL\CollationMetadataProvider\CachingCollationMetadataProvider;
use Doctrine\DBAL\Platforms\MySQL\CollationMetadataProvider\ConnectionCollationMetadataProvider;
use Doctrine\DBAL\Platforms\MySQL\ColumnProvider;
use Doctrine\DBAL\Platforms\MySQL\DefaultTableOptions;
use Doctrine\DBAL\Platforms\MySQL\ForeignKeyProvider;
use Doctrine\DBAL\Platforms\MySQL\IndexProvider;
use Doctrine\DBAL\Platforms\MySQL\TableProvider;
use Doctrine\DBAL\Platforms\MySQL\UniqueConstraintProvider;
use Doctrine\DBAL\Result;

use function array_change_key_case;
use function assert;
use function explode;
use function implode;
use function str_contains;

use const CASE_LOWER;

/**
 * Schema manager for the MySQL RDBMS.
 *
 * @extends AbstractSchemaManager<AbstractMySQLPlatform>
 */
class MySQLSchemaManager extends AbstractSchemaManager
{
    /** @throws Exception */
    public function __construct(Connection $connection, AbstractPlatform $platform)
    {
        $columnProvider           = new ColumnProvider($connection);
        $indexProvider            = new IndexProvider($connection);
        $uniqueConstraintProvider = new UniqueConstraintProvider();
        $foreignKeyProvider       = new ForeignKeyProvider();

        $tableProvider = new TableProvider(
            $columnProvider,
            $indexProvider,
            $uniqueConstraintProvider,
            $foreignKeyProvider,
        );

        parent::__construct($connection, $platform, $tableProvider);
    }

    private ?DefaultTableOptions $defaultTableOptions = null;

    /**
     * {@inheritdoc}
     */
    protected function _getPortableTableDefinition(array $table): string
    {
        return $table['TABLE_NAME'];
    }

    /**
     * {@inheritdoc}
     */
    protected function _getPortableViewDefinition(array $view): View
    {
        return new View($view['TABLE_NAME'], $view['VIEW_DEFINITION']);
    }

    /**
     * {@inheritdoc}
     */
    protected function _getPortableTableIndexesList(array $tableIndexes, string $tableName): array
    {
        foreach ($tableIndexes as $k => $v) {
            $v = array_change_key_case($v, CASE_LOWER);
            if ($v['key_name'] === 'PRIMARY') {
                $v['primary'] = true;
            } else {
                $v['primary'] = false;
            }

            if (str_contains($v['index_type'], 'FULLTEXT')) {
                $v['flags'] = ['FULLTEXT'];
            } elseif (str_contains($v['index_type'], 'SPATIAL')) {
                $v['flags'] = ['SPATIAL'];
            }

            // Ignore prohibited prefix `length` for spatial index
            if (! str_contains($v['index_type'], 'SPATIAL')) {
                $v['length'] = isset($v['sub_part']) ? (int) $v['sub_part'] : null;
            }

            $tableIndexes[$k] = $v;
        }

        return parent::_getPortableTableIndexesList($tableIndexes, $tableName);
    }

    /**
     * {@inheritdoc}
     */
    protected function _getPortableDatabaseDefinition(array $database): string
    {
        return $database['Database'];
    }

    /**
     * {@inheritdoc}
     */

    /**
     * {@inheritdoc}
     */
    protected function _getPortableTableForeignKeysList(array $tableForeignKeys): array
    {
        $list = [];
        foreach ($tableForeignKeys as $value) {
            $value = array_change_key_case($value, CASE_LOWER);
            if (! isset($list[$value['constraint_name']])) {
                if (! isset($value['delete_rule']) || $value['delete_rule'] === 'RESTRICT') {
                    $value['delete_rule'] = null;
                }

                if (! isset($value['update_rule']) || $value['update_rule'] === 'RESTRICT') {
                    $value['update_rule'] = null;
                }

                $list[$value['constraint_name']] = [
                    'name' => $value['constraint_name'],
                    'local' => [],
                    'foreign' => [],
                    'foreignTable' => $value['referenced_table_name'],
                    'onDelete' => $value['delete_rule'],
                    'onUpdate' => $value['update_rule'],
                ];
            }

            $list[$value['constraint_name']]['local'][]   = $value['column_name'];
            $list[$value['constraint_name']]['foreign'][] = $value['referenced_column_name'];
        }

        return parent::_getPortableTableForeignKeysList($list);
    }

    /**
     * {@inheritDoc}
     */
    protected function _getPortableTableForeignKeyDefinition(array $tableForeignKey): ForeignKeyConstraint
    {
        return new ForeignKeyConstraint(
            $tableForeignKey['local'],
            $tableForeignKey['foreignTable'],
            $tableForeignKey['foreign'],
            $tableForeignKey['name'],
            [
                'onDelete' => $tableForeignKey['onDelete'],
                'onUpdate' => $tableForeignKey['onUpdate'],
            ],
        );
    }

    /** @throws Exception */
    public function createComparator(): Comparator
    {
        return new MySQL\Comparator(
            $this->platform,
            new CachingCharsetMetadataProvider(
                new ConnectionCharsetMetadataProvider($this->connection),
            ),
            new CachingCollationMetadataProvider(
                new ConnectionCollationMetadataProvider($this->connection),
            ),
            $this->getDefaultTableOptions(),
        );
    }

    protected function selectDatabaseForeignKeys(string $databaseName, ?string $tableName = null): Result
    {
        $sql = 'SELECT DISTINCT';

        if ($tableName === null) {
            $sql .= ' k.TABLE_NAME,';
        }

        $sql .= <<<'SQL'
            k.CONSTRAINT_NAME,
            k.COLUMN_NAME,
            k.REFERENCED_TABLE_NAME,
            k.REFERENCED_COLUMN_NAME,
            k.ORDINAL_POSITION,
            c.UPDATE_RULE,
            c.DELETE_RULE
FROM information_schema.key_column_usage k
INNER JOIN information_schema.referential_constraints c
ON c.CONSTRAINT_NAME = k.CONSTRAINT_NAME
AND c.TABLE_NAME = k.TABLE_NAME
SQL;

        $conditions = ['k.TABLE_SCHEMA = ?'];
        $params     = [$databaseName];

        if ($tableName !== null) {
            $conditions[] = 'k.TABLE_NAME = ?';
            $params[]     = $tableName;
        }

        // The schema name is passed multiple times in the WHERE clause instead of using a JOIN condition
        // in order to avoid performance issues on MySQL older than 8.0 and the corresponding MariaDB versions
        // caused by https://bugs.mysql.com/bug.php?id=81347
        $conditions[] = 'c.CONSTRAINT_SCHEMA = ?';
        $params[]     = $databaseName;

        $conditions[] = 'k.REFERENCED_COLUMN_NAME IS NOT NULL';

        $sql .= ' WHERE ' . implode(' AND ', $conditions) . ' ORDER BY k.ORDINAL_POSITION';

        return $this->connection->executeQuery($sql, $params);
    }

    /**
     * {@inheritDoc}
     */
    protected function fetchTableOptionsByTable(string $databaseName, ?string $tableName = null): array
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

        /** @var array<string,array<string,mixed>> $metadata */
        $metadata = $this->connection->executeQuery($sql, $params)
            ->fetchAllAssociativeIndexed();

        $tableOptions = [];
        foreach ($metadata as $table => $data) {
            $data = array_change_key_case($data, CASE_LOWER);

            $tableOptions[$table] = [
                'engine'         => $data['engine'],
                'collation'      => $data['table_collation'],
                'charset'        => $data['character_set_name'],
                'autoincrement'  => $data['auto_increment'],
                'comment'        => $data['table_comment'],
                'create_options' => $this->parseCreateOptions($data['create_options']),
            ];
        }

        return $tableOptions;
    }

    /** @return array<string, string>|array<string, true> */
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

    /** @throws Exception */
    private function getDefaultTableOptions(): DefaultTableOptions
    {
        if ($this->defaultTableOptions === null) {
            $row = $this->connection->fetchNumeric(
                'SELECT @@character_set_database, @@collation_database',
            );

            assert($row !== false);

            $this->defaultTableOptions = new DefaultTableOptions(...$row);
        }

        return $this->defaultTableOptions;
    }
}
