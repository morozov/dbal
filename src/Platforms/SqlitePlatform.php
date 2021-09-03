<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Platforms;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\Keywords\KeywordList;
use Doctrine\DBAL\Platforms\Keywords\SQLiteKeywords;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Exception\ColumnDoesNotExist;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Name;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\TransactionIsolationLevel;
use Doctrine\DBAL\Types;
use InvalidArgumentException;

use function array_combine;
use function array_keys;
use function array_merge;
use function array_search;
use function array_unique;
use function array_values;
use function implode;
use function sprintf;
use function str_replace;
use function strtolower;
use function trim;

/**
 * The SqlitePlatform class describes the specifics and dialects of the SQLite
 * database platform.
 *
 * @todo   Rename: SQLitePlatform
 */
class SqlitePlatform extends AbstractPlatform
{
    public function getRegexpExpression(): string
    {
        return 'REGEXP';
    }

    public function getTrimExpression(string $str, int $mode = TrimMode::UNSPECIFIED, ?string $char = null): string
    {
        switch ($mode) {
            case TrimMode::UNSPECIFIED:
            case TrimMode::BOTH:
                $trimFn = 'TRIM';
                break;

            case TrimMode::LEADING:
                $trimFn = 'LTRIM';
                break;

            case TrimMode::TRAILING:
                $trimFn = 'RTRIM';
                break;

            default:
                throw new InvalidArgumentException(
                    sprintf(
                        'The value of $mode is expected to be one of the TrimMode constants, %d given.',
                        $mode
                    )
                );
        }

        $arguments = [$str];

        if ($char !== null) {
            $arguments[] = $char;
        }

        return sprintf('%s(%s)', $trimFn, implode(', ', $arguments));
    }

    public function getSubstringExpression(string $string, string $start, ?string $length = null): string
    {
        if ($length === null) {
            return sprintf('SUBSTR(%s, %s)', $string, $start);
        }

        return sprintf('SUBSTR(%s, %s, %s)', $string, $start, $length);
    }

    public function getLocateExpression(string $string, string $substring, ?string $start = null): string
    {
        if ($start === null) {
            return sprintf('LOCATE(%s, %s)', $string, $substring);
        }

        return sprintf('LOCATE(%s, %s, %s)', $string, $substring, $start);
    }

    protected function getDateArithmeticIntervalExpression(
        string $date,
        string $operator,
        string $interval,
        string $unit
    ): string {
        switch ($unit) {
            case DateIntervalUnit::WEEK:
                $interval = $this->multiplyInterval($interval, 7);
                $unit     = DateIntervalUnit::DAY;
                break;

            case DateIntervalUnit::QUARTER:
                $interval = $this->multiplyInterval($interval, 3);
                $unit     = DateIntervalUnit::MONTH;
                break;
        }

        return 'DATETIME(' . $date . ',' . $this->getConcatExpression(
            $this->quoteStringLiteral($operator),
            $interval,
            $this->quoteStringLiteral(' ' . $unit)
        ) . ')';
    }

    public function getDateDiffExpression(string $date1, string $date2): string
    {
        return sprintf("JULIANDAY(%s, 'start of day') - JULIANDAY(%s, 'start of day')", $date1, $date2);
    }

    /**
     * {@inheritDoc}
     *
     * The SQLite platform doesn't support the concept of a database, therefore, it always returns an empty string
     * as an indicator of an implicitly selected database.
     *
     * @see \Doctrine\DBAL\Connection::getDatabase()
     */
    public function getCurrentDatabaseExpression(): string
    {
        return "''";
    }

    protected function _getTransactionIsolationLevelSQL(int $level): string
    {
        switch ($level) {
            case TransactionIsolationLevel::READ_UNCOMMITTED:
                return '0';

            case TransactionIsolationLevel::READ_COMMITTED:
            case TransactionIsolationLevel::REPEATABLE_READ:
            case TransactionIsolationLevel::SERIALIZABLE:
                return '1';

            default:
                return parent::_getTransactionIsolationLevelSQL($level);
        }
    }

    public function getSetTransactionIsolationSQL(int $level): string
    {
        return 'PRAGMA read_uncommitted = ' . $this->_getTransactionIsolationLevelSQL($level);
    }

