<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Platforms;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platform\NameNormalizer\DefaultNameNormalizer;
use Doctrine\DBAL\Platforms\Keywords\KeywordList;
use Doctrine\DBAL\Platforms\Keywords\MySQLKeywords;
use Doctrine\DBAL\Platforms\MySQL\LiteralBuilder;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Name;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\SQL\Builder\IdentifierBuilder\DefaultIdentifierBuilder;
use Doctrine\DBAL\SQL\Builder\LiteralBuilder\DefaultLiteralBuilder;
use Doctrine\DBAL\TransactionIsolationLevel;
use Doctrine\DBAL\Types\BlobType;
use Doctrine\DBAL\Types\TextType;

use function array_diff_key;
use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function implode;
use function in_array;
use function is_numeric;
use function sprintf;
use function strcasecmp;
use function strtoupper;
use function trim;

/**
 * The MySQLPlatform provides the behavior, features and SQL dialect of the
 * MySQL database platform. This platform represents a MySQL 5.0 or greater platform that
 * uses the InnoDB storage engine.
 */
class MySQLPlatform extends AbstractPlatform
{
    public const LENGTH_LIMIT_TINYTEXT   = 255;
    public const LENGTH_LIMIT_TEXT       = 65535;
    public const LENGTH_LIMIT_MEDIUMTEXT = 16777215;

    public const LENGTH_LIMIT_TINYBLOB   = 255;
    public const LENGTH_LIMIT_BLOB       = 65535;
    public const LENGTH_LIMIT_MEDIUMBLOB = 16777215;

    public function __construct()
    {
        parent::__construct(
            new DefaultNameNormalizer(),
            new DefaultIdentifierBuilder('`', '`'),
            new LiteralBuilder(new DefaultLiteralBuilder())
        );
    }

    protected function doModifyLimitQuery(string $query, ?int $limit, int $offset): string
    {
        if ($limit !== null) {
            $query .= ' LIMIT ' . $limit;

            if ($offset > 0) {
                $query .= ' OFFSET ' . $offset;
            }
        } elseif ($offset > 0) {
            // 2^64-1 is the maximum of unsigned BIGINT, the biggest limit possible
            $query .= ' LIMIT 18446744073709551615 OFFSET ' . $offset;
        }

        return $query;
    }

    public function getIdentifierQuoteCharacter(): string
    {
        return '`';
    }

    public function getRegexpExpression(): string
    {
        return 'RLIKE';
    }

    public function getLocateExpression(string $string, string $substring, ?string $start = null): string
    {
        if ($start === null) {
            return sprintf('LOCATE(%s, %s)', $substring, $string);
        }

        return sprintf('LOCATE(%s, %s, %s)', $substring, $string, $start);
    }

    public function getConcatExpression(string ...$string): string
    {
        return sprintf('CONCAT(%s)', implode(', ', $string));
    }

    protected function getDateArithmeticIntervalExpression(
        string $date,
        string $operator,
        string $interval,
        string $unit
    ): string {
        $function = $operator === '+' ? 'DATE_ADD' : 'DATE_SUB';

        return $function . '(' . $date . ', INTERVAL ' . $interval . ' ' . $unit . ')';
    }

    public function getDateDiffExpression(string $date1, string $date2): string
    {
        return 'DATEDIFF(' . $date1 . ', ' . $date2 . ')';
    }

    public function getCurrentDatabaseExpression(): string
    {
        return 'DATABASE()';
    }

    public function getLengthExpression(string $string): string
    {
        return 'CHAR_LENGTH(' . $string . ')';
    }

    public function getListDatabasesSQL(): string
    {
        return 'SHOW DATABASES';
    }

    public function getListTableConstraintsSQL(Name $name): string
    {
        return 'SHOW INDEX FROM ' . $this->buildNameIdentifier($name);
    }

