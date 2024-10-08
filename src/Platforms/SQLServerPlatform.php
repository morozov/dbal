<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Platforms;

use Doctrine\DBAL\Exception\ColumnLengthRequired;
use Doctrine\DBAL\Exception\InvalidLockMode;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Platform\NameNormalizer\DefaultNameNormalizer;
use Doctrine\DBAL\Platforms\Keywords\KeywordList;
use Doctrine\DBAL\Platforms\Keywords\SQLServerKeywords;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ColumnDiff;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Name;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\SQL\Builder\IdentifierBuilder\DefaultIdentifierBuilder;
use Doctrine\DBAL\SQL\Builder\LiteralBuilder\DefaultLiteralBuilder;
use InvalidArgumentException;

use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function crc32;
use function dechex;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_numeric;
use function preg_match;
use function preg_match_all;
use function sprintf;
use function strpos;
use function strtoupper;
use function substr_count;

use const PREG_OFFSET_CAPTURE;

/**
 * Provides the behavior, features and SQL dialect of the Microsoft SQL Server database platform
 * of the oldest supported version.
 */
class SQLServerPlatform extends AbstractPlatform
{
    public function __construct()
    {
        parent::__construct(
            new DefaultNameNormalizer(),
            new DefaultIdentifierBuilder('[', ']'),
            new DefaultLiteralBuilder()
        );
    }

    public function getCurrentDateSQL(): string
    {
        return $this->getConvertExpression('date', 'GETDATE()');
    }

    public function getCurrentTimeSQL(): string
    {
        return $this->getConvertExpression('time', 'GETDATE()');
    }

    /**
     * Returns an expression that converts an expression of one data type to another.
     *
     * @param string $dataType   The target native data type. Alias data types cannot be used.
     * @param string $expression The SQL expression to convert.
     */
    private function getConvertExpression(string $dataType, string $expression): string
    {
        return sprintf('CONVERT(%s, %s)', $dataType, $expression);
    }

    protected function getDateArithmeticIntervalExpression(
        string $date,
        string $operator,
        string $interval,
        string $unit
    ): string {
        $factorClause = '';

        if ($operator === '-') {
            $factorClause = '-1 * ';
        }

        return 'DATEADD(' . $unit . ', ' . $factorClause . $interval . ', ' . $date . ')';
    }

    public function getDateDiffExpression(string $date1, string $date2): string
    {
        return 'DATEDIFF(day, ' . $date2 . ',' . $date1 . ')';
    }