    public function prefersIdentityColumns(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function getBooleanTypeDeclarationSQL(array $column): string
    {
        return 'BOOLEAN';
    }

    /**
     * {@inheritDoc}
     */
    public function getIntegerTypeDeclarationSQL(array $column): string
    {
        return 'INTEGER' . $this->_getCommonIntegerTypeDeclarationSQL($column);
    }

    /**
     * {@inheritDoc}
     */
    public function getBigIntTypeDeclarationSQL(array $column): string
    {
        //  SQLite autoincrement is implicit for INTEGER PKs, but not for BIGINT fields.
        if (! empty($column['autoincrement'])) {
            return $this->getIntegerTypeDeclarationSQL($column);
        }

        return 'BIGINT' . $this->_getCommonIntegerTypeDeclarationSQL($column);
    }

    /**
     * @param array<string, mixed> $column
     */
    public function getTinyIntTypeDeclarationSQL(array $column): string
    {
        // SQLite autoincrement is implicit for INTEGER PKs, but not for TINYINT columns
        if (! empty($column['autoincrement'])) {
            return $this->getIntegerTypeDeclarationSQL($column);
        }

        return 'TINYINT' . $this->_getCommonIntegerTypeDeclarationSQL($column);
    }

    /**
     * {@inheritDoc}
     */
    public function getSmallIntTypeDeclarationSQL(array $column): string
    {
        // SQLite autoincrement is implicit for INTEGER PKs, but not for SMALLINT fields.
        if (! empty($column['autoincrement'])) {
            return $this->getIntegerTypeDeclarationSQL($column);
        }

        return 'SMALLINT' . $this->_getCommonIntegerTypeDeclarationSQL($column);
    }

    /**
     * @param array<string, mixed> $column
     */
    public function getMediumIntTypeDeclarationSQL(array $column): string
    {
        // SQLite autoincrement is implicit for INTEGER PKs, but not for MEDIUMINT columns
        if (! empty($column['autoincrement'])) {
            return $this->getIntegerTypeDeclarationSQL($column);
        }

        return 'MEDIUMINT' . $this->_getCommonIntegerTypeDeclarationSQL($column);
    }

    /**
     * {@inheritDoc}
     */
    public function getDateTimeTypeDeclarationSQL(array $column): string
    {
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
    protected function _getCommonIntegerTypeDeclarationSQL(array $column): string
    {
        // sqlite autoincrement is only possible for the primary key
        if (! empty($column['autoincrement'])) {
            return ' PRIMARY KEY AUTOINCREMENT';
        }

        return ! empty($column['unsigned']) ? ' UNSIGNED' : '';
    }

    public function getForeignKeyDeclarationSQL(ForeignKeyConstraint $foreignKey): string
    {
        return parent::getForeignKeyDeclarationSQL(new ForeignKeyConstraint(
            $foreignKey->getQuotedLocalColumns($this),
            $foreignKey->getForeignTableName(),
            $foreignKey->getQuotedForeignColumns($this),
            $foreignKey->getName(),
            $foreignKey->getOptions()
        ));
    }

    /**
     * {@inheritDoc}
     */
    protected function _getCreateTableSQL(Name $name, array $columns, array $options = []): array
    {
        $queryFields = $this->getColumnDeclarationListSQL($columns);

        if (isset($options['uniqueConstraints']) && ! empty($options['uniqueConstraints'])) {
            foreach ($options['uniqueConstraints'] as $definition) {
                $queryFields .= ', ' . $this->getUniqueConstraintDeclarationSQL($definition);
            }
        }

        $queryFields .= $this->getNonAutoincrementPrimaryKeyDefinition($columns, $options);

        if (isset($options['foreignKeys'])) {
            foreach ($options['foreignKeys'] as $foreignKey) {
                $queryFields .= ', ' . $this->getForeignKeyDeclarationSQL($foreignKey);
            }
        }

        $tableComment = '';
        if (isset($options['comment'])) {
            $comment = trim($options['comment'], " '");

            $tableComment = $this->getInlineTableCommentSQL($comment);
        }

        $query = ['CREATE TABLE ' . $this->buildNameIdentifier($name) . ' ' . $tableComment . '(' . $queryFields . ')'];

        if (isset($options['alter']) && $options['alter'] === true) {
            return $query;
        }

        if (isset($options['indexes'])) {
            foreach ($options['indexes'] as $indexDef) {
                $query[] = $this->getCreateIndexSQL($indexDef, $name);
            }
        }

        if (isset($options['unique'])) {
            foreach ($options['unique'] as $indexDef) {
                $query[] = $this->getCreateIndexSQL($indexDef, $name);
            }
        }

        return $query;
    }

    /**
     * Generate a PRIMARY KEY definition if no autoincrement value is used
     *
     * @param mixed[][] $columns
     * @param mixed[]   $options
     */
    private function getNonAutoincrementPrimaryKeyDefinition(array $columns, array $options): string
    {
        if (empty($options['primary'])) {
            return '';
        }

        $keyColumns = array_unique(array_values($options['primary']));

        foreach ($keyColumns as $keyColumn) {
            foreach ($columns as $column) {
                if ($column['name'] === $keyColumn && ! empty($column['autoincrement'])) {
                    return '';
                }
            }
        }

        return ', PRIMARY KEY(' . implode(', ', $keyColumns) . ')';
    }

    protected function getBinaryTypeDeclarationSQLSnippet(?int $length): string
    {
        return 'BLOB';
    }

    protected function getVarcharTypeDeclarationSQLSnippet(?int $length): string
    {
        $sql = 'VARCHAR';

        if ($length !== null) {
            $sql .= sprintf('(%d)', $length);
        }

        return $sql;
    }

    protected function getVarbinaryTypeDeclarationSQLSnippet(?int $length): string
    {
        return 'BLOB';
    }

    /**
     * {@inheritDoc}
     */
    public function getClobTypeDeclarationSQL(array $column): string
    {
        return 'CLOB';
    }

    public function getListTableConstraintsSQL(Name $name): string
    {
        return sprintf(
            "SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name = %s AND sql NOT NULL ORDER BY name",
            $this->buildNameLiteral($name)
        );
    }

    public function getListTableColumnsSQL(Name $name, ?UnqualifiedName $databaseName = null): string
    {
        return sprintf('PRAGMA table_info(%s)', $this->buildNameLiteral($name));
    }

    public function getListTableIndexesSQL(Name $name, ?UnqualifiedName $databaseName = null): string
    {
        return sprintf('PRAGMA index_list(%s)', $this->buildNameLiteral($name));
    }

    public function getListTablesSQL(): string
    {
        return 'SELECT name FROM sqlite_master'
            . " WHERE type = 'table'"
            . " AND name != 'sqlite_sequence'"
            . " AND name != 'geometry_columns'"
            . " AND name != 'spatial_ref_sys'"
            . ' UNION ALL SELECT name FROM sqlite_temp_master'
            . " WHERE type = 'table' ORDER BY name";
    }

    public function getListViewsSQL(UnqualifiedName $databaseName): string
    {
        return "SELECT name, sql FROM sqlite_master WHERE type='view' AND sql NOT NULL";
    }

    public function getAdvancedForeignKeyOptionsSQL(ForeignKeyConstraint $foreignKey): string
    {
        $query = parent::getAdvancedForeignKeyOptionsSQL($foreignKey);

        if (! $foreignKey->hasOption('deferrable') || $foreignKey->getOption('deferrable') === false) {
            $query .= ' NOT';
        }

        $query .= ' DEFERRABLE';
        $query .= ' INITIALLY';

        if ($foreignKey->hasOption('deferred') && $foreignKey->getOption('deferred') !== false) {
            $query .= ' DEFERRED';
        } else {
            $query .= ' IMMEDIATE';
        }

        return $query;
    }

    public function supportsCreateDropDatabase(): bool
    {
        return false;
    }

    public function supportsIdentityColumns(): bool
    {
        return true;
    }

    public function supportsColumnCollation(): bool
    {
        return true;
    }

    public function supportsInlineColumnComments(): bool
    {
        return true;
    }

    public function getTruncateTableSQL(Name $name, bool $cascade = false): string
    {
        return 'DELETE FROM ' . $this->buildNameIdentifier($name);
    }

    public function getForUpdateSQL(): string
    {
        return '';
    }

    public function getInlineColumnCommentSQL(string $comment): string
    {
        if ($comment === '') {
            return '';
        }

        return '--' . str_replace("\n", "\n--", $comment) . "\n";
    }

    private function getInlineTableCommentSQL(string $comment): string
    {
        return $this->getInlineColumnCommentSQL($comment);
    }

    protected function initializeDoctrineTypeMappings(): void
    {
        $this->doctrineTypeMapping = [
            'bigint'           => 'bigint',
            'bigserial'        => 'bigint',
            'blob'             => 'blob',
            'boolean'          => 'boolean',
            'char'             => 'string',
            'clob'             => 'text',
            'date'             => 'date',
            'datetime'         => 'datetime',
            'decimal'          => 'decimal',
            'double'           => 'float',
            'double precision' => 'float',
            'float'            => 'float',
            'image'            => 'string',
            'int'              => 'integer',
            'integer'          => 'integer',
            'longtext'         => 'text',
            'longvarchar'      => 'string',
            'mediumint'        => 'integer',
            'mediumtext'       => 'text',
            'ntext'            => 'string',
            'numeric'          => 'decimal',
            'nvarchar'         => 'string',
            'real'             => 'float',
            'serial'           => 'integer',
            'smallint'         => 'smallint',
            'string'           => 'string',
            'text'             => 'text',
            'time'             => 'time',
            'timestamp'        => 'datetime',
            'tinyint'          => 'boolean',
            'tinytext'         => 'text',
            'varchar'          => 'string',
            'varchar2'         => 'string',
        ];
    }

    protected function createReservedKeywordsList(): KeywordList
    {
        return new SQLiteKeywords();
    }

    /**
     * {@inheritDoc}
     */
    protected function getPreAlterTableIndexForeignKeySQL(TableDiff $diff): array
    {
        if (! $diff->fromTable instanceof Table) {
            throw new Exception(
                'Sqlite platform requires for alter table the table diff with reference to original table schema.'
            );
        }

        $sql = [];
        foreach ($diff->fromTable->getIndexes() as $index) {
            if ($index->isPrimary()) {
                continue;
            }

            $sql[] = $this->getDropIndexSQL($index->getName(), $diff->getName());
        }

        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    protected function getPostAlterTableIndexForeignKeySQL(TableDiff $diff): array
    {
        $fromTable = $diff->fromTable;

        if ($fromTable === null) {
            throw new Exception(
                'Sqlite platform requires for alter table the table diff with reference to original table schema.'
            );
        }

        $sql       = [];
        $tableName = $diff->getNewName() ?? $diff->getName();

        foreach ($this->getIndexesInAlteredTable($diff, $fromTable) as $index) {
            if ($index->isPrimary()) {
                continue;
            }

            $sql[] = $this->getCreateIndexSQL($index, $tableName);
        }

        return $sql;
    }

    protected function doModifyLimitQuery(string $query, ?int $limit, int $offset): string
    {
        if ($limit === null && $offset > 0) {
            $limit = -1;
        }

        return parent::doModifyLimitQuery($query, $limit, $offset);
    }

    /**
     * {@inheritDoc}
     */
    public function getBlobTypeDeclarationSQL(array $column): string
    {
        return 'BLOB';
    }

    public function supportsForeignKeyConstraints(): bool
    {
        return false;
    }

    public function getCreatePrimaryKeySQL(Index $index, Name $table): string
    {
        throw new Exception('Sqlite platform does not support alter primary key.');
    }

    public function getCreateForeignKeySQL(ForeignKeyConstraint $foreignKey, Name $tableName): string
    {
        throw new Exception('Sqlite platform does not support alter foreign key.');
    }

    public function getDropForeignKeySQL(Name $name, Name $tableName): string
    {
        throw new Exception('Sqlite platform does not support alter foreign key.');
    }

    /**
     * {@inheritDoc}
     */
    public function getCreateTableSQL(
        Table $table,
        int $createFlags = self::CREATE_INDEXES | self::CREATE_FOREIGNKEYS
    ): array {
        return parent::getCreateTableSQL($table, $createFlags);
    }

    public function getListTableForeignKeysSQL(Name $name, ?UnqualifiedName $databaseName = null): string
    {
        return sprintf('PRAGMA foreign_key_list(%s)', $this->buildNameLiteral($name));
    }

    /**
     * {@inheritDoc}
     */
    public function getAlterTableSQL(TableDiff $diff): array
    {
        $sql = $this->getSimpleAlterTableSQL($diff);
        if ($sql !== false) {
            return $sql;
        }

        $fromTable = $diff->fromTable;
        if ($fromTable === null) {
            throw new Exception(
                'Sqlite platform requires for alter table the table diff with reference to original table schema.'
            );
        }

        $table = clone $fromTable;

        $columns        = [];
        $oldColumnNames = [];
        $newColumnNames = [];
        $columnSql      = [];

        foreach ($table->getColumns() as $column) {
            $columnName                  = $column->getName();
            $columns[$columnName]        = $column;
            $oldColumnNames[$columnName] = $newColumnNames[$columnName] = $column->getName();
        }

        foreach ($diff->removedColumns as $columnName => $column) {
            if ($this->onSchemaAlterTableRemoveColumn($column, $diff, $columnSql)) {
                continue;
            }

            if (! isset($columns[$columnName])) {
                continue;
            }

            unset(
                $columns[$columnName],
                $oldColumnNames[$columnName],
                $newColumnNames[$columnName]
            );
        }

        foreach ($diff->renamedColumns as $oldColumnName => $column) {
            if ($this->onSchemaAlterTableRenameColumn($oldColumnName, $column, $diff, $columnSql)) {
                continue;
            }

            $oldColumnName = strtolower($oldColumnName);
            $columns       = $this->replaceColumn($diff->name, $columns, $oldColumnName, $column);

            if (! isset($newColumnNames[$oldColumnName])) {
                continue;
            }

            $newColumnNames[$oldColumnName] = $column->getName();
        }

        foreach ($diff->changedColumns as $oldColumnName => $columnDiff) {
            if ($this->onSchemaAlterTableChangeColumn($columnDiff, $diff, $columnSql)) {
                continue;
            }

            $oldColumnName = strtolower($oldColumnName);
            $columns       = $this->replaceColumn($diff->name, $columns, $oldColumnName, $columnDiff->column);

            if (! isset($newColumnNames[$oldColumnName])) {
                continue;
            }

            $newColumnNames[$oldColumnName] = $columnDiff->column->getName();
        }

        foreach ($diff->addedColumns as $columnName => $column) {
            if ($this->onSchemaAlterTableAddColumn($column, $diff, $columnSql)) {
                continue;
            }

            $columns[$columnName] = $column;
        }

        $sql      = [];
        $tableSql = [];

        if (! $this->onSchemaAlterTable($diff, $tableSql)) {
            $dataTable = new Table(
                $table->getName()
                    ->generate(static function (string $name): string {
                        return '__temp__' . $name;
                    })
            );

            $newTable = new Table(
                $table->getName(),
                $columns,
                $this->getPrimaryIndexInAlteredTable($diff, $fromTable),
                [],
                $this->getForeignKeysInAlteredTable($diff, $fromTable),
                $table->getOptions()
            );

            $newTable->addOption('alter', true);

            $sql = $this->getPreAlterTableIndexForeignKeySQL($diff);

            $sql[] = sprintf(
                'CREATE TEMPORARY TABLE %s AS SELECT %s FROM %s',
                $this->buildNameIdentifier($dataTable->getName()),
                implode(', ', $oldColumnNames),
                $this->buildNameIdentifier($table->getName())
            );
            $sql[] = $this->getDropTableSQL($fromTable->getName());

            $sql   = array_merge($sql, $this->getCreateTableSQL($newTable));
            $sql[] = sprintf(
                'INSERT INTO %s (%s) SELECT %s FROM %s',
                $this->buildNameIdentifier($newTable->getName()),
                implode(', ', $newColumnNames),
                implode(', ', $oldColumnNames),
                $this->buildNameIdentifier($dataTable->getName())
            );
            $sql[] = $this->getDropTableSQL($dataTable->getName());

            $newName = $diff->getNewName();

            if ($newName !== null) {
                $sql[] = sprintf(
                    'ALTER TABLE %s RENAME TO %s',
                    $this->buildNameIdentifier($newTable->getName()),
                    $this->buildNameIdentifier($newName)
                );
            }

            $sql = array_merge($sql, $this->getPostAlterTableIndexForeignKeySQL($diff));
        }

        return array_merge($sql, $tableSql, $columnSql);
    }

    /**
     * Replace the column with the given name with the new column.
     *
     * @param array<string,Column> $columns
     *
     * @return array<string,Column>
     *
     * @throws Exception
     */
    private function replaceColumn(Name $tableName, array $columns, string $columnName, Column $column): array
    {
        $keys  = array_keys($columns);
        $index = array_search($columnName, $keys, true);

        if ($index === false) {
            throw ColumnDoesNotExist::new($columnName, $tableName);
        }

        $values = array_values($columns);

        $keys[$index]   = $column->getName();
        $values[$index] = $column;

        return array_combine($keys, $values);
    }

    /**
     * @return string[]|false
     *
     * @throws Exception
     */
    private function getSimpleAlterTableSQL(TableDiff $diff)
    {
        // Suppress changes on integer type autoincrement columns.
        foreach ($diff->changedColumns as $oldColumnName => $columnDiff) {
            if (
                ! $columnDiff->column->getAutoincrement() ||
                ! $columnDiff->column->getType() instanceof Types\IntegerType
            ) {
                continue;
            }

            if (! $columnDiff->hasChanged('type') && $columnDiff->hasChanged('unsigned')) {
                unset($diff->changedColumns[$oldColumnName]);

                continue;
            }

            $fromColumnType = $columnDiff->fromColumn->getType();

            if (! ($fromColumnType instanceof Types\SmallIntType) && ! ($fromColumnType instanceof Types\BigIntType)) {
                continue;
            }

            unset($diff->changedColumns[$oldColumnName]);
        }

        if (
            ! empty($diff->renamedColumns)
            || ! empty($diff->addedForeignKeys)
            || ! empty($diff->addedIndexes)
            || ! empty($diff->changedColumns)
            || ! empty($diff->changedForeignKeys)
            || ! empty($diff->changedIndexes)
            || ! empty($diff->removedColumns)
            || ! empty($diff->removedForeignKeys)
            || ! empty($diff->removedIndexes)
            || ! empty($diff->renamedIndexes)
        ) {
            return false;
        }

        $table = new Table($diff->getName());

        $sql       = [];
        $tableSql  = [];
        $columnSql = [];

        foreach ($diff->addedColumns as $column) {
            if ($this->onSchemaAlterTableAddColumn($column, $diff, $columnSql)) {
                continue;
            }

            $definition = array_merge([
                'unique' => null,
                'autoincrement' => null,
                'default' => null,
            ], $column->toArray());

            $type = $definition['type'];

            switch (true) {
                case isset($definition['columnDefinition']) || $definition['autoincrement'] || $definition['unique']:
                case $type instanceof Types\DateTimeType && $definition['default'] === $this->getCurrentTimestampSQL():
                case $type instanceof Types\DateType && $definition['default'] === $this->getCurrentDateSQL():
                case $type instanceof Types\TimeType && $definition['default'] === $this->getCurrentTimeSQL():
                    return false;
            }

            $definition['name'] = $column->getName();
            if ($type instanceof Types\StringType && $definition['length'] === null) {
                $definition['length'] = 255;
            }

            $sql[] = 'ALTER TABLE ' . $this->buildNameIdentifier($table->getName())
                . ' ADD COLUMN ' . $this->getColumnDeclarationSQL($definition['name'], $definition);
        }

        if (! $this->onSchemaAlterTable($diff, $tableSql)) {
            if ($diff->newName !== null) {
                $sql[] = 'ALTER TABLE ' . $this->buildNameIdentifier($table->getName())
                    . ' RENAME TO ' . $this->buildNameIdentifier($diff->getNewName());
            }
        }

        return array_merge($sql, $tableSql, $columnSql);
    }

    /**
     * @return string[]
     */
    private function getColumnNamesInAlteredTable(TableDiff $diff, Table $fromTable): array
    {
        $columns = [];

        foreach ($fromTable->getColumns() as $column) {
            $columnName           = $column->getName();
            $columns[$columnName] = $columnName;
        }

        foreach ($diff->removedColumns as $columnName => $column) {
            if (! isset($columns[$columnName])) {
                continue;
            }

            unset($columns[$columnName]);
        }

        foreach ($diff->renamedColumns as $oldColumnName => $column) {
            $columnName              = $column->getName();
            $columns[$oldColumnName] = $columnName;
            $columns[$columnName]    = $columnName;
        }

        foreach ($diff->changedColumns as $oldColumnName => $columnDiff) {
            $columnName              = $columnDiff->column->getName();
            $columns[$oldColumnName] = $columnName;
            $columns[$columnName]    = $columnName;
        }

        foreach ($diff->addedColumns as $column) {
            $columnName           = $column->getName();
            $columns[$columnName] = $columnName;
        }

        return $columns;
    }

    /**
     * @return Index[]
     */
    private function getIndexesInAlteredTable(TableDiff $diff, Table $fromTable): array
    {
        $indexes     = $fromTable->getIndexes();
        $columnNames = $this->getColumnNamesInAlteredTable($diff, $fromTable);

        foreach ($indexes as $key => $index) {
            foreach ($diff->renamedIndexes as $oldIndexName => $renamedIndex) {
                if ($key !== $oldIndexName) {
                    continue;
                }

                unset($indexes[$key]);
            }

            $changed      = false;
            $indexColumns = [];
            foreach ($index->getColumnNames() as $columnName) {
                if (! isset($columnNames[$columnName])) {
                    unset($indexes[$key]);
                    continue 2;
                }

                $indexColumns[] = $columnNames[$columnName];
                if ($columnName === $columnNames[$columnName]) {
                    continue;
                }

                $changed = true;
            }

            if (! $changed) {
                continue;
            }

            $indexes[$key] = new Index(
                $index->getName(),
                $indexColumns,
                $index->isUnique(),
                $index->isPrimary(),
                $index->getFlags()
            );
        }

        foreach ($diff->removedIndexes as $index) {
            $indexName = $index->getName();

            if ($indexName === '') {
                continue;
            }

            unset($indexes[$indexName]);
        }

        foreach (array_merge($diff->changedIndexes, $diff->addedIndexes, $diff->renamedIndexes) as $index) {
            $indexes[$index->getName()] = $index;
        }

        return $indexes;
    }

    /**
     * @return ForeignKeyConstraint[]
     */
    private function getForeignKeysInAlteredTable(TableDiff $diff, Table $fromTable): array
    {
        $foreignKeys = $fromTable->getForeignKeys();
        $columnNames = $this->getColumnNamesInAlteredTable($diff, $fromTable);

        foreach ($foreignKeys as $key => $constraint) {
            $changed      = false;
            $localColumns = [];
            foreach ($constraint->getLocalColumnNames() as $columnName) {
                if (! isset($columnNames[$columnName])) {
                    unset($foreignKeys[$key]);
                    continue 2;
                }

                $localColumns[] = $columnNames[$columnName];
                if ($columnName === $columnNames[$columnName]) {
                    continue;
                }

                $changed = true;
            }

            if (! $changed) {
                continue;
            }

            $foreignKeys[$key] = new ForeignKeyConstraint(
                $localColumns,
                $constraint->getForeignTableName(),
                $constraint->getForeignColumnNames(),
                $constraint->getName(),
                $constraint->getOptions()
            );
        }

        foreach ($diff->removedForeignKeys as $constraint) {
            $constraintName = $constraint->getName();

            if ($constraintName === '') {
                continue;
            }

            unset($foreignKeys[$constraintName]);
        }

        foreach (array_merge($diff->changedForeignKeys, $diff->addedForeignKeys) as $constraint) {
            $foreignKeys[$constraint->getName()] = $constraint;
        }

        return $foreignKeys;
    }

    /**
     * @return Index[]
     */
    private function getPrimaryIndexInAlteredTable(TableDiff $diff, Table $fromTable): array
    {
        $primaryIndex = [];

        foreach ($this->getIndexesInAlteredTable($diff, $fromTable) as $index) {
            if (! $index->isPrimary()) {
                continue;
            }

            $primaryIndex = [$index->getName() => $index];
        }

        return $primaryIndex;
    }
}