    /**
     * {@inheritDoc}
     *
     * Two approaches to listing the table indexes. The information_schema is
     * preferred, because it doesn't cause problems with SQL keywords such as "order" or "table".
     */
    public function getListTableIndexesSQL(Name $name, ?UnqualifiedName $databaseName = null): string
    {
        if ($databaseName !== null) {
            return 'SELECT NON_UNIQUE AS Non_Unique, INDEX_NAME AS Key_name, COLUMN_NAME AS Column_Name,' .
                   ' SUB_PART AS Sub_Part, INDEX_TYPE AS Index_Type' .
                   ' FROM information_schema.STATISTICS WHERE TABLE_NAME = ' . $this->buildNameLiteral($name) .
                   ' AND TABLE_SCHEMA = ' . $this->buildNameLiteral($databaseName) .
                   ' ORDER BY SEQ_IN_INDEX ASC';
        }

        return 'SHOW INDEX FROM ' . $this->buildNameIdentifier($name);
    }

    public function getListViewsSQL(UnqualifiedName $databaseName): string
    {
        return 'SELECT * FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ' . $this->buildNameLiteral($databaseName);
    }

    public function getListTableForeignKeysSQL(Name $name, ?UnqualifiedName $databaseName = null): string
    {
        $sql = 'SELECT DISTINCT k.`CONSTRAINT_NAME`, k.`COLUMN_NAME`, k.`REFERENCED_TABLE_NAME`, ' .
               'k.`REFERENCED_COLUMN_NAME`, k.`ORDINAL_POSITION` /*!50116 , c.update_rule, c.delete_rule */ ' .
               'FROM information_schema.key_column_usage k /*!50116 ' .
               'INNER JOIN information_schema.referential_constraints c ON ' .
               '  c.constraint_name = k.constraint_name AND ' .
               '  c.table_name = ' . $this->buildNameLiteral($name) .
               ' */ WHERE k.table_name = ' . $this->buildNameLiteral($name);

        $databaseNameSql = $this->getDatabaseNameSql($databaseName);

        return $sql . ' AND k.table_schema = ' . $databaseNameSql
            . ' /*!50116 AND c.constraint_schema = ' . $databaseNameSql . ' */'
            . ' AND k.`REFERENCED_COLUMN_NAME` is not NULL'
            . ' ORDER BY k.`ORDINAL_POSITION`';
    }

    /**
     * Gets the SQL snippet used to declare a CLOB column type.
     *     TINYTEXT   : 2 ^  8 - 1 = 255
     *     TEXT       : 2 ^ 16 - 1 = 65535
     *     MEDIUMTEXT : 2 ^ 24 - 1 = 16777215
     *     LONGTEXT   : 2 ^ 32 - 1 = 4294967295
     *
     * {@inheritDoc}
     */
    public function getClobTypeDeclarationSQL(array $column): string
    {
        if (! empty($column['length']) && is_numeric($column['length'])) {
            $length = $column['length'];

            if ($length <= static::LENGTH_LIMIT_TINYTEXT) {
                return 'TINYTEXT';
            }

            if ($length <= static::LENGTH_LIMIT_TEXT) {
                return 'TEXT';
            }

            if ($length <= static::LENGTH_LIMIT_MEDIUMTEXT) {
                return 'MEDIUMTEXT';
            }
        }

        return 'LONGTEXT';
    }

    /**
     * {@inheritDoc}
     */
    public function getDateTimeTypeDeclarationSQL(array $column): string
    {
        if (isset($column['version']) && $column['version'] === true) {
            return 'TIMESTAMP';
        }

        return 'DATETIME';
    }

    /**
     * {@inheritDoc}
     */
    public function getDateTypeDeclarationSQL(array $column): string
    {
        return 'DATE';
    }

    /**
     * {@inheritDoc}
     */
    public function getTimeTypeDeclarationSQL(array $column): string
    {
        return 'TIME';
    }

    /**
     * {@inheritDoc}
     */
    public function getBooleanTypeDeclarationSQL(array $column): string
    {
        return 'TINYINT(1)';
    }