    /**
     * {@inheritDoc}
     *
     * Microsoft SQL Server prefers "autoincrement" identity columns
     * since sequences can only be emulated with a table.
     */
    public function prefersIdentityColumns(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     *
     * Microsoft SQL Server supports this through AUTO_INCREMENT columns.
     */
    public function supportsIdentityColumns(): bool
    {
        return true;
    }

    public function supportsReleaseSavepoints(): bool
    {
        return false;
    }

    public function supportsSchemas(): bool
    {
        return true;
    }

    public function getDefaultSchemaName(): string
    {
        return 'dbo';
    }

    public function supportsColumnCollation(): bool
    {
        return true;
    }

    public function supportsSequences(): bool
    {
        return true;
    }

    public function getAlterSequenceSQL(Sequence $sequence): string
    {
        return 'ALTER SEQUENCE ' . $sequence->getName() .
            ' INCREMENT BY ' . $sequence->getAllocationSize();
    }

    public function getCreateSequenceSQL(Sequence $sequence): string
    {
        return 'CREATE SEQUENCE ' . $sequence->getName() .
            ' START WITH ' . $sequence->getInitialValue() .
            ' INCREMENT BY ' . $sequence->getAllocationSize() .
            ' MINVALUE ' . $sequence->getInitialValue();
    }

    public function getListSequencesSQL(UnqualifiedName $databaseName): string
    {
        return 'SELECT seq.name,
                       CAST(
                           seq.increment AS VARCHAR(MAX)
                       ) AS increment, -- CAST avoids driver error for sql_variant type
                       CAST(
                           seq.start_value AS VARCHAR(MAX)
                       ) AS start_value -- CAST avoids driver error for sql_variant type
                FROM   sys.sequences AS seq';
    }

    public function getSequenceNextValSQL(Name $name): string
    {
        return 'SELECT NEXT VALUE FOR ' . $name;
    }

    public function hasNativeGuidType(): bool
    {
        return true;
    }

    public function getDropForeignKeySQL(Name $name, Name $tableName): string
    {
        return $this->getDropConstraintSQL($name, $tableName);
    }

    public function getDropIndexSQL(Name $name, Name $tableName): string
    {
        return 'DROP INDEX ' . $this->buildNameLiteral($name) . ' ON ' . $this->buildNameLiteral($tableName);
    }

    /**
     * {@inheritDoc}
     */
    protected function _getCreateTableSQL(Name $name, array $columns, array $options = []): array
    {
        $defaultConstraintsSql = [];
        $commentsSql           = [];

        $tableComment = $options['comment'] ?? null;
        if ($tableComment !== null) {
            $commentsSql[] = $this->getCommentOnTableSQL($name, $tableComment);
        }

        // @todo does other code breaks because of this?
        // force primary keys to be not null
        foreach ($columns as &$column) {
            if (! empty($column['primary'])) {
                $column['notnull'] = true;
            }

            // Build default constraints SQL statements.
            if (isset($column['default'])) {
                $defaultConstraintsSql[] = 'ALTER TABLE ' . $name .
                    ' ADD' . $this->getDefaultConstraintDeclarationSQL($name, $column);
            }

            if (empty($column['comment']) && ! is_numeric($column['comment'])) {
                continue;
            }

            $commentsSql[] = $this->getCreateColumnCommentSQL($name, $column['name'], $column['comment']);
        }

        $columnListSql = $this->getColumnDeclarationListSQL($columns);

        if (isset($options['uniqueConstraints']) && ! empty($options['uniqueConstraints'])) {
            foreach ($options['uniqueConstraints'] as $definition) {
                $columnListSql .= ', ' . $this->getUniqueConstraintDeclarationSQL($definition);
            }
        }

        if (isset($options['primary']) && ! empty($options['primary'])) {
            $flags = '';
            if (isset($options['primary_index']) && $options['primary_index']->hasFlag('nonclustered')) {
                $flags = ' NONCLUSTERED';
            }

            $columnListSql .= ', PRIMARY KEY' . $flags
                . ' (' . implode(', ', array_unique(array_values($options['primary']))) . ')';
        }

        $query = 'CREATE TABLE ' . $name . ' (' . $columnListSql;

        $check = $this->getCheckDeclarationSQL($columns);
        if (! empty($check)) {
            $query .= ', ' . $check;
        }

        $query .= ')';

        $sql = [$query];

        if (isset($options['indexes']) && ! empty($options['indexes'])) {
            foreach ($options['indexes'] as $index) {
                $sql[] = $this->getCreateIndexSQL($index, $name);
            }
        }

        if (isset($options['foreignKeys'])) {
            foreach ((array) $options['foreignKeys'] as $definition) {
                $sql[] = $this->getCreateForeignKeySQL($definition, $name);
            }
        }

        return array_merge($sql, $commentsSql, $defaultConstraintsSql);
    }

    public function getCreatePrimaryKeySQL(Index $index, Name $table): string
    {
        $sql = 'ALTER TABLE ' . $table . ' ADD PRIMARY KEY';

        if ($index->hasFlag('nonclustered')) {
            $sql .= ' NONCLUSTERED';
        }

        return $sql . ' (' . $this->getIndexFieldDeclarationListSQL($index) . ')';
    }

    /**
     * Returns the SQL statement for creating a column comment.
     *
     * SQL Server does not support native column comments,
     * therefore the extended properties functionality is used
     * as a workaround to store them.
     * The property name used to store column comments is "MS_Description"
     * which provides compatibility with SQL Server Management Studio,
     * as column comments are stored in the same property there when
     * specifying a column's "Description" attribute.
     *
     * @param string $tableName  The quoted table name to which the column belongs.
     * @param string $columnName The quoted column name to create the comment for.
     * @param string $comment    The column's comment.
     */
    protected function getCreateColumnCommentSQL(string $tableName, string $columnName, string $comment): string
    {
        if (strpos($tableName, '.') !== false) {
            [$schemaSQL, $tableSQL] = explode('.', $tableName);
            $schemaSQL              = $this->buildNameLiteral($schemaSQL);
            $tableSQL               = $this->buildNameLiteral($tableSQL);
        } else {
            $schemaSQL = "'dbo'";
            $tableSQL  = $this->buildNameLiteral($tableName);
        }

        return $this->getAddExtendedPropertySQL(
            'MS_Description',
            $comment,
            'SCHEMA',
            $schemaSQL,
            'TABLE',
            $tableSQL,
            'COLUMN',
            $columnName
        );
    }

    /**
     * Returns the SQL snippet for declaring a default constraint.
     *
     * @param Name   $tableName Name of the table to return the default constraint declaration for.
     * @param Column $column    Column definition.
     *
     * @throws InvalidArgumentException
     */
    protected function getDefaultConstraintDeclarationSQL(Name $tableName, Column $column): string
    {
        $columnName = $column->getName();

        return ' CONSTRAINT ' .
            $this->generateDefaultConstraintName($tableName, $columnName) .
            $this->getDefaultValueDeclarationSQL($column->toArray()) .
            ' FOR ' . $this->buildNameIdentifier($columnName);
    }

    public function getCreateIndexSQL(Index $index, Name $tableName): string
    {
        $constraint = parent::getCreateIndexSQL($index, $tableName);

        if ($index->isUnique() && ! $index->isPrimary()) {
            $constraint = $this->_appendUniqueConstraintDefinition($constraint, $index);
        }

        return $constraint;
    }

    protected function getCreateIndexSQLFlags(Index $index): string
    {
        $type = '';
        if ($index->isUnique()) {
            $type .= 'UNIQUE ';
        }

        if ($index->hasFlag('clustered')) {
            $type .= 'CLUSTERED ';
        } elseif ($index->hasFlag('nonclustered')) {
            $type .= 'NONCLUSTERED ';
        }

        return $type;
    }

    /**
     * Extend unique key constraint with required filters
     */
    private function _appendUniqueConstraintDefinition(string $sql, Index $index): string
    {
        $fields = [];

        foreach ($index->getQuotedColumns($this) as $field) {
            $fields[] = $field . ' IS NOT NULL';
        }

        return $sql . ' WHERE ' . implode(' AND ', $fields);
    }

    /**
     * {@inheritDoc}
     */
    public function getAlterTableSQL(TableDiff $diff): array
    {
        $queryParts  = [];
        $sql         = [];
        $columnSql   = [];
        $commentsSql = [];

        foreach ($diff->addedColumns as $column) {
            if ($this->onSchemaAlterTableAddColumn($column, $diff, $columnSql)) {
                continue;
            }

            $columnDef    = $column->toArray();
            $addColumnSql = 'ADD ' . $this->getColumnDeclarationSQL($column->getName(), $columnDef);
            if (isset($columnDef['default'])) {
                $addColumnSql .= ' CONSTRAINT ' .
                    $this->generateDefaultConstraintName($diff->name, $column->getName()) .
                    $this->getDefaultValueDeclarationSQL($columnDef);
            }

            $queryParts[] = $addColumnSql;

            $comment = $this->getColumnComment($column);

            if ($comment === '') {
                continue;
            }

            $commentsSql[] = $this->getCreateColumnCommentSQL(
                $diff->name,
                $column->getName(),
                $comment
            );
        }

        foreach ($diff->removedColumns as $column) {
            if ($this->onSchemaAlterTableRemoveColumn($column, $diff, $columnSql)) {
                continue;
            }

            $queryParts[] = 'DROP COLUMN ' . $column->getName();
        }

        foreach ($diff->changedColumns as $columnDiff) {
            if ($this->onSchemaAlterTableChangeColumn($columnDiff, $diff, $columnSql)) {
                continue;
            }

            $column     = $columnDiff->column;
            $comment    = $this->getColumnComment($column);
            $hasComment = $comment !== '';

            $fromComment    = $this->getColumnComment($columnDiff->fromColumn);
            $hasFromComment = $fromComment !== '';

            if ($hasFromComment && $hasComment && $fromComment !== $comment) {
                $commentsSql[] = $this->getAlterColumnCommentSQL($diff->name, $column->getName(), $comment);
            } elseif ($hasFromComment && ! $hasComment) {
                $commentsSql[] = $this->getDropColumnCommentSQL($diff->name, $column->getName());
            } elseif (! $hasFromComment && $hasComment) {
                $commentsSql[] = $this->getCreateColumnCommentSQL($diff->name, $column->getName(), $comment);
            }

            // Do not add query part if only comment has changed.
            if ($columnDiff->hasChanged('comment') && count($columnDiff->changedProperties) === 1) {
                continue;
            }

            $requireDropDefaultConstraint = $this->alterColumnRequiresDropDefaultConstraint($columnDiff);

            if ($requireDropDefaultConstraint) {
                $queryParts[] = $this->getAlterTableDropDefaultConstraintClause(
                    $diff->name,
                    $columnDiff->oldColumnName
                );
            }

            $columnDef = $column->toArray();

            $queryParts[] = 'ALTER COLUMN ' .
                    $this->getColumnDeclarationSQL($column->getName(), $columnDef);

            if (
                ! isset($columnDef['default'])
                || (! $requireDropDefaultConstraint && ! $columnDiff->hasChanged('default'))
            ) {
                continue;
            }

            $queryParts[] = $this->getAlterTableAddDefaultConstraintClause($diff->name, $column);
        }

        foreach ($diff->renamedColumns as $oldColumnName => $column) {
            if ($this->onSchemaAlterTableRenameColumn($oldColumnName, $column, $diff, $columnSql)) {
                continue;
            }

            $sql[] = "sp_rename '" .
                $diff->getName() . '.' . $oldColumnName .
                "', '" . $column->getName() . "', 'COLUMN'";

            // Recreate default constraint with new column name if necessary (for future reference).
            if ($column->getDefault() === null) {
                continue;
            }

            $queryParts[] = $this->getAlterTableDropDefaultConstraintClause($diff->name, $oldColumnName);
            $queryParts[] = $this->getAlterTableAddDefaultConstraintClause($diff->name, $column);
        }

        $tableSql = [];

        if ($this->onSchemaAlterTable($diff, $tableSql)) {
            return array_merge($tableSql, $columnSql);
        }

        foreach ($queryParts as $query) {
            $sql[] = 'ALTER TABLE ' . $diff->getName($this)->getName() . ' ' . $query;
        }

        $sql = array_merge($sql, $commentsSql);

        $newName = $diff->getNewName();

        if ($newName !== null) {
            $sql[] = "sp_rename '" . $diff->getName() . "', '" . $newName . "'";

            /**
             * Rename table's default constraints names
             * to match the new table name.
             * This is necessary to ensure that the default
             * constraints can be referenced in future table
             * alterations as the table name is encoded in
             * default constraints' names.
             */
            $sql[] = "DECLARE @sql NVARCHAR(MAX) = N''; " .
                "SELECT @sql += N'EXEC sp_rename N''' + dc.name + ''', N''' " .
                "+ REPLACE(dc.name, '" . $this->generateIdentifierName($diff->getName()) . "', " .
                "'" . $this->generateIdentifierName($newName) . "') + ''', ''OBJECT'';' " .
                'FROM sys.default_constraints dc ' .
                'JOIN sys.tables tbl ON dc.parent_object_id = tbl.object_id ' .
                'WHERE tbl.name = ' . $this->buildNameLiteral($newName) . ';' .
                'EXEC sp_executesql @sql';
        }

        $sql = array_merge(
            $this->getPreAlterTableIndexForeignKeySQL($diff),
            $sql,
            $this->getPostAlterTableIndexForeignKeySQL($diff)
        );

        return array_merge($sql, $tableSql, $columnSql);
    }

    /**
     * Returns the SQL clause for adding a default constraint in an ALTER TABLE statement.
     *
     * @param Name   $tableName The name of the table to generate the clause for.
     * @param Column $column    The column to generate the clause for.
     */
    private function getAlterTableAddDefaultConstraintClause(Name $tableName, Column $column): string
    {
        return 'ADD' . $this->getDefaultConstraintDeclarationSQL($tableName, $column);
    }

    /**
     * Returns the SQL clause for dropping an existing default constraint in an ALTER TABLE statement.
     *
     * @param Name $tableName  The name of the table to generate the clause for.
     * @param Name $columnName The name of the column to generate the clause for.
     */
    private function getAlterTableDropDefaultConstraintClause(Name $tableName, Name $columnName): string
    {
        return 'DROP CONSTRAINT ' . $this->generateDefaultConstraintName($tableName, $columnName);
    }

    /**
     * Checks whether a column alteration requires dropping its default constraint first.
     *
     * Different to other database vendors SQL Server implements column default values
     * as constraints and therefore changes in a column's default value as well as changes
     * in a column's type require dropping the default constraint first before being to
     * alter the particular column to the new definition.
     */
    private function alterColumnRequiresDropDefaultConstraint(ColumnDiff $columnDiff): bool
    {
        // We only need to drop an existing default constraint if we know the
        // column was defined with a default value before.
        if ($columnDiff->fromColumn->getDefault() === null) {
            return false;
        }

        // We need to drop an existing default constraint if the column was
        // defined with a default value before and it has changed.
        if ($columnDiff->hasChanged('default')) {
            return true;
        }

        // We need to drop an existing default constraint if the column was
        // defined with a default value before and the native column type has changed.
        return $columnDiff->hasChanged('type') || $columnDiff->hasChanged('fixed');
    }

    /**
     * Returns the SQL statement for altering a column comment.
     *
     * SQL Server does not support native column comments,
     * therefore the extended properties functionality is used
     * as a workaround to store them.
     * The property name used to store column comments is "MS_Description"
     * which provides compatibility with SQL Server Management Studio,
     * as column comments are stored in the same property there when
     * specifying a column's "Description" attribute.
     *
     * @param string $tableName  The quoted table name to which the column belongs.
     * @param string $columnName The quoted column name to alter the comment for.
     * @param string $comment    The column's comment.
     */
    protected function getAlterColumnCommentSQL(string $tableName, string $columnName, string $comment): string
    {
        if (strpos($tableName, '.') !== false) {
            [$schemaSQL, $tableSQL] = explode('.', $tableName);
            $schemaSQL              = $this->buildNameLiteral($schemaSQL);
            $tableSQL               = $this->buildNameLiteral($tableSQL);
        } else {
            $schemaSQL = "'dbo'";
            $tableSQL  = $this->buildNameLiteral($tableName);
        }

        return $this->getUpdateExtendedPropertySQL(
            'MS_Description',
            $comment,
            'SCHEMA',
            $schemaSQL,
            'TABLE',
            $tableSQL,
            'COLUMN',
            $columnName
        );
    }

    /**
     * Returns the SQL statement for dropping a column comment.
     *
     * SQL Server does not support native column comments,
     * therefore the extended properties functionality is used
     * as a workaround to store them.
     * The property name used to store column comments is "MS_Description"
     * which provides compatibility with SQL Server Management Studio,
     * as column comments are stored in the same property there when
     * specifying a column's "Description" attribute.
     *
     * @param string $tableName  The quoted table name to which the column belongs.
     * @param string $columnName The quoted column name to drop the comment for.
     */
    protected function getDropColumnCommentSQL(string $tableName, string $columnName): string
    {
        if (strpos($tableName, '.') !== false) {
            [$schemaSQL, $tableSQL] = explode('.', $tableName);
            $schemaSQL              = $this->buildNameLiteral($schemaSQL);
            $tableSQL               = $this->buildNameLiteral($tableSQL);
        } else {
            $schemaSQL = "'dbo'";
            $tableSQL  = $this->buildNameLiteral($tableName);
        }

        return $this->getDropExtendedPropertySQL(
            'MS_Description',
            'SCHEMA',
            $schemaSQL,
            'TABLE',
            $tableSQL,
            'COLUMN',
            $columnName
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function getRenameIndexSQL(Name $oldIndexName, Index $index, Name $tableName): array
    {
        return [sprintf(
            "EXEC sp_rename N'%s.%s', N'%s', N'INDEX'",
            $this->buildNameIdentifier($tableName),
            $this->buildNameIdentifier($oldIndexName),
            $this->buildNameIdentifier($index->getName())
        ),
        ];
    }

    /**
     * Returns the SQL statement for adding an extended property to a database object.
     *
     * @link http://msdn.microsoft.com/en-us/library/ms180047%28v=sql.90%29.aspx
     *
     * @param string      $name       The name of the property to add.
     * @param string|null $value      The value of the property to add.
     * @param string|null $level0Type The type of the object at level 0 the property belongs to.
     * @param string|null $level0Name The name of the object at level 0 the property belongs to.
     * @param string|null $level1Type The type of the object at level 1 the property belongs to.
     * @param string|null $level1Name The name of the object at level 1 the property belongs to.
     * @param string|null $level2Type The type of the object at level 2 the property belongs to.
     * @param string|null $level2Name The name of the object at level 2 the property belongs to.
     */
    protected function getAddExtendedPropertySQL(
        string $name,
        ?string $value = null,
        ?string $level0Type = null,
        ?string $level0Name = null,
        ?string $level1Type = null,
        ?string $level1Name = null,
        ?string $level2Type = null,
        ?string $level2Name = null
    ): string {
        return 'EXEC sp_addextendedproperty ' .
            'N' . $this->buildNameLiteral($name) . ', N' . $this->buildNameLiteral((string) $value) . ', ' .
            'N' . $this->buildNameLiteral((string) $level0Type) . ', ' . $level0Name . ', ' .
            'N' . $this->buildNameLiteral((string) $level1Type) . ', ' . $level1Name . ', ' .
            'N' . $this->buildNameLiteral((string) $level2Type) . ', ' . $level2Name;
    }

    /**
     * Returns the SQL statement for dropping an extended property from a database object.
     *
     * @link http://technet.microsoft.com/en-gb/library/ms178595%28v=sql.90%29.aspx
     *
     * @param string      $name       The name of the property to drop.
     * @param string|null $level0Type The type of the object at level 0 the property belongs to.
     * @param string|null $level0Name The name of the object at level 0 the property belongs to.
     * @param string|null $level1Type The type of the object at level 1 the property belongs to.
     * @param string|null $level1Name The name of the object at level 1 the property belongs to.
     * @param string|null $level2Type The type of the object at level 2 the property belongs to.
     * @param string|null $level2Name The name of the object at level 2 the property belongs to.
     */
    protected function getDropExtendedPropertySQL(
        string $name,
        ?string $level0Type = null,
        ?string $level0Name = null,
        ?string $level1Type = null,
        ?string $level1Name = null,
        ?string $level2Type = null,
        ?string $level2Name = null
    ): string {
        return 'EXEC sp_dropextendedproperty ' .
            'N' . $this->buildNameLiteral($name) . ', ' .
            'N' . $this->buildNameLiteral((string) $level0Type) . ', ' . $level0Name . ', ' .
            'N' . $this->buildNameLiteral((string) $level1Type) . ', ' . $level1Name . ', ' .
            'N' . $this->buildNameLiteral((string) $level2Type) . ', ' . $level2Name;
    }

    /**
     * Returns the SQL statement for updating an extended property of a database object.
     *
     * @link http://msdn.microsoft.com/en-us/library/ms186885%28v=sql.90%29.aspx
     *
     * @param string      $name       The name of the property to update.
     * @param string|null $value      The value of the property to update.
     * @param string|null $level0Type The type of the object at level 0 the property belongs to.
     * @param string|null $level0Name The name of the object at level 0 the property belongs to.
     * @param string|null $level1Type The type of the object at level 1 the property belongs to.
     * @param string|null $level1Name The name of the object at level 1 the property belongs to.
     * @param string|null $level2Type The type of the object at level 2 the property belongs to.
     * @param string|null $level2Name The name of the object at level 2 the property belongs to.
     */
    protected function getUpdateExtendedPropertySQL(
        string $name,
        ?string $value = null,
        ?string $level0Type = null,
        ?string $level0Name = null,
        ?string $level1Type = null,
        ?string $level1Name = null,
        ?string $level2Type = null,
        ?string $level2Name = null
    ): string {
        return 'EXEC sp_updateextendedproperty ' .
            'N' . $this->buildNameLiteral($name) . ', N' . $this->buildNameLiteral((string) $value) . ', ' .
            'N' . $this->buildNameLiteral((string) $level0Type) . ', ' . $level0Name . ', ' .
            'N' . $this->buildNameLiteral((string) $level1Type) . ', ' . $level1Name . ', ' .
            'N' . $this->buildNameLiteral((string) $level2Type) . ', ' . $level2Name;
    }

    public function getEmptyIdentityInsertSQL(Name $tableName, Name $identityColumnName): string
    {
        return 'INSERT INTO ' . $this->buildNameIdentifier($tableName) . ' DEFAULT VALUES';
    }

    public function getListTablesSQL(): string
    {
        // "sysdiagrams" table must be ignored as it's internal SQL Server table for Database Diagrams
        // Category 2 must be ignored as it is "MS SQL Server 'pseudo-system' object[s]" for replication
        return 'SELECT name, SCHEMA_NAME (uid) AS schema_name FROM sysobjects'
            . " WHERE type = 'U' AND name != 'sysdiagrams' AND category != 2 ORDER BY name";
    }

    public function getListTableColumnsSQL(Name $name, ?UnqualifiedName $databaseName = null): string
    {
        return "SELECT    col.name,
                          type.name AS type,
                          col.max_length AS length,
                          ~col.is_nullable AS notnull,
                          def.definition AS [default],
                          col.scale,
                          col.precision,
                          col.is_identity AS autoincrement,
                          col.collation_name AS collation,
                          CAST(prop.value AS NVARCHAR(MAX)) AS comment -- CAST avoids driver error for sql_variant type
                FROM      sys.columns AS col
                JOIN      sys.types AS type
                ON        col.user_type_id = type.user_type_id
                JOIN      sys.objects AS obj
                ON        col.object_id = obj.object_id
                JOIN      sys.schemas AS scm
                ON        obj.schema_id = scm.schema_id
                LEFT JOIN sys.default_constraints def
                ON        col.default_object_id = def.object_id
                AND       col.object_id = def.parent_object_id
                LEFT JOIN sys.extended_properties AS prop
                ON        obj.object_id = prop.major_id
                AND       col.column_id = prop.minor_id
                AND       prop.name = 'MS_Description'
                WHERE     obj.type = 'U'
                AND       " . $this->getTableWhereClause($name, 'scm.name', 'obj.name');
    }

    public function getListTableForeignKeysSQL(Name $name, ?UnqualifiedName $databaseName = null): string
    {
        return 'SELECT f.name AS ForeignKey,
                SCHEMA_NAME (f.SCHEMA_ID) AS SchemaName,
                OBJECT_NAME (f.parent_object_id) AS TableName,
                COL_NAME (fc.parent_object_id,fc.parent_column_id) AS ColumnName,
                SCHEMA_NAME (o.SCHEMA_ID) ReferenceSchemaName,
                OBJECT_NAME (f.referenced_object_id) AS ReferenceTableName,
                COL_NAME(fc.referenced_object_id,fc.referenced_column_id) AS ReferenceColumnName,
                f.delete_referential_action_desc,
                f.update_referential_action_desc
                FROM sys.foreign_keys AS f
                INNER JOIN sys.foreign_key_columns AS fc
                INNER JOIN sys.objects AS o ON o.OBJECT_ID = fc.referenced_object_id
                ON f.OBJECT_ID = fc.constraint_object_id
                WHERE ' .
                $this->getTableWhereClause($name, 'SCHEMA_NAME (f.schema_id)', 'OBJECT_NAME (f.parent_object_id)') .
                ' ORDER BY fc.constraint_column_id';
    }

    public function getListTableIndexesSQL(Name $name, ?UnqualifiedName $databaseName = null): string
    {
        return "SELECT idx.name AS key_name,
                       col.name AS column_name,
                       ~idx.is_unique AS non_unique,
                       idx.is_primary_key AS [primary],
                       CASE idx.type
                           WHEN '1' THEN 'clustered'
                           WHEN '2' THEN 'nonclustered'
                           ELSE NULL
                       END AS flags
                FROM sys.tables AS tbl
                JOIN sys.schemas AS scm ON tbl.schema_id = scm.schema_id
                JOIN sys.indexes AS idx ON tbl.object_id = idx.object_id
                JOIN sys.index_columns AS idxcol ON idx.object_id = idxcol.object_id AND idx.index_id = idxcol.index_id
                JOIN sys.columns AS col ON idxcol.object_id = col.object_id AND idxcol.column_id = col.column_id
                WHERE " . $this->getTableWhereClause($name, 'scm.name', 'tbl.name') . '
                ORDER BY idx.index_id ASC, idxcol.key_ordinal ASC';
    }

    public function getListViewsSQL(UnqualifiedName $databaseName): string
    {
        return "SELECT name, definition FROM sysobjects
                    INNER JOIN sys.sql_modules ON sysobjects.id = sys.sql_modules.object_id
                WHERE type = 'V' ORDER BY name";
    }

    /**
     * Returns the where clause to filter schema and table name in a query.
     *
     * @param Name   $name         The fully qualified name of the table.
     * @param string $schemaColumn The name of the column to compare the schema to in the where clause.
     * @param string $tableColumn  The name of the column to compare the table to in the where clause.
     */
    private function getTableWhereClause(Name $name, string $schemaColumn, string $tableColumn): string
    {
        if (strpos($name, '.') !== false) {
            [$schema, $name] = explode('.', $name);
            $schema          = $this->buildNameLiteral($schema);
            $name            = $this->buildNameLiteral($name);
        } else {
            $schema = 'SCHEMA_NAME()';
            $name   = $this->buildNameLiteral($name);
        }

        return sprintf('(%s = %s AND %s = %s)', $tableColumn, $name, $schemaColumn, $schema);
    }

    public function getLocateExpression(string $string, string $substring, ?string $start = null): string
    {
        if ($start === null) {
            return sprintf('CHARINDEX(%s, %s)', $substring, $string);
        }

        return sprintf('CHARINDEX(%s, %s, %s)', $substring, $string, $start);
    }

    public function getModExpression(string $dividend, string $divisor): string
    {
        return $dividend . ' % ' . $divisor;
    }

    public function getTrimExpression(string $str, int $mode = TrimMode::UNSPECIFIED, ?string $char = null): string
    {
        if (! in_array($mode, [TrimMode::UNSPECIFIED, TrimMode::LEADING, TrimMode::TRAILING, TrimMode::BOTH], true)) {
            throw new InvalidArgumentException(
                sprintf('The value of $mode is expected to be one of the TrimMode constants, %d given', $mode)
            );
        }

        if ($char === null) {
            switch ($mode) {
                case TrimMode::LEADING:
                    return 'LTRIM(' . $str . ')';

                case TrimMode::TRAILING:
                    return 'RTRIM(' . $str . ')';

                default:
                    return 'LTRIM(RTRIM(' . $str . '))';
            }
        }

        $pattern = "'%[^' + " . $char . " + ']%'";

        if ($mode === TrimMode::LEADING) {
            return 'stuff(' . $str . ', 1, patindex(' . $pattern . ', ' . $str . ') - 1, null)';
        }

        if ($mode === TrimMode::TRAILING) {
            return 'reverse(stuff(reverse(' . $str . '), 1, '
                . 'patindex(' . $pattern . ', reverse(' . $str . ')) - 1, null))';
        }

        return 'reverse(stuff(reverse(stuff(' . $str . ', 1, patindex(' . $pattern . ', ' . $str . ') - 1, null)), 1, '
            . 'patindex(' . $pattern . ', reverse(stuff(' . $str . ', 1, patindex(' . $pattern . ', ' . $str
            . ') - 1, null))) - 1, null))';
    }

    public function getConcatExpression(string ...$string): string
    {
        return sprintf('CONCAT(%s)', implode(', ', $string));
    }

    public function getListDatabasesSQL(): string
    {
        return 'SELECT * FROM sys.databases';
    }

    public function getSubstringExpression(string $string, string $start, ?string $length = null): string
    {
        if ($length === null) {
            return sprintf('SUBSTRING(%s, %s, LEN(%s) - %s + 1)', $string, $start, $string, $start);
        }

        return sprintf('SUBSTRING(%s, %s, %s)', $string, $start, $length);
    }

    public function getLengthExpression(string $string): string
    {
        return 'LEN(' . $string . ')';
    }

    public function getCurrentDatabaseExpression(): string
    {
        return 'DB_NAME()';
    }

    public function getSetTransactionIsolationSQL(int $level): string
    {
        return 'SET TRANSACTION ISOLATION LEVEL ' . $this->_getTransactionIsolationLevelSQL($level);
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
     * {@inheritDoc}
     */
    public function getGuidTypeDeclarationSQL(array $column): string
    {
        return 'UNIQUEIDENTIFIER';
    }

    /**
     * {@inheritDoc}
     */
    public function getDateTimeTzTypeDeclarationSQL(array $column): string
    {
        return 'DATETIMEOFFSET(6)';
    }

    protected function getCharTypeDeclarationSQLSnippet(?int $length): string
    {
        $sql = 'NCHAR';

        if ($length !== null) {
            $sql .= sprintf('(%d)', $length);
        }

        return $sql;
    }

    protected function getVarcharTypeDeclarationSQLSnippet(?int $length): string
    {
        if ($length === null) {
            throw ColumnLengthRequired::new($this, 'NVARCHAR');
        }

        return sprintf('NVARCHAR(%d)', $length);
    }

    /**
     * {@inheritDoc}
     */
    public function getAsciiStringTypeDeclarationSQL(array $column): string
    {
        $length = $column['length'] ?? null;

        if (empty($column['fixed'])) {
            return parent::getVarcharTypeDeclarationSQLSnippet($length);
        }

        return parent::getCharTypeDeclarationSQLSnippet($length);
    }

    /**
     * {@inheritDoc}
     */
    public function getClobTypeDeclarationSQL(array $column): string
    {
        return 'VARCHAR(MAX)';
    }

    /**
     * {@inheritDoc}
     */
    protected function _getCommonIntegerTypeDeclarationSQL(array $column): string
    {
        return ! empty($column['autoincrement']) ? ' IDENTITY' : '';
    }

    /**
     * {@inheritDoc}
     */
    public function getDateTimeTypeDeclarationSQL(array $column): string
    {
        // 3 - microseconds precision length
        // http://msdn.microsoft.com/en-us/library/ms187819.aspx
        return 'DATETIME2(6)';
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
        return 'TIME(0)';
    }

    /**
     * {@inheritDoc}
     */
    public function getBooleanTypeDeclarationSQL(array $column): string
    {
        return 'BIT';
    }

    protected function doModifyLimitQuery(string $query, ?int $limit, int $offset): string
    {
        if ($limit === null && $offset <= 0) {
            return $query;
        }

        if ($this->shouldAddOrderBy($query)) {
            if (preg_match('/^SELECT\s+DISTINCT/im', $query) > 0) {
                // SQL Server won't let us order by a non-selected column in a DISTINCT query,
                // so we have to do this madness. This says, order by the first column in the
                // result. SQL Server's docs say that a nonordered query's result order is non-
                // deterministic anyway, so this won't do anything that a bunch of update and
                // deletes to the table wouldn't do anyway.
                $query .= ' ORDER BY 1';
            } else {
                // In another DBMS, we could do ORDER BY 0, but SQL Server gets angry if you
                // use constant expressions in the order by list.
                $query .= ' ORDER BY (SELECT 0)';
            }
        }

        // This looks somewhat like MYSQL, but limit/offset are in inverse positions
        // Supposedly SQL:2008 core standard.
        // Per TSQL spec, FETCH NEXT n ROWS ONLY is not valid without OFFSET n ROWS.
        $query .= sprintf(' OFFSET %d ROWS', $offset);

        if ($limit !== null) {
            $query .= sprintf(' FETCH NEXT %d ROWS ONLY', $limit);
        }

        return $query;
    }

    /**
     * {@inheritDoc}
     */
    public function convertBooleans($item)
    {
        if (is_array($item)) {
            foreach ($item as $key => $value) {
                if (! is_bool($value) && ! is_numeric($value)) {
                    continue;
                }

                $item[$key] = (int) (bool) $value;
            }
        } elseif (is_bool($item) || is_numeric($item)) {
            $item = (int) (bool) $item;
        }

        return $item;
    }

    public function getCreateTemporaryTableSnippetSQL(): string
    {
        return 'CREATE TABLE';
    }

    public function getTemporaryTableName(Name $tableName): Name
    {
        return '#' . $tableName;
    }

    public function getDateTimeFormatString(): string
    {
        return 'Y-m-d H:i:s.u';
    }

    public function getDateFormatString(): string
    {
        return 'Y-m-d';
    }

    public function getTimeFormatString(): string
    {
        return 'H:i:s';
    }

    public function getDateTimeTzFormatString(): string
    {
        return 'Y-m-d H:i:s.u P';
    }

    protected function initializeDoctrineTypeMappings(): void
    {
        $this->doctrineTypeMapping = [
            'bigint'           => 'bigint',
            'binary'           => 'binary',
            'bit'              => 'boolean',
            'blob'             => 'blob',
            'char'             => 'string',
            'date'             => 'date',
            'datetime'         => 'datetime',
            'datetime2'        => 'datetime',
            'datetimeoffset'   => 'datetimetz',
            'decimal'          => 'decimal',
            'double'           => 'float',
            'double precision' => 'float',
            'float'            => 'float',
            'image'            => 'blob',
            'int'              => 'integer',
            'money'            => 'integer',
            'nchar'            => 'string',
            'ntext'            => 'text',
            'numeric'          => 'decimal',
            'nvarchar'         => 'string',
            'real'             => 'float',
            'smalldatetime'    => 'datetime',
            'smallint'         => 'smallint',
            'smallmoney'       => 'integer',
            'text'             => 'text',
            'time'             => 'time',
            'tinyint'          => 'smallint',
            'uniqueidentifier' => 'guid',
            'varbinary'        => 'binary',
            'varchar'          => 'string',
        ];
    }

    public function createSavePoint(Name $name): string
    {
        return 'SAVE TRANSACTION ' . $this->buildNameIdentifier($name);
    }

    public function releaseSavePoint(Name $name): string
    {
        return '';
    }

    public function rollBackSavePoint(Name $name): string
    {
        return 'ROLLBACK TRANSACTION ' . $this->buildNameIdentifier($name);
    }

    public function getForeignKeyReferentialActionSQL(string $action): string
    {
        // RESTRICT is not supported, therefore falling back to NO ACTION.
        if (strtoupper($action) === 'RESTRICT') {
            return 'NO ACTION';
        }

        return parent::getForeignKeyReferentialActionSQL($action);
    }

    public function appendLockHint(string $fromClause, int $lockMode): string
    {
        switch ($lockMode) {
            case LockMode::NONE:
            case LockMode::OPTIMISTIC:
                return $fromClause;

            case LockMode::PESSIMISTIC_READ:
                return $fromClause . ' WITH (HOLDLOCK, ROWLOCK)';

            case LockMode::PESSIMISTIC_WRITE:
                return $fromClause . ' WITH (UPDLOCK, ROWLOCK)';

            default:
                throw InvalidLockMode::fromLockMode($lockMode);
        }
    }

    public function getForUpdateSQL(): string
    {
        return ' ';
    }

    protected function createReservedKeywordsList(): KeywordList
    {
        return new SQLServerKeywords();
    }

    public function getTruncateTableSQL(Name $name, bool $cascade = false): string
    {
        return 'TRUNCATE TABLE ' . $this->buildNameIdentifier($name);
    }

    /**
     * {@inheritDoc}
     */
    public function getBlobTypeDeclarationSQL(array $column): string
    {
        return 'VARBINARY(MAX)';
    }

    /**
     * {@inheritdoc}
     *
     * Modifies column declaration order as it differs in Microsoft SQL Server.
     */
    public function getColumnDeclarationSQL(Name $name, array $column): string
    {
        if (isset($column['columnDefinition'])) {
            $columnDef = $this->getCustomTypeDeclarationSQL($column);
        } else {
            $collation = ! empty($column['collation']) ?
                ' ' . $this->getColumnCollationDeclarationSQL($column['collation']) : '';

            $notnull = ! empty($column['notnull']) ? ' NOT NULL' : '';

            $unique = ! empty($column['unique']) ? ' UNIQUE' : '';

            $check = ! empty($column['check']) ?
                ' ' . $column['check'] : '';

            $typeDecl  = $column['type']->getSQLDeclaration($column, $this);
            $columnDef = $typeDecl . $collation . $notnull . $unique . $check;
        }

        return $this->buildNameIdentifier($name) . ' ' . $columnDef;
    }

    public function columnsEqual(Column $column1, Column $column2): bool
    {
        if (! parent::columnsEqual($column1, $column2)) {
            return false;
        }

        return $this->getDefaultValueDeclarationSQL($column1->toArray())
            === $this->getDefaultValueDeclarationSQL($column2->toArray());
    }

    protected function getLikeWildcardCharacters(): string
    {
        return parent::getLikeWildcardCharacters() . '[]^';
    }

    /**
     * Returns a unique default constraint name for a table and column.
     *
     * @param Name $tableName  Name of the table to generate the unique default constraint name for.
     * @param Name $columnName Name of the column in the table to generate the unique default constraint name for.
     */
    private function generateDefaultConstraintName(Name $tableName, Name $columnName): string
    {
        return 'DF_' . $this->generateIdentifierName($tableName) . '_' . $this->generateIdentifierName($columnName);
    }

    /**
     * Returns a hash value for a given identifier.
     *
     * @param Name $name Name to generate a hash value for.
     */
    private function generateIdentifierName(Name $name): string
    {
        return strtoupper(dechex(crc32($name)));
    }

    protected function getCommentOnTableSQL(Name $name, string $comment): string
    {
        return sprintf(
            <<<'SQL'
                EXEC sys.sp_addextendedproperty @name=N'MS_Description',
                  @value=N%s, @level0type=N'SCHEMA', @level0name=N'dbo',
                  @level1type=N'TABLE', @level1name=N%s
                SQL
            ,
            $this->quoteStringLiteral($comment),
            $this->buildNameLiteral($name)
        );
    }

    public function getListTableMetadataSQL(Name $name): string
    {
        return sprintf(
            <<<'SQL'
                SELECT
                  p.value AS [table_comment]
                FROM
                  sys.tables AS tbl
                  INNER JOIN sys.extended_properties AS p ON p.major_id=tbl.object_id AND p.minor_id=0 AND p.class=1
                WHERE
                  (tbl.name=N%s and SCHEMA_NAME(tbl.schema_id)=N'dbo' and p.name=N'MS_Description')
                SQL
            ,
            $this->buildNameLiteral($name)
        );
    }

    private function shouldAddOrderBy(string $query): bool
    {
        // Find the position of the last instance of ORDER BY and ensure it is not within a parenthetical statement
        // but can be in a newline
        $matches      = [];
        $matchesCount = preg_match_all('/[\\s]+order\\s+by\\s/im', $query, $matches, PREG_OFFSET_CAPTURE);
        if ($matchesCount === 0) {
            return true;
        }

        // ORDER BY instance may be in a subquery after ORDER BY
        // e.g. SELECT col1 FROM test ORDER BY (SELECT col2 from test ORDER BY col2)
        // if in the searched query ORDER BY clause was found where
        // number of open parentheses after the occurrence of the clause is equal to
        // number of closed brackets after the occurrence of the clause,
        // it means that ORDER BY is included in the query being checked
        while ($matchesCount > 0) {
            $orderByPos          = $matches[0][--$matchesCount][1];
            $openBracketsCount   = substr_count($query, '(', $orderByPos);
            $closedBracketsCount = substr_count($query, ')', $orderByPos);
            if ($openBracketsCount === $closedBracketsCount) {
                return false;
            }
        }

        return true;
    }
}
