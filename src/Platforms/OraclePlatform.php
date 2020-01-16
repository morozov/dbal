<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Platforms;

use Doctrine\DBAL\Exception\ColumnLengthRequired;
use Doctrine\DBAL\Platforms\Keywords\KeywordList;
use Doctrine\DBAL\Platforms\Keywords\OracleKeywords;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Identifier;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\TransactionIsolationLevel;
use Doctrine\DBAL\Types\BinaryType;
use InvalidArgumentException;

use function array_merge;
use function count;
use function explode;
use function implode;
use function sprintf;
use function str_contains;
use function strlen;
use function strtoupper;
use function substr;

/**
 * OraclePlatform.
 */
class OraclePlatform extends AbstractPlatform
{
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
            return sprintf('INSTR(%s, %s)', $string, $substring);
        }

        return sprintf('INSTR(%s, %s, %s)', $string, $substring, $start);
    }

    protected function getDateArithmeticIntervalExpression(
        string $date,
        string $operator,
        string $interval,
        string $unit
    ): string {
        switch ($unit) {
            case DateIntervalUnit::MONTH:
            case DateIntervalUnit::QUARTER:
            case DateIntervalUnit::YEAR:
                switch ($unit) {
                    case DateIntervalUnit::QUARTER:
                        $interval = $this->multiplyInterval($interval, 3);
                        break;

                    case DateIntervalUnit::YEAR:
                        $interval = $this->multiplyInterval($interval, 12);
                        break;
                }

                return 'ADD_MONTHS(' . $date . ', ' . $operator . $interval . ')';

            default:
                $calculationClause = '';

                switch ($unit) {
                    case DateIntervalUnit::SECOND:
                        $calculationClause = '/24/60/60';
                        break;

                    case DateIntervalUnit::MINUTE:
                        $calculationClause = '/24/60';
                        break;

                    case DateIntervalUnit::HOUR:
                        $calculationClause = '/24';
                        break;

                    case DateIntervalUnit::WEEK:
                        $calculationClause = '*7';
                        break;
                }

                return '(' . $date . $operator . $interval . $calculationClause . ')';
        }
    }

    public function getDateDiffExpression(string $date1, string $date2): string
    {
        return sprintf('TRUNC(%s) - TRUNC(%s)', $date1, $date2);
    }

    public function getBitAndComparisonExpression(string $value1, string $value2): string
    {
        return 'BITAND(' . $value1 . ', ' . $value2 . ')';
    }

    public function getCurrentDatabaseExpression(): string
    {
        return "SYS_CONTEXT('USERENV', 'CURRENT_SCHEMA')";
    }

    public function getBitOrComparisonExpression(string $value1, string $value2): string
    {
        return '(' . $value1 . '-' .
                $this->getBitAndComparisonExpression($value1, $value2)
                . '+' . $value2 . ')';
    }

    public function getCreatePrimaryKeySQL(Index $index, string $table): string
    {
        return 'ALTER TABLE ' . $table . ' ADD CONSTRAINT ' . $index->getQuotedName($this)
            . ' PRIMARY KEY (' . $this->getIndexFieldDeclarationListSQL($index) . ')';
    }

    /**
     * {@inheritDoc}
     *
     * Need to specifiy minvalue, since start with is hidden in the system and MINVALUE <= START WITH.
     * Therefore we can use MINVALUE to be able to get a hint what START WITH was for later introspection
     * in {@see listSequences()}
     */
    public function getCreateSequenceSQL(Sequence $sequence): string
    {
        return 'CREATE SEQUENCE ' . $sequence->getQuotedName($this) .
               ' START WITH ' . $sequence->getInitialValue() .
               ' MINVALUE ' . $sequence->getInitialValue() .
               ' INCREMENT BY ' . $sequence->getAllocationSize() .
               $this->getSequenceCacheSQL($sequence);
    }

    public function getAlterSequenceSQL(Sequence $sequence): string
    {
        return 'ALTER SEQUENCE ' . $sequence->getQuotedName($this) .
               ' INCREMENT BY ' . $sequence->getAllocationSize()
               . $this->getSequenceCacheSQL($sequence);
    }

    /**
     * Cache definition for sequences
     */
    private function getSequenceCacheSQL(Sequence $sequence): string
    {
        if ($sequence->getCache() === 0) {
            return ' NOCACHE';
        }

        if ($sequence->getCache() === 1) {
            return ' NOCACHE';
        }

        if ($sequence->getCache() > 1) {
            return ' CACHE ' . $sequence->getCache();
        }

        return '';
    }

    public function getSequenceNextValSQL(string $sequence): string
    {
        return 'SELECT ' . $sequence . '.nextval FROM DUAL';
    }

    public function getSetTransactionIsolationSQL(int $level): string
    {
        return 'SET TRANSACTION ISOLATION LEVEL ' . $this->_getTransactionIsolationLevelSQL($level);
    }

    protected function _getTransactionIsolationLevelSQL(int $level): string
    {
        switch ($level) {
            case TransactionIsolationLevel::READ_UNCOMMITTED:
                return 'READ UNCOMMITTED';

            case TransactionIsolationLevel::READ_COMMITTED:
                return 'READ COMMITTED';

            case TransactionIsolationLevel::REPEATABLE_READ:
            case TransactionIsolationLevel::SERIALIZABLE:
                return 'SERIALIZABLE';

            default:
                return parent::_getTransactionIsolationLevelSQL($level);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getBooleanTypeDeclarationSQL(array $column): string
    {
        return 'NUMBER(1)';
    }

    /**
     * {@inheritDoc}
     */
    public function getIntegerTypeDeclarationSQL(array $column): string
    {
        return 'NUMBER(10)';
    }

    /**
     * {@inheritDoc}
     */
    public function getBigIntTypeDeclarationSQL(array $column): string
    {
        return 'NUMBER(20)';
    }

    /**
     * {@inheritDoc}
     */
    public function getSmallIntTypeDeclarationSQL(array $column): string
    {
        return 'NUMBER(5)';
    }

    /**
     * {@inheritDoc}
     */
    public function getDateTimeTypeDeclarationSQL(array $column): string
    {
        return 'TIMESTAMP(0)';
    }

    /**
     * {@inheritDoc}
     */
    public function getDateTimeTzTypeDeclarationSQL(array $column): string
    {
        return 'TIMESTAMP(0) WITH TIME ZONE';
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
        return 'DATE';
    }

    /**
     * {@inheritDoc}
     */
    protected function _getCommonIntegerTypeDeclarationSQL(array $column): string
    {
        return '';
    }

    protected function getVarcharTypeDeclarationSQLSnippet(?int $length): string
    {
        if ($length === null) {
            throw ColumnLengthRequired::new($this, 'VARCHAR2');
        }

        return sprintf('VARCHAR2(%d)', $length);
    }

    protected function getBinaryTypeDeclarationSQLSnippet(?int $length): string
    {
        if ($length === null) {
            throw ColumnLengthRequired::new($this, 'RAW');
        }

        return sprintf('RAW(%d)', $length);
    }

    protected function getVarbinaryTypeDeclarationSQLSnippet(?int $length): string
    {
        return $this->getBinaryTypeDeclarationSQLSnippet($length);
    }

    /**
     * {@inheritDoc}
     */
    public function getClobTypeDeclarationSQL(array $column): string
    {
        return 'CLOB';
    }

    public function getListDatabasesSQL(): string
    {
        return 'SELECT username FROM all_users';
    }

    public function getListSequencesSQL(string $database): string
    {
        return 'SELECT SEQUENCE_NAME, MIN_VALUE, INCREMENT_BY FROM SYS.ALL_SEQUENCES WHERE SEQUENCE_OWNER = '
            . $this->quoteStringLiteral($this->fooBar($database));
    }

    /**
     * {@inheritDoc}
     */
    protected function _getCreateTableSQL(string $name, array $columns, array $options = []): array
    {
        $indexes            = $options['indexes'] ?? [];
        $options['indexes'] = [];
        $sql                = parent::_getCreateTableSQL($name, $columns, $options);

        foreach ($columns as $column) {
            if (isset($column['sequence'])) {
                $sql[] = $this->getCreateSequenceSQL($column['sequence']);
            }

            if (
                empty($column['autoincrement'])
            ) {
                continue;
            }

            $sql = array_merge($sql, $this->getCreateAutoincrementSql($column['name'], $name));
        }

        foreach ($indexes as $index) {
            $sql[] = $this->getCreateIndexSQL($index, $name);
        }

        return $sql;
    }

    /**
     * {@inheritDoc}
     *
     * @link http://ezcomponents.org/docs/api/trunk/DatabaseSchema/ezcDbSchemaOracleReader.html
     */
    public function getListTableIndexesSQL(string $table, string $database): string
    {
        return sprintf(
            <<<'SQL'
SELECT
    ic.INDEX_NAME AS name,
    (
        SELECT i.INDEX_TYPE
          FROM SYS.ALL_INDEXES i
         WHERE i.OWNER = ic.INDEX_OWNER
           AND i.INDEX_NAME = ic.INDEX_NAME
    ) AS type,
    decode(
        (
            SELECT i.UNIQUENESS
              FROM SYS.ALL_INDEXES i
             WHERE i.OWNER = ic.INDEX_OWNER
               AND i.INDEX_NAME = ic.INDEX_NAME
        ),
        'NONUNIQUE',
        0,
        'UNIQUE',
        1
    ) AS is_unique,
    ic.COLUMN_NAME AS column_name,
    ic.COLUMN_POSITION AS column_pos,
    (
        SELECT c.CONSTRAINT_TYPE
          FROM SYS.ALL_CONSTRAINTS c
         WHERE c.OWNER = ic.INDEX_OWNER
           AND c.INDEX_NAME = ic.INDEX_NAME
    AND  ucon.table_name = uind_col.table_name) AS is_primary
    FROM SYS.ALL_IND_COLUMNS ic
   WHERE ic.INDEX_OWNER = %s
     AND ic.TABLE_NAME = %s
ORDER BY ic.COLUMN_POSITION
SQL
            ,
            $this->quoteStringLiteral(
                $this->fooBar($database)
            ),
            $this->quoteStringLiteral(
                $this->fooBar($table)
            )
        );
    }

    public function getListTablesSQL(string $database): string
    {
        return 'SELECT * FROM sys.user_tables';
    }

    public function getListViewsSQL(string $database): string
    {
        return sprintf(
            <<<'SQL'
SELECT VIEW_NAME,
       TEXT
  FROM SYS.ALL_VIEWS
 WHERE OWNER = %s
SQL
            ,
            $this->quoteStringLiteral(
                $this->fooBar($database)
            )
        );
    }

    /**
     * @return array<int, string>
     */
    protected function getCreateAutoincrementSql(string $name, string $table, int $start = 1): array
    {
        $tableIdentifier   = $this->normalizeIdentifier($table);
        $quotedTableName   = $tableIdentifier->getQuotedName($this);
        $unquotedTableName = $tableIdentifier->getName();

        $nameIdentifier = $this->normalizeIdentifier($name);
        $quotedName     = $nameIdentifier->getQuotedName($this);
        $unquotedName   = $nameIdentifier->getName();

        $sql = [];

        $autoincrementIdentifierName = $this->getAutoincrementIdentifierName($tableIdentifier);

        $idx = new Index($autoincrementIdentifierName, [$quotedName], true, true);

        $sql[] = "DECLARE
  constraints_Count NUMBER;
BEGIN
  SELECT COUNT(CONSTRAINT_NAME) INTO constraints_Count
    FROM USER_CONSTRAINTS
   WHERE TABLE_NAME = '" . $unquotedTableName . "'
     AND CONSTRAINT_TYPE = 'P';
  IF constraints_Count = 0 OR constraints_Count = '' THEN
    EXECUTE IMMEDIATE '" . $this->getCreateIndexSQL($idx, $quotedTableName) . "';
  END IF;
END;";

        $sequenceName = $this->getIdentitySequenceName(
            $tableIdentifier->isQuoted() ? $quotedTableName : $unquotedTableName,
            $nameIdentifier->isQuoted() ? $quotedName : $unquotedName
        );
        $sequence     = new Sequence($sequenceName, $start);
        $sql[]        = $this->getCreateSequenceSQL($sequence);

        $sql[] = 'CREATE TRIGGER ' . $autoincrementIdentifierName . '
   BEFORE INSERT
   ON ' . $quotedTableName . '
   FOR EACH ROW
DECLARE
   last_Sequence NUMBER;
   last_InsertID NUMBER;
BEGIN
   IF (:NEW.' . $quotedName . ' IS NULL OR :NEW.' . $quotedName . ' = 0) THEN
      SELECT ' . $sequenceName . '.NEXTVAL INTO :NEW.' . $quotedName . ' FROM DUAL;
   ELSE
      SELECT NVL(Last_Number, 0) INTO last_Sequence
        FROM User_Sequences
       WHERE Sequence_Name = \'' . $sequence->getName() . '\';
      SELECT :NEW.' . $quotedName . ' INTO last_InsertID FROM DUAL;
      WHILE (last_InsertID > last_Sequence) LOOP
         SELECT ' . $sequenceName . '.NEXTVAL INTO last_Sequence FROM DUAL;
      END LOOP;
      SELECT ' . $sequenceName . '.NEXTVAL INTO last_Sequence FROM DUAL;
   END IF;
END;';

        return $sql;
    }

    /**
     * @internal The method should be only used from within the OracleSchemaManager class hierarchy.
     *
     * Returns the SQL statements to drop the autoincrement for the given table name.
     *
     * @param string $table The table name to drop the autoincrement for.
     *
     * @return string[]
     */
    public function getDropAutoincrementSql(string $table): array
    {
        $table                       = $this->normalizeIdentifier($table);
        $autoincrementIdentifierName = $this->getAutoincrementIdentifierName($table);
        $identitySequenceName        = $this->getIdentitySequenceName(
            $table->isQuoted() ? $table->getQuotedName($this) : $table->getName(),
            ''
        );

        return [
            'DROP TRIGGER ' . $autoincrementIdentifierName,
            $this->getDropSequenceSQL($identitySequenceName),
            $this->getDropConstraintSQL($autoincrementIdentifierName, $table->getQuotedName($this)),
        ];
    }

    /**
     * Normalizes the given identifier.
     *
     * Uppercases the given identifier if it is not quoted by intention
     * to reflect Oracle's internal auto uppercasing strategy of unquoted identifiers.
     *
     * @param string $name The identifier to normalize.
     */
    private function normalizeIdentifier(string $name): Identifier
    {
        $identifier = new Identifier($name);

        return $identifier->isQuoted() ? $identifier : new Identifier(strtoupper($name));
    }

    private function fooBar(string $name): string
    {
        $identifier = new Identifier($name);
        $name       = $identifier->getName();

        if ($identifier->isQuoted()) {
            return $name;
        }

        return strtoupper($name);
    }

    /**
     * Adds suffix to identifier,
     *
     * if the new string exceeds max identifier length,
     * keeps $suffix, cuts from $identifier as much as the part exceeding.
     */
    private function addSuffix(string $identifier, string $suffix): string
    {
        $maxPossibleLengthWithoutSuffix = $this->getMaxIdentifierLength() - strlen($suffix);
        if (strlen($identifier) > $maxPossibleLengthWithoutSuffix) {
            $identifier = substr($identifier, 0, $maxPossibleLengthWithoutSuffix);
        }

        return $identifier . $suffix;
    }

    /**
     * Returns the autoincrement primary key identifier name for the given table identifier.
     *
     * Quotes the autoincrement primary key identifier name
     * if the given table name is quoted by intention.
     */
    private function getAutoincrementIdentifierName(Identifier $table): string
    {
        $identifierName = $this->addSuffix($table->getName(), '_AI_PK');

        return $table->isQuoted()
            ? $this->quoteSingleIdentifier($identifierName)
            : $identifierName;
    }

    public function getListTableForeignKeysSQL(string $table, string $database): string
    {
        return sprintf(
            <<<'SQL'
SELECT c.CONSTRAINT_NAME,
       c.DELETE_RULE,
       cc.COLUMN_NAME  AS "local_column",
       cc.POSITION,
       rcc.TABLE_NAME  AS "references_table",
       rcc.COLUMN_NAME AS "foreign_column"
FROM SYS.ALL_CONSTRAINTS c
JOIN SYS.ALL_CONS_COLUMNS cc
  ON cc.OWNER = c.OWNER
 AND cc.CONSTRAINT_NAME = c.CONSTRAINT_NAME
JOIN SYS.ALL_CONS_COLUMNS rcc
  ON rcc.OWNER = c.OWNER
 AND rcc.CONSTRAINT_NAME = c.R_CONSTRAINT_NAME
 AND rcc.POSITION = cc.POSITION
WHERE c.OWNER = %s
  AND c.TABLE_NAME = %s
  AND c.CONSTRAINT_TYPE = 'R'
ORDER BY cc.CONSTRAINT_NAME,
         cc.POSITION
SQL
            ,
            $this->quoteStringLiteral(
                $this->fooBar($database)
            ),
            $this->quoteStringLiteral(
                $this->fooBar($table)
            )
        );
    }

    /**
     * @deprecated
     */
    public function getListTableConstraintsSQL(string $table, string $database): string
    {
        $table = $this->quoteStringLiteral($this->fooBar($table));

        return 'SELECT * FROM user_constraints WHERE table_name = ' . $table;
    }

    public function getListTableColumnsSQL(string $table, string $database): string
    {
        return sprintf(
            <<<'SQL'
SELECT   tc.*,
         (
             SELECT cc.comments
             FROM   all_col_comments cc
             WHERE  cc.TABLE_NAME = tc.TABLE_NAME
             AND    cc.OWNER = tc.OWNER
             AND    cc.COLUMN_NAME = tc.COLUMN_NAME
         ) AS comments
FROM     all_tab_columns tc
WHERE    tc.owner = %s AND tc.table_name = %s
ORDER BY tc.column_id
SQL
            ,
            $this->quoteStringLiteral(
                $this->fooBar($database)
            ),
            $this->quoteStringLiteral(
                $this->fooBar($table)
            )
        );
    }

    public function getDropForeignKeySQL(string $foreignKey, string $table): string
    {
        return $this->getDropConstraintSQL($foreignKey, $table);
    }

    public function getAdvancedForeignKeyOptionsSQL(ForeignKeyConstraint $foreignKey): string
    {
        $referentialAction = '';

        if ($foreignKey->hasOption('onDelete')) {
            $referentialAction = $this->getForeignKeyReferentialActionSQL($foreignKey->getOption('onDelete'));
        }

        if ($referentialAction !== '') {
            return ' ON DELETE ' . $referentialAction;
        }

        return '';
    }

    public function getForeignKeyReferentialActionSQL(string $action): string
    {
        $action = strtoupper($action);

        switch ($action) {
            case 'RESTRICT': // RESTRICT is not supported, therefore falling back to NO ACTION.
            case 'NO ACTION':
                // NO ACTION cannot be declared explicitly,
                // therefore returning empty string to indicate to OMIT the referential clause.
                return '';

            case 'CASCADE':
            case 'SET NULL':
                return $action;

            default:
                // SET DEFAULT is not supported, throw exception instead.
                throw new InvalidArgumentException(sprintf('Invalid foreign key action "%s".', $action));
        }
    }

    public function getCreateDatabaseSQL(string $name): string
    {
        return 'CREATE USER ' . $name;
    }

    public function getDropDatabaseSQL(string $name): string
    {
        return 'DROP USER ' . $name . ' CASCADE';
    }

    /**
     * {@inheritDoc}
     */
    public function getAlterTableSQL(TableDiff $diff): array
    {
        $sql         = [];
        $commentsSQL = [];
        $columnSql   = [];

        $fields = [];

        foreach ($diff->addedColumns as $column) {
            if ($this->onSchemaAlterTableAddColumn($column, $diff, $columnSql)) {
                continue;
            }

            $fields[] = $this->getColumnDeclarationSQL($column->getQuotedName($this), $column->toArray());
            $comment  = $column->getComment();

            if ($comment === '') {
                continue;
            }

            $commentsSQL[] = $this->getCommentOnColumnSQL(
                $diff->getName($this)->getQuotedName($this),
                $column->getQuotedName($this),
                $comment
            );
        }

        if (count($fields) > 0) {
            $sql[] = 'ALTER TABLE ' . $diff->getName($this)->getQuotedName($this)
                . ' ADD (' . implode(', ', $fields) . ')';
        }

        $fields = [];
        foreach ($diff->changedColumns as $columnDiff) {
            if ($this->onSchemaAlterTableChangeColumn($columnDiff, $diff, $columnSql)) {
                continue;
            }

            $column = $columnDiff->column;

            // Do not generate column alteration clause if type is binary and only fixed property has changed.
            // Oracle only supports binary type columns with variable length.
            // Avoids unnecessary table alteration statements.
            if (
                $column->getType() instanceof BinaryType &&
                $columnDiff->hasChanged('fixed') &&
                count($columnDiff->changedProperties) === 1
            ) {
                continue;
            }

            $columnHasChangedComment = $columnDiff->hasChanged('comment');

            /**
             * Do not add query part if only comment has changed
             */
            if (! ($columnHasChangedComment && count($columnDiff->changedProperties) === 1)) {
                $columnInfo = $column->toArray();

                if (! $columnDiff->hasChanged('notnull')) {
                    unset($columnInfo['notnull']);
                }

                $fields[] = $column->getQuotedName($this) . $this->getColumnDeclarationSQL('', $columnInfo);
            }

            if (! $columnHasChangedComment) {
                continue;
            }

            $commentsSQL[] = $this->getCommentOnColumnSQL(
                $diff->getName($this)->getQuotedName($this),
                $column->getQuotedName($this),
                $column->getComment()
            );
        }

        if (count($fields) > 0) {
            $sql[] = 'ALTER TABLE ' . $diff->getName($this)->getQuotedName($this)
                . ' MODIFY (' . implode(', ', $fields) . ')';
        }

        foreach ($diff->renamedColumns as $oldColumnName => $column) {
            if ($this->onSchemaAlterTableRenameColumn($oldColumnName, $column, $diff, $columnSql)) {
                continue;
            }

            $oldColumnName = new Identifier($oldColumnName);

            $sql[] = 'ALTER TABLE ' . $diff->getName($this)->getQuotedName($this) .
                ' RENAME COLUMN ' . $oldColumnName->getQuotedName($this) . ' TO ' . $column->getQuotedName($this);
        }

        $fields = [];
        foreach ($diff->removedColumns as $column) {
            if ($this->onSchemaAlterTableRemoveColumn($column, $diff, $columnSql)) {
                continue;
            }

            $fields[] = $column->getQuotedName($this);
        }

        if (count($fields) > 0) {
            $sql[] = 'ALTER TABLE ' . $diff->getName($this)->getQuotedName($this)
                . ' DROP (' . implode(', ', $fields) . ')';
        }

        $tableSql = [];

        if (! $this->onSchemaAlterTable($diff, $tableSql)) {
            $sql = array_merge($sql, $commentsSQL);

            $newName = $diff->getNewName();

            if ($newName !== null) {
                $sql[] = sprintf(
                    'ALTER TABLE %s RENAME TO %s',
                    $diff->getName($this)->getQuotedName($this),
                    $newName->getQuotedName($this)
                );
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
     * {@inheritdoc}
     */
    public function getColumnDeclarationSQL(string $name, array $column): string
    {
        if (isset($column['columnDefinition'])) {
            $columnDef = $this->getCustomTypeDeclarationSQL($column);
        } else {
            $default = $this->getDefaultValueDeclarationSQL($column);

            $notnull = '';

            if (isset($column['notnull'])) {
                $notnull = $column['notnull'] ? ' NOT NULL' : ' NULL';
            }

            $unique = ! empty($column['unique']) ? ' UNIQUE' : '';

            $check = ! empty($column['check']) ?
                ' ' . $column['check'] : '';

            $typeDecl  = $column['type']->getSQLDeclaration($column, $this);
            $columnDef = $typeDecl . $default . $notnull . $unique . $check;
        }

        return $name . ' ' . $columnDef;
    }

    /**
     * {@inheritdoc}
     */
    protected function getRenameIndexSQL(string $oldIndexName, Index $index, string $tableName): array
    {
        if (str_contains($tableName, '.')) {
            [$schema]     = explode('.', $tableName);
            $oldIndexName = $schema . '.' . $oldIndexName;
        }

        return ['ALTER INDEX ' . $oldIndexName . ' RENAME TO ' . $index->getQuotedName($this)];
    }

    public function usesSequenceEmulatedIdentityColumns(): bool
    {
        return true;
    }

    public function getIdentitySequenceName(string $tableName, string $columnName): string
    {
        $table = new Identifier($tableName);

        // No usage of column name to preserve BC compatibility with <2.5
        $identitySequenceName = $this->addSuffix($table->getName(), '_SEQ');

        if ($table->isQuoted()) {
            $identitySequenceName = '"' . $identitySequenceName . '"';
        }

        $identitySequenceIdentifier = $this->normalizeIdentifier($identitySequenceName);

        return $identitySequenceIdentifier->getQuotedName($this);
    }

    public function supportsCommentOnStatement(): bool
    {
        return true;
    }

    protected function doModifyLimitQuery(string $query, ?int $limit, int $offset): string
    {
        if ($offset > 0) {
            $query .= sprintf(' OFFSET %d ROWS', $offset);
        }

        if ($limit !== null) {
            $query .= sprintf(' FETCH NEXT %d ROWS ONLY', $limit);
        }

        return $query;
    }

    public function getCreateTemporaryTableSnippetSQL(): string
    {
        return 'CREATE GLOBAL TEMPORARY TABLE';
    }

    public function getDateTimeTzFormatString(): string
    {
        return 'Y-m-d H:i:sP';
    }

    public function getDateFormatString(): string
    {
        return 'Y-m-d 00:00:00';
    }

    public function getTimeFormatString(): string
    {
        return '1900-01-01 H:i:s';
    }

    public function getMaxIdentifierLength(): int
    {
        return 30;
    }

    public function supportsSequences(): bool
    {
        return true;
    }

    public function supportsReleaseSavepoints(): bool
    {
        return false;
    }

    public function getTruncateTableSQL(string $tableName, bool $cascade = false): string
    {
        $tableIdentifier = new Identifier($tableName);

        return 'TRUNCATE TABLE ' . $tableIdentifier->getQuotedName($this);
    }

    public function getDummySelectSQL(string $expression = '1'): string
    {
        return sprintf('SELECT %s FROM DUAL', $expression);
    }

    protected function initializeDoctrineTypeMappings(): void
    {
        $this->doctrineTypeMapping = [
            'binary_double'  => 'float',
            'binary_float'   => 'float',
            'binary_integer' => 'boolean',
            'blob'           => 'blob',
            'char'           => 'string',
            'clob'           => 'text',
            'date'           => 'date',
            'float'          => 'float',
            'integer'        => 'integer',
            'long'           => 'string',
            'long raw'       => 'blob',
            'nchar'          => 'string',
            'nclob'          => 'text',
            'number'         => 'integer',
            'nvarchar2'      => 'string',
            'pls_integer'    => 'boolean',
            'raw'            => 'binary',
            'rowid'          => 'string',
            'timestamp'      => 'datetime',
            'timestamptz'    => 'datetimetz',
            'urowid'         => 'string',
            'varchar'        => 'string',
            'varchar2'       => 'string',
        ];
    }

    public function releaseSavePoint(string $savepoint): string
    {
        return '';
    }

    protected function createReservedKeywordsList(): KeywordList
    {
        return new OracleKeywords();
    }

    /**
     * {@inheritDoc}
     */
    public function getBlobTypeDeclarationSQL(array $column): string
    {
        return 'BLOB';
    }

    public function getListTableCommentsSQL(string $table, string $database): string
    {
        return sprintf(
            <<<'SQL'
SELECT COMMENTS
  FROM SYS.ALL_TAB_COMMENTS
 WHERE OWNER = %s
   AND TABLE_NAME = %s
SQL
            ,
            $this->quoteStringLiteral(
                $this->fooBar($database)
            ),
            $this->quoteStringLiteral(
                $this->fooBar($table)
            )
        );
    }
}