    /**
     * {@inheritDoc}
     *
     * MySQL prefers "autoincrement" identity columns since sequences can only
     * be emulated with a table.
     */
    public function prefersIdentityColumns(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     *
     * MySQL supports this through AUTO_INCREMENT columns.
     */
    public function supportsIdentityColumns(): bool
    {
        return true;
    }

    public function supportsInlineColumnComments(): bool
    {
        return true;
    }

    public function supportsColumnCollation(): bool
    {
        return true;
    }

    public function getListTablesSQL(): string
    {
        return "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'";
    }

    public function getListTableColumnsSQL(Name $name, ?UnqualifiedName $databaseName = null): string
    {
        return 'SELECT COLUMN_NAME AS Field, COLUMN_TYPE AS Type, IS_NULLABLE AS `Null`, ' .
               'COLUMN_KEY AS `Key`, COLUMN_DEFAULT AS `Default`, EXTRA AS Extra, COLUMN_COMMENT AS Comment, ' .
               'CHARACTER_SET_NAME AS CharacterSet, COLLATION_NAME AS Collation ' .
               'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ' . $this->getDatabaseNameSql($databaseName) .
               ' AND TABLE_NAME = ' . $this->buildNameLiteral($name) . ' ORDER BY ORDINAL_POSITION';
    }

    public function getListTableMetadataSQL(Name $name, ?UnqualifiedName $databaseName = null): string
    {
        return sprintf(
            <<<'SQL'
SELECT t.ENGINE,
       t.AUTO_INCREMENT,
       t.TABLE_COMMENT,
       t.CREATE_OPTIONS,
       t.TABLE_COLLATION,
       ccsa.CHARACTER_SET_NAME
FROM information_schema.TABLES t
    INNER JOIN information_schema.`COLLATION_CHARACTER_SET_APPLICABILITY` ccsa
        ON ccsa.COLLATION_NAME = t.TABLE_COLLATION
WHERE TABLE_TYPE = 'BASE TABLE' AND TABLE_SCHEMA = %s AND TABLE_NAME = %s
SQL
            ,
            $this->getDatabaseNameSql($databaseName),
            $this->buildNameLiteral($name)
        );
    }

    /**
     * {@inheritDoc}
     */
    protected function _getCreateTableSQL(Name $name, array $columns, array $options = []): array
    {
        $queryFields = $this->getColumnDeclarationListSQL($columns);

        if (isset($options['uniqueConstraints']) && ! empty($options['uniqueConstraints'])) {
            foreach ($options['uniqueConstraints'] as $constraint) {
                $queryFields .= ', ' . $this->getUniqueConstraintDeclarationSQL($constraint);
            }
        }

        // add all indexes
        if (isset($options['indexes']) && ! empty($options['indexes'])) {
            foreach ($options['indexes'] as $index) {
                $queryFields .= ', ' . $this->getIndexDeclarationSQL($index);
            }
        }

        // attach all primary keys
        if (isset($options['primary']) && ! empty($options['primary'])) {
            $keyColumns   = array_unique(array_values($options['primary']));
            $queryFields .= ', PRIMARY KEY(' . implode(', ', $keyColumns) . ')';
        }

        $sql = ['CREATE'];

        if (! empty($options['temporary'])) {
            $sql[] = 'TEMPORARY';
        }

        $sql[] = 'TABLE ' . $name . ' (' . $queryFields . ')';

        $tableOptions = $this->buildTableOptions($options);

        if ($tableOptions !== '') {
            $sql[] = $tableOptions;
        }

        if (isset($options['partition_options'])) {
            $sql[] = $options['partition_options'];
        }

        $sql = [implode(' ', $sql)];

        // Propagate foreign key constraints only for InnoDB.
        if (
            isset($options['foreignKeys'])
            && (! isset($options['engine']) || strcasecmp($options['engine'], 'InnoDB') === 0)
        ) {
            foreach ($options['foreignKeys'] as $foreignKey) {
                $sql[] = $this->getCreateForeignKeySQL($foreignKey, $name);
            }
        }

        return $sql;
    }

    /**
     * {@inheritdoc}
     */
    public function getDefaultValueDeclarationSQL(array $column): string
    {
        // Unset the default value if the given column definition does not allow default values.
        if ($column['type'] instanceof TextType || $column['type'] instanceof BlobType) {
            $column['default'] = null;
        }

        return parent::getDefaultValueDeclarationSQL($column);
    }

    /**
     * Build SQL for table options
     *
     * @param mixed[] $options
     */
    private function buildTableOptions(array $options): string
    {
        if (isset($options['table_options'])) {
            return $options['table_options'];
        }

        $tableOptions = [];

        if (isset($options['charset'])) {
            $tableOptions[] = sprintf('DEFAULT CHARACTER SET %s', $options['charset']);
        }

        if (isset($options['collate'])) {
            $tableOptions[] = $this->getColumnCollationDeclarationSQL($options['collate']);
        }

        if (isset($options['engine'])) {
            $tableOptions[] = sprintf('ENGINE = %s', $options['engine']);
        }

        // Auto increment
        if (isset($options['auto_increment'])) {
            $tableOptions[] = sprintf('AUTO_INCREMENT = %s', $options['auto_increment']);
        }

        // Comment
        if (isset($options['comment'])) {
            $tableOptions[] = sprintf('COMMENT = %s ', $this->buildNameLiteral($options['comment']));
        }

        // Row format
        if (isset($options['row_format'])) {
            $tableOptions[] = sprintf('ROW_FORMAT = %s', $options['row_format']);
        }

        return implode(' ', $tableOptions);
    }

    /**
     * {@inheritDoc}
     */
    public function getAlterTableSQL(TableDiff $diff): array
    {
        $columnSql  = [];
        $queryParts = [];
        $newName    = $diff->getNewName();

        if ($newName !== null) {
            $queryParts[] = 'RENAME TO ' . $newName->getName();
        }

        foreach ($diff->addedColumns as $column) {
            if ($this->onSchemaAlterTableAddColumn($column, $diff, $columnSql)) {
                continue;
            }

            $columnArray = array_merge($column->toArray(), [
                'comment' => $this->getColumnComment($column),
            ]);

            $queryParts[] = 'ADD ' . $this->getColumnDeclarationSQL($column->getName(), $columnArray);
        }

        foreach ($diff->removedColumns as $column) {
            if ($this->onSchemaAlterTableRemoveColumn($column, $diff, $columnSql)) {
                continue;
            }

            $queryParts[] =  'DROP ' . $column->getName();
        }

        foreach ($diff->changedColumns as $columnDiff) {
            if ($this->onSchemaAlterTableChangeColumn($columnDiff, $diff, $columnSql)) {
                continue;
            }

            $column      = $columnDiff->column;
            $columnArray = $column->toArray();

            // Don't propagate default value changes for unsupported column types.
            if (
                $columnDiff->hasChanged('default') &&
                count($columnDiff->changedProperties) === 1 &&
                ($columnArray['type'] instanceof TextType || $columnArray['type'] instanceof BlobType)
            ) {
                continue;
            }

            $columnArray['comment'] = $this->getColumnComment($column);
            $queryParts[]           =  'CHANGE ' . ($columnDiff->getOldColumnName()->getName()) . ' '
                    . $this->getColumnDeclarationSQL($column->getName(), $columnArray);
        }

        foreach ($diff->renamedColumns as $oldColumnName => $column) {
            if ($this->onSchemaAlterTableRenameColumn($oldColumnName, $column, $diff, $columnSql)) {
                continue;
            }

            $columnArray            = $column->toArray();
            $columnArray['comment'] = $this->getColumnComment($column);
            $queryParts[]           =  'CHANGE ' . $this->buildNameIdentifier($oldColumnName) . ' '
                    . $this->getColumnDeclarationSQL($column->getName(), $columnArray);
        }

        if (isset($diff->addedIndexes['primary'])) {
            $keyColumns   = array_unique(array_values($diff->addedIndexes['primary']->getColumnNames()));
            $queryParts[] = 'ADD PRIMARY KEY (' . implode(', ', $keyColumns) . ')';
            unset($diff->addedIndexes['primary']);
        } elseif (isset($diff->changedIndexes['primary'])) {
            // Necessary in case the new primary key includes a new auto_increment column
            foreach ($diff->changedIndexes['primary']->getColumnNames() as $columnName) {
                if (isset($diff->addedColumns[$columnName]) && $diff->addedColumns[$columnName]->getAutoincrement()) {
                    $keyColumns   = array_unique(array_values($diff->changedIndexes['primary']->getColumnNames()));
                    $queryParts[] = 'DROP PRIMARY KEY';
                    $queryParts[] = 'ADD PRIMARY KEY (' . implode(', ', $keyColumns) . ')';
                    unset($diff->changedIndexes['primary']);
                    break;
                }
            }
        }

        $sql      = [];
        $tableSql = [];

        if (! $this->onSchemaAlterTable($diff, $tableSql)) {
            if (count($queryParts) > 0) {
                $sql[] = 'ALTER TABLE ' . $diff->getName($this)->getName() . ' '
                    . implode(', ', $queryParts);
            }

            $sql = array_merge(
                $this->getPreAlterTableIndexForeignKeySQL($diff),
                $sql,
                $this->getPostAlterTableIndexForeignKeySQL($diff)
            );
        }

        return array_merge($sql, $tableSql, $columnSql);
    }

    /**
     * {@inheritDoc}
     */
    protected function getPreAlterTableIndexForeignKeySQL(TableDiff $diff): array
    {
        $sql   = [];
        $table = $diff->getName($this)->getName();

        foreach ($diff->changedIndexes as $changedIndex) {
            $sql = array_merge($sql, $this->getPreAlterTableAlterPrimaryKeySQL($diff, $changedIndex));
        }

        foreach ($diff->removedIndexes as $remKey => $remIndex) {
            $sql = array_merge($sql, $this->getPreAlterTableAlterPrimaryKeySQL($diff, $remIndex));

            foreach ($diff->addedIndexes as $addKey => $addIndex) {
                if ($remIndex->getColumnNames() !== $addIndex->getColumnNames()) {
                    continue;
                }

                $indexClause = 'INDEX ' . $addIndex->getName();

                if ($addIndex->isPrimary()) {
                    $indexClause = 'PRIMARY KEY';
                } elseif ($addIndex->isUnique()) {
                    $indexClause = 'UNIQUE INDEX ' . $addIndex->getName();
                }

                $query  = 'ALTER TABLE ' . $table . ' DROP INDEX ' . $remIndex->getName() . ', ';
                $query .= 'ADD ' . $indexClause;
                $query .= ' (' . $this->getIndexFieldDeclarationListSQL($addIndex) . ')';

                $sql[] = $query;

                unset($diff->removedIndexes[$remKey], $diff->addedIndexes[$addKey]);

                break;
            }
        }

        $engine = 'INNODB';

        if ($diff->fromTable instanceof Table && $diff->fromTable->hasOption('engine')) {
            $engine = strtoupper(trim($diff->fromTable->getOption('engine')));
        }

        // Suppress foreign key constraint propagation on non-supporting engines.
        if ($engine !== 'INNODB') {
            $diff->addedForeignKeys   = [];
            $diff->changedForeignKeys = [];
            $diff->removedForeignKeys = [];
        }

        $sql = array_merge(
            $sql,
            $this->getPreAlterTableAlterIndexForeignKeySQL($diff),
            parent::getPreAlterTableIndexForeignKeySQL($diff),
            $this->getPreAlterTableRenameIndexForeignKeySQL($diff)
        );

        return $sql;
    }

    /**
     * @return string[]
     *
     * @throws Exception
     */
    private function getPreAlterTableAlterPrimaryKeySQL(TableDiff $diff, Index $index): array
    {
        $sql = [];

        if (! $index->isPrimary() || ! $diff->fromTable instanceof Table) {
            return $sql;
        }

        $tableName = $diff->getName($this)->getName();

        // Dropping primary keys requires to unset autoincrement attribute on the particular column first.
        foreach ($index->getColumnNames() as $columnName) {
            if (! $diff->fromTable->hasColumn($columnName)) {
                continue;
            }

            $column = $diff->fromTable->getColumn($columnName);

            if (! $column->getAutoincrement()) {
                continue;
            }

            $column->setAutoincrement(false);

            $sql[] = 'ALTER TABLE ' . $tableName . ' MODIFY ' .
                $this->getColumnDeclarationSQL($column->getName(), $column->toArray());

            // original autoincrement information might be needed later on by other parts of the table alteration
            $column->setAutoincrement(true);
        }

        return $sql;
    }

    /**
     * @param TableDiff $diff The table diff to gather the SQL for.
     *
     * @return string[]
     *
     * @throws Exception
     */
    private function getPreAlterTableAlterIndexForeignKeySQL(TableDiff $diff): array
    {
        $sql   = [];
        $table = $diff->getName($this)->getName();

        foreach ($diff->changedIndexes as $changedIndex) {
            // Changed primary key
            if (! $changedIndex->isPrimary() || ! ($diff->fromTable instanceof Table)) {
                continue;
            }

            foreach ($diff->fromTable->getPrimaryKeyColumns() as $columnName => $column) {
                $column = $diff->fromTable->getColumn($columnName);

                // Check if an autoincrement column was dropped from the primary key.
                if (! $column->getAutoincrement() || in_array($columnName, $changedIndex->getColumnNames(), true)) {
                    continue;
                }

                // The autoincrement attribute needs to be removed from the dropped column
                // before we can drop and recreate the primary key.
                $column->setAutoincrement(false);

                $sql[] = 'ALTER TABLE ' . $table . ' MODIFY ' .
                    $this->getColumnDeclarationSQL($column->getName(), $column->toArray());

                // Restore the autoincrement attribute as it might be needed later on
                // by other parts of the table alteration.
                $column->setAutoincrement(true);
            }
        }

        return $sql;
    }

    /**
     * @param TableDiff $diff The table diff to gather the SQL for.
     *
     * @return string[]
     */
    protected function getPreAlterTableRenameIndexForeignKeySQL(TableDiff $diff): array
    {
        $sql       = [];
        $tableName = $diff->getName($this)->getName();

        foreach ($this->getRemainingForeignKeyConstraintsRequiringRenamedIndexes($diff) as $foreignKey) {
            if (in_array($foreignKey, $diff->changedForeignKeys, true)) {
                continue;
            }

            $sql[] = $this->getDropForeignKeySQL($foreignKey->getName(), $tableName);
        }

        return $sql;
    }

    /**
     * Returns the remaining foreign key constraints that require one of the renamed indexes.
     *
     * "Remaining" here refers to the diff between the foreign keys currently defined in the associated
     * table and the foreign keys to be removed.
     *
     * @param TableDiff $diff The table diff to evaluate.
     *
     * @return ForeignKeyConstraint[]
     */
    private function getRemainingForeignKeyConstraintsRequiringRenamedIndexes(TableDiff $diff): array
    {
        if (empty($diff->renamedIndexes) || ! $diff->fromTable instanceof Table) {
            return [];
        }

        $foreignKeys = [];
        /** @var ForeignKeyConstraint[] $remainingForeignKeys */
        $remainingForeignKeys = array_diff_key(
            $diff->fromTable->getForeignKeys(),
            $diff->removedForeignKeys
        );

        foreach ($remainingForeignKeys as $foreignKey) {
            foreach ($diff->renamedIndexes as $index) {
                if ($foreignKey->intersectsIndexColumns($index)) {
                    $foreignKeys[] = $foreignKey;

                    break;
                }
            }
        }

        return $foreignKeys;
    }

    /**
     * {@inheritdoc}
     */
    protected function getPostAlterTableIndexForeignKeySQL(TableDiff $diff): array
    {
        return array_merge(
            parent::getPostAlterTableIndexForeignKeySQL($diff),
            $this->getPostAlterTableRenameIndexForeignKeySQL($diff)
        );
    }

    /**
     * @param TableDiff $diff The table diff to gather the SQL for.
     *
     * @return string[]
     */
    protected function getPostAlterTableRenameIndexForeignKeySQL(TableDiff $diff): array
    {
        $sql     = [];
        $newName = $diff->getNewName();

        if ($newName !== null) {
            $tableName = $newName->getName();
        } else {
            $tableName = $diff->getName($this)->getName();
        }

        foreach ($this->getRemainingForeignKeyConstraintsRequiringRenamedIndexes($diff) as $foreignKey) {
            if (in_array($foreignKey, $diff->changedForeignKeys, true)) {
                continue;
            }

            $sql[] = $this->getCreateForeignKeySQL($foreignKey, $tableName);
        }

        return $sql;
    }

    protected function getCreateIndexSQLFlags(Index $index): string
    {
        $type = '';
        if ($index->isUnique()) {
            $type .= 'UNIQUE ';
        } elseif ($index->hasFlag('fulltext')) {
            $type .= 'FULLTEXT ';
        } elseif ($index->hasFlag('spatial')) {
            $type .= 'SPATIAL ';
        }

        return $type;
    }

    /**
     * {@inheritDoc}
     */
    public function getIntegerTypeDeclarationSQL(array $column): string
    {
        return 'INT' . $this->_getCommonIntegerTypeDeclarationSQL($column);
    }

    /**
     * {@inheritDoc}
     */
    public function getBigIntTypeDeclarationSQL(array $column): string
    {
        return 'BIGINT' . $this->_getCommonIntegerTypeDeclarationSQL($column);
    }

    /**
     * {@inheritDoc}
     */
    public function getSmallIntTypeDeclarationSQL(array $column): string
    {
        return 'SMALLINT' . $this->_getCommonIntegerTypeDeclarationSQL($column);
    }

    /**
     * {@inheritdoc}
     */
    public function getFloatDeclarationSQL(array $column): string
    {
        return 'DOUBLE PRECISION' . $this->getUnsignedDeclaration($column);
    }

    /**
     * {@inheritdoc}
     */
    public function getDecimalTypeDeclarationSQL(array $column): string
    {
        return parent::getDecimalTypeDeclarationSQL($column) . $this->getUnsignedDeclaration($column);
    }

    /**
     * Get unsigned declaration for a column.
     *
     * @param mixed[] $columnDef
     */
    private function getUnsignedDeclaration(array $columnDef): string
    {
        return ! empty($columnDef['unsigned']) ? ' UNSIGNED' : '';
    }

    /**
     * {@inheritDoc}
     */
    protected function _getCommonIntegerTypeDeclarationSQL(array $column): string
    {
        $autoinc = '';
        if (! empty($column['autoincrement'])) {
            $autoinc = ' AUTO_INCREMENT';
        }

        return $this->getUnsignedDeclaration($column) . $autoinc;
    }

    public function getColumnCharsetDeclarationSQL(string $charset): string
    {
        return 'CHARACTER SET ' . $charset;
    }

    public function getColumnCollationDeclarationSQL(string $collation): string
    {
        return 'COLLATE ' . $this->quoteSingleIdentifier($collation);
    }

    public function getAdvancedForeignKeyOptionsSQL(ForeignKeyConstraint $foreignKey): string
    {
        $query = '';
        if ($foreignKey->hasOption('match')) {
            $query .= ' MATCH ' . $foreignKey->getOption('match');
        }

        $query .= parent::getAdvancedForeignKeyOptionsSQL($foreignKey);

        return $query;
    }

    public function getDropIndexSQL(Name $name, Name $tableName): string
    {
        return 'DROP INDEX ' . $this->buildNameIdentifier($name) . ' ON ' . $this->buildNameIdentifier($tableName);
    }

    protected function getDropPrimaryKeySQL(Name $tableName): string
    {
        return 'ALTER TABLE ' . $this->buildNameIdentifier($tableName) . ' DROP PRIMARY KEY';
    }

    /**
     * The `ALTER TABLE ... DROP CONSTRAINT` syntax is only available as of MySQL 8.0.19.
     *
     * @link https://dev.mysql.com/doc/refman/8.0/en/alter-table.html
     */
    public function getDropUniqueConstraintSQL(string $name, string $tableName): string
    {
        return $this->getDropIndexSQL($name, $tableName);
    }

    public function getSetTransactionIsolationSQL(int $level): string
    {
        return 'SET SESSION TRANSACTION ISOLATION LEVEL ' . $this->_getTransactionIsolationLevelSQL($level);
    }

    public function getReadLockSQL(): string
    {
        return 'LOCK IN SHARE MODE';
    }

    protected function initializeDoctrineTypeMappings(): void
    {
        $this->doctrineTypeMapping = [
            'bigint'     => 'bigint',
            'binary'     => 'binary',
            'blob'       => 'blob',
            'char'       => 'string',
            'date'       => 'date',
            'datetime'   => 'datetime',
            'decimal'    => 'decimal',
            'double'     => 'float',
            'float'      => 'float',
            'int'        => 'integer',
            'integer'    => 'integer',
            'longblob'   => 'blob',
            'longtext'   => 'text',
            'mediumblob' => 'blob',
            'mediumint'  => 'integer',
            'mediumtext' => 'text',
            'numeric'    => 'decimal',
            'real'       => 'float',
            'set'        => 'simple_array',
            'smallint'   => 'smallint',
            'string'     => 'string',
            'text'       => 'text',
            'time'       => 'time',
            'timestamp'  => 'datetime',
            'tinyblob'   => 'blob',
            'tinyint'    => 'boolean',
            'tinytext'   => 'text',
            'varbinary'  => 'binary',
            'varchar'    => 'string',
            'year'       => 'date',
        ];
    }

    protected function createReservedKeywordsList(): KeywordList
    {
        return new MySQLKeywords();
    }

    /**
     * {@inheritDoc}
     *
     * MySQL commits a transaction implicitly when DROP TABLE is executed, however not
     * if DROP TEMPORARY TABLE is executed.
     */
    public function getDropTemporaryTableSQL(Name $name): string
    {
        return 'DROP TEMPORARY TABLE ' . $this->buildNameIdentifier($name);
    }

    /**
     * Gets the SQL Snippet used to declare a BLOB column type.
     *     TINYBLOB   : 2 ^  8 - 1 = 255
     *     BLOB       : 2 ^ 16 - 1 = 65535
     *     MEDIUMBLOB : 2 ^ 24 - 1 = 16777215
     *     LONGBLOB   : 2 ^ 32 - 1 = 4294967295
     *
     * {@inheritDoc}
     */
    public function getBlobTypeDeclarationSQL(array $column): string
    {
        if (! empty($column['length']) && is_numeric($column['length'])) {
            $length = $column['length'];

            if ($length <= static::LENGTH_LIMIT_TINYBLOB) {
                return 'TINYBLOB';
            }

            if ($length <= static::LENGTH_LIMIT_BLOB) {
                return 'BLOB';
            }

            if ($length <= static::LENGTH_LIMIT_MEDIUMBLOB) {
                return 'MEDIUMBLOB';
            }
        }

        return 'LONGBLOB';
    }

    public function getDefaultTransactionIsolationLevel(): int
    {
        return TransactionIsolationLevel::REPEATABLE_READ;
    }

    public function supportsColumnLengthIndexes(): bool
    {
        return true;
    }

    /**
     * Returns an SQL expression representing the given database name or current database name
     *
     * @param Name|null $name Database name
     */
    private function getDatabaseNameSql(?Name $name): string
    {
        if ($name === null) {
            return $this->getCurrentDatabaseExpression();
        }

        return $this->buildNameLiteral($name);
    }
}
