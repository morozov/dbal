<?php

namespace Doctrine\DBAL\Platforms;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Identifier;
use Doctrine\DBAL\Schema\IdentifierV2;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\NonQuotedIdentifier;
use Doctrine\DBAL\Schema\QuotedIdentifier;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\TransactionIsolationLevel;
use Doctrine\DBAL\Types\BinaryType;
use InvalidArgumentException;

use function array_merge;
use function count;
use function explode;
use function func_get_arg;
use function func_num_args;
use function implode;
use function preg_match;
use function sprintf;
use function strlen;
use function strpos;
use function strtoupper;
use function substr;

/**
 * OraclePlatform.
 */
class OraclePlatform extends AbstractPlatform
{
    /**
     * Assertion for Oracle identifiers.
     *
     * @link http://docs.oracle.com/cd/B19306_01/server.102/b14200/sql_elements008.htm
     *
     * @param string $identifier
     *
     * @return void
     *
     * @throws Exception
     */
    public static function assertValidIdentifier($identifier)
    {
        if (preg_match('(^(([a-zA-Z]{1}[a-zA-Z0-9_$#]{0,})|("[^"]+"))$)', $identifier) === 0) {
            throw new Exception('Invalid Oracle identifier');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getSubstringExpression($string, $start, $length = null)
    {
        if ($length !== null) {
            return sprintf('SUBSTR(%s, %d, %d)', $string, $start, $length);
        }

        return sprintf('SUBSTR(%s, %d)', $string, $start);
    }

    /**
     * @param string $type
     *
     * @return string
     */
    public function getNowExpression($type = 'timestamp')
    {
        switch ($type) {
            case 'date':
            case 'time':
            case 'timestamp':
            default:
                return 'TO_CHAR(CURRENT_TIMESTAMP, \'YYYY-MM-DD HH24:MI:SS\')';
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getLocateExpression($str, $substr, $startPos = false)
    {
        if ($startPos === false) {
            return 'INSTR(' . $str . ', ' . $substr . ')';
        }

        return 'INSTR(' . $str . ', ' . $substr . ', ' . $startPos . ')';
    }

    /**
     * {@inheritdoc}
     */
    protected function getDateArithmeticIntervalExpression($date, $operator, $interval, $unit)
    {
        switch ($unit) {
            case DateIntervalUnit::MONTH:
            case DateIntervalUnit::QUARTER:
            case DateIntervalUnit::YEAR:
                switch ($unit) {
                    case DateIntervalUnit::QUARTER:
                        $interval *= 3;
                        break;

                    case DateIntervalUnit::YEAR:
                        $interval *= 12;
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

    /**
     * {@inheritDoc}
     */
    public function getDateDiffExpression($date1, $date2)
    {
        return sprintf('TRUNC(%s) - TRUNC(%s)', $date1, $date2);
    }

    /**
     * {@inheritDoc}
     */
    public function getBitAndComparisonExpression($value1, $value2)
    {
        return 'BITAND(' . $value1 . ', ' . $value2 . ')';
    }

    public function getCurrentDatabaseExpression(): string
    {
        return "SYS_CONTEXT('USERENV', 'CURRENT_SCHEMA')";
    }

    /**
     * {@inheritDoc}
     */
    public function getBitOrComparisonExpression($value1, $value2)
    {
        return '(' . $value1 . '-' .
                $this->getBitAndComparisonExpression($value1, $value2)
                . '+' . $value2 . ')';
    }

    /**
     * {@inheritDoc}
     *
     * Need to specifiy minvalue, since start with is hidden in the system and MINVALUE <= START WITH.
     * Therefore we can use MINVALUE to be able to get a hint what START WITH was for later introspection
     * in {@see listSequences()}
     */
    public function getCreateSequenceSQL(Sequence $sequence)
    {
        return 'CREATE SEQUENCE ' . $this->quoteIdentifier($sequence->getName()) .
               ' START WITH ' . $sequence->getInitialValue() .
               ' MINVALUE ' . $sequence->getInitialValue() .
               ' INCREMENT BY ' . $sequence->getAllocationSize() .
               $this->getSequenceCacheSQL($sequence);
    }

    /**
     * {@inheritDoc}
     */
    public function getAlterSequenceSQL(Sequence $sequence)
    {
        return 'ALTER SEQUENCE ' . $sequence->getQuotedName($this) .
               ' INCREMENT BY ' . $sequence->getAllocationSize()
               . $this->getSequenceCacheSQL($sequence);
    }

    /**
     * Cache definition for sequences
     *
     * @return string
     */
    private function getSequenceCacheSQL(Sequence $sequence)
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

    /**
     * {@inheritDoc}
     */
    public function getSequenceNextValSQL($sequence)
    {
        return 'SELECT ' . $sequence . '.nextval FROM DUAL';
    }

    /**
     * {@inheritDoc}
     */
    public function getSetTransactionIsolationSQL($level)
    {
        return 'SET TRANSACTION ISOLATION LEVEL ' . $this->_getTransactionIsolationLevelSQL($level);
    }

    /**
     * {@inheritDoc}
     */
    protected function _getTransactionIsolationLevelSQL($level)
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
    public function getBooleanTypeDeclarationSQL(array $column)
    {
        return 'NUMBER(1)';
    }

    /**
     * {@inheritDoc}
     */
    public function getIntegerTypeDeclarationSQL(array $column)
    {
        return 'NUMBER(10)';
    }

    /**
     * {@inheritDoc}
     */
    public function getBigIntTypeDeclarationSQL(array $column)
    {
        return 'NUMBER(20)';
    }

    /**
     * {@inheritDoc}
     */
    public function getSmallIntTypeDeclarationSQL(array $column)
    {
        return 'NUMBER(5)';
    }

    /**
     * {@inheritDoc}
     */
    public function getDateTimeTypeDeclarationSQL(array $column)
    {
        return 'TIMESTAMP(0)';
    }

    /**
     * {@inheritDoc}
     */
    public function getDateTimeTzTypeDeclarationSQL(array $column)
    {
        return 'TIMESTAMP(0) WITH TIME ZONE';
    }

    /**
     * {@inheritDoc}
     */
    public function getDateTypeDeclarationSQL(array $column)
    {
        return 'DATE';
    }

    /**
     * {@inheritDoc}
     */
    public function getTimeTypeDeclarationSQL(array $column)
    {
        return 'DATE';
    }

    /**
     * {@inheritDoc}
     */
    protected function _getCommonIntegerTypeDeclarationSQL(array $column)
    {
        return '';
    }

    /**
     * {@inheritDoc}
     */
    protected function getVarcharTypeDeclarationSQLSnippet($length, $fixed)
    {
        return $fixed ? ($length > 0 ? 'CHAR(' . $length . ')' : 'CHAR(2000)')
                : ($length > 0 ? 'VARCHAR2(' . $length . ')' : 'VARCHAR2(4000)');
    }

    /**
     * {@inheritdoc}
     */
    protected function getBinaryTypeDeclarationSQLSnippet($length, $fixed)
    {
        return 'RAW(' . ($length > 0 ? $length : $this->getBinaryMaxLength()) . ')';
    }

    /**
     * {@inheritdoc}
     */
    public function getBinaryMaxLength()
    {
        return 2000;
    }

    /**
     * {@inheritDoc}
     */
    public function getClobTypeDeclarationSQL(array $column)
    {
        return 'CLOB';
    }

    /**
     * {@inheritDoc}
     */
    public function getListDatabasesSQL()
    {
        return 'SELECT username FROM all_users';
    }

    public function getListSequencesSQL(IdentifierV2 $database): string
    {
        return 'SELECT SEQUENCE_NAME, MIN_VALUE, INCREMENT_BY FROM SYS.ALL_SEQUENCES WHERE SEQUENCE_OWNER = '
            . $database->toLiteralSQL($this);
    }

    /**
     * {@inheritDoc}
     */
    protected function _getCreateTableSQL($name, array $columns, array $options = [])
    {
        $indexes            = $options['indexes'] ?? [];
        $options['indexes'] = [];
        $sql                = parent::_getCreateTableSQL($name, $columns, $options);

        foreach ($columns as $columnName => $column) {
            if (isset($column['sequence'])) {
                $sql[] = $this->getCreateSequenceSQL($column['sequence']);
            }

            if (
                ! isset($column['autoincrement']) || ! $column['autoincrement'] &&
                (! isset($column['autoinc']) || ! $column['autoinc'])
            ) {
                continue;
            }

            $sql = array_merge($sql, $this->getCreateAutoincrementSql($columnName, $name));
        }

        if (isset($indexes) && ! empty($indexes)) {
            foreach ($indexes as $index) {
                $sql[] = $this->getCreateIndexSQL($index, $name);
            }
        }

        return $sql;
    }

    /**
     * {@inheritDoc}
     *
     * @link http://ezcomponents.org/docs/api/trunk/DatabaseSchema/ezcDbSchemaOracleReader.html
     */
    public function getListTableIndexesSQL($table, $database)
    {
        $table    = $this->doTheHellCreateIdentifier($table);
        $database = $this->doTheHellCreateIdentifier($database);

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
    ) AS is_primary
    FROM SYS.ALL_IND_COLUMNS ic
   WHERE ic.INDEX_OWNER = %s
     AND ic.TABLE_NAME = %s
ORDER BY ic.COLUMN_POSITION
SQL
            ,
            $database->toLiteralSQL($this),
            $table->toLiteralSQL($this)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getListTablesSQL()
    {
        return 'SELECT * FROM sys.user_tables';
    }

    /**
     * {@inheritDoc}
     */
    public function getListViewsSQL($database)
    {
        $database = $this->doTheHellCreateIdentifier($database);

        return sprintf(
            <<<'SQL'
SELECT VIEW_NAME,
       TEXT
  FROM SYS.ALL_VIEWS
 WHERE OWNER = %s
SQL
            ,
            $database->toLiteralSQL($this)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getCreateViewSQL($name, $sql)
    {
        $name = $this->doTheHellCreateIdentifier($name);

        return 'CREATE VIEW ' . $name->toIdentifierSQL($this) . ' AS ' . $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function getDropViewSQL($name)
    {
        $name = $this->doTheHellCreateIdentifier($name);

        return 'DROP VIEW ' . $name->toIdentifierSQL($this);
    }

    /**
     * @param string $name
     * @param string $table
     * @param int    $start
     *
     * @return string[]
     */
    public function getCreateAutoincrementSql($name, $table, $start = 1)
    {
        $name  = $this->doTheHellCreateIdentifier($name);
        $table = $this->doTheHellCreateIdentifier($table);

        $sql = [];

        $autoincrementIdentifierName = $this->getAutoincrementIdentifierName($table);

        $idx = new Index($autoincrementIdentifierName, [$name], true, true);

        $sql[] = 'DECLARE
  constraints_Count NUMBER;
BEGIN
  SELECT COUNT(CONSTRAINT_NAME) INTO constraints_Count FROM USER_CONSTRAINTS WHERE TABLE_NAME = '
            . $table->toLiteralSQL($this)
            . " AND CONSTRAINT_TYPE = 'P';
  IF constraints_Count = 0 OR constraints_Count = '' THEN
    EXECUTE IMMEDIATE '" . $this->getCreateConstraintSQL($idx, $table) . "';
  END IF;
END;";

        $sequenceName = $this->getIdentitySequenceName($table, $name);
        $sequence     = new Sequence($sequenceName, $start);
        $sql[]        = $this->getCreateSequenceSQL($sequence);

        $sql[] = 'CREATE TRIGGER ' . $this->quoteIdentifier($autoincrementIdentifierName) . '
   BEFORE INSERT
   ON ' . $table->toIdentifierSQL($this) . '
   FOR EACH ROW
DECLARE
   last_Sequence NUMBER;
   last_InsertID NUMBER;
BEGIN
   SELECT ' . $this->quoteIdentifier($sequenceName) . '.NEXTVAL INTO :NEW.'
            . $name->toIdentifierSQL($this) . ' FROM DUAL;
   IF (:NEW.' . $name->toIdentifierSQL($this) . ' IS NULL OR :NEW.'
            . $name->toIdentifierSQL($this) . ' = 0) THEN
      SELECT ' . $this->quoteIdentifier($sequenceName) . '.NEXTVAL INTO :NEW.'
            . $name->toIdentifierSQL($this) . ' FROM DUAL;
   ELSE
      SELECT NVL(Last_Number, 0) INTO last_Sequence
        FROM User_Sequences
       WHERE Sequence_Name = ' . $this->quoteStringLiteral($sequenceName) . ';
      SELECT :NEW.' . $name->toIdentifierSQL($this) . ' INTO last_InsertID FROM DUAL;
      WHILE (last_InsertID > last_Sequence) LOOP
         SELECT ' . $this->quoteIdentifier($sequenceName) . '.NEXTVAL INTO last_Sequence FROM DUAL;
      END LOOP;
   END IF;
END;';

        return $sql;
    }

    /**
     * Returns the SQL statements to drop the autoincrement for the given table name.
     *
     * @param string $table The table name to drop the autoincrement for.
     *
     * @return string[]
     */
    public function getDropAutoincrementSql($table)
    {
        $table = $this->doTheHellCreateIdentifier($table);

        $autoincrementIdentifierName = $this->getAutoincrementIdentifierName($table);
        $identitySequenceName        = $this->getIdentitySequenceName($table, '');

        return [
            'DROP TRIGGER ' . $autoincrementIdentifierName->toIdentifierSQL($this),
            $this->getDropSequenceSQL($identitySequenceName),
            $this->getDropConstraintSQL(
                $autoincrementIdentifierName,
                $table
            ),
        ];
    }

    /**
     * Adds suffix to identifier,
     *
     * if the new string exceeds max identifier length,
     * keeps $suffix, cuts from $identifier as much as the part exceeding.
     */
    private function addSuffix(IdentifierV2 $identifier, string $suffix): IdentifierV2
    {
        $value = $identifier->toValue($this);

        $maxPossibleLengthWithoutSuffix = $this->getMaxIdentifierLength() - strlen($suffix);
        if (strlen($value) > $maxPossibleLengthWithoutSuffix) {
            $value = substr($value, 0, $maxPossibleLengthWithoutSuffix);
        }

        return new QuotedIdentifier($value . $suffix);
    }

    /**
     * Returns the autoincrement primary key identifier name for the given table identifier.
     *
     * Quotes the autoincrement primary key identifier name
     * if the given table name is quoted by intention.
     *
     * @param IdentifierV2 $table The table identifier to return the autoincrement primary key identifier name for.
     */
    private function getAutoincrementIdentifierName(IdentifierV2 $table): IdentifierV2
    {
        return $this->addSuffix($table, '_AI_PK');
    }

    /**
     * {@inheritDoc}
     */
    public function getListTableForeignKeysSQL($table, $database)
    {
        $table    = $this->doTheHellCreateIdentifier($table);
        $database = $this->doTheHellCreateIdentifier($database);

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
            $database->toLiteralSQL($this),
            $table->toLiteralSQL($this)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getListTableConstraintsSQL($table)
    {
        $table = $this->doTheHellCreateIdentifier($table);

        return 'SELECT * FROM user_constraints WHERE table_name = ' . $table->toLiteralSQL($this);
    }

    /**
     * {@inheritDoc}
     */
    public function getListTableColumnsSQL($table, $database)
    {
        $table    = $this->doTheHellCreateIdentifier($table);
        $database = $this->doTheHellCreateIdentifier($database);

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
            $database->toLiteralSQL($this),
            $table->toLiteralSQL($this)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getDropSequenceSQL($sequence)
    {
        $sequence = $this->doTheHellCreateIdentifier($sequence);

        return 'DROP SEQUENCE ' . $sequence->toIdentifierSQL($this);
    }

    /**
     * {@inheritDoc}
     */
    public function getDropForeignKeySQL($foreignKey, $table)
    {
        $foreignKey = $this->doTheHellCreateIdentifier($foreignKey);
        $table      = $this->doTheHellCreateIdentifier($table);

        return 'ALTER TABLE ' . $table->toIdentifierSQL($this)
            . ' DROP CONSTRAINT ' . $foreignKey->toIdentifierSQL($this);
    }

    /**
     * {@inheritdoc}
     */
    public function getAdvancedForeignKeyOptionsSQL(ForeignKeyConstraint $foreignKey)
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

    /**
     * {@inheritdoc}
     */
    public function getForeignKeyReferentialActionSQL($action)
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
                throw new InvalidArgumentException('Invalid foreign key action: ' . $action);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getDropDatabaseSQL($database)
    {
        return 'DROP USER ' . $database . ' CASCADE';
    }

    /**
     * {@inheritDoc}
     */
    public function getAlterTableSQL(TableDiff $diff)
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
            $comment  = $this->getColumnComment($column);

            if ($comment === null || $comment === '') {
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
                $this->getColumnComment($column)
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

            if ($newName !== false) {
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
    public function getColumnDeclarationSQL($name, array $column)
    {
        if (isset($column['columnDefinition'])) {
            $columnDef = $this->getCustomTypeDeclarationSQL($column);
        } else {
            $default = $this->getDefaultValueDeclarationSQL($column);

            $notnull = '';

            if (isset($column['notnull'])) {
                $notnull = $column['notnull'] ? ' NOT NULL' : ' NULL';
            }

            $unique = ! empty($column['unique']) ?
                ' ' . $this->getUniqueFieldDeclarationSQL() : '';

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
    protected function getRenameIndexSQL($oldIndexName, Index $index, $tableName)
    {
        if (strpos($tableName, '.') !== false) {
            [$schema]     = explode('.', $tableName);
            $oldIndexName = $schema . '.' . $oldIndexName;
        }

        return ['ALTER INDEX ' . $oldIndexName . ' RENAME TO ' . $index->getQuotedName($this)];
    }

    /**
     * {@inheritdoc}
     */
    public function usesSequenceEmulatedIdentityColumns()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getIdentitySequenceName($tableName, $columnName)
    {
        $tableName = $this->doTheHellCreateIdentifier($tableName);

        // No usage of column name to preserve BC compatibility with <2.5
        return $this->addSuffix($tableName, '_SEQ');
    }

    /**
     * {@inheritDoc}
     */
    public function supportsCommentOnStatement()
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function getName()
    {
        return 'oracle';
    }

    /**
     * {@inheritDoc}
     */
    protected function doModifyLimitQuery($query, $limit, $offset)
    {
        if ($limit === null && $offset <= 0) {
            return $query;
        }

        if (preg_match('/^\s*SELECT/i', $query) === 1) {
            if (preg_match('/\sFROM\s/i', $query) === 0) {
                $query .= ' FROM dual';
            }

            $columns = ['a.*'];

            if ($offset > 0) {
                $columns[] = 'ROWNUM AS doctrine_rownum';
            }

            $query = sprintf('SELECT %s FROM (%s) a', implode(', ', $columns), $query);

            if ($limit !== null) {
                $query .= sprintf(' WHERE ROWNUM <= %d', $offset + $limit);
            }

            if ($offset > 0) {
                $query = sprintf('SELECT * FROM (%s) WHERE doctrine_rownum >= %d', $query, $offset + 1);
            }
        }

        return $query;
    }

    /**
     * {@inheritDoc}
     */
    public function getCreateTemporaryTableSnippetSQL()
    {
        return 'CREATE GLOBAL TEMPORARY TABLE';
    }

    /**
     * {@inheritDoc}
     */
    public function getDateTimeTzFormatString()
    {
        return 'Y-m-d H:i:sP';
    }

    /**
     * {@inheritDoc}
     */
    public function getDateFormatString()
    {
        return 'Y-m-d 00:00:00';
    }

    /**
     * {@inheritDoc}
     */
    public function getTimeFormatString()
    {
        return '1900-01-01 H:i:s';
    }

    /**
     * {@inheritDoc}
     */
    public function getMaxIdentifierLength()
    {
        return 30;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsSequences()
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsReleaseSavepoints()
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function getTruncateTableSQL($tableName, $cascade = false)
    {
        $tableIdentifier = new Identifier($tableName);

        return 'TRUNCATE TABLE ' . $tableIdentifier->getQuotedName($this);
    }

    /**
     * {@inheritDoc}
     */
    public function getDummySelectSQL()
    {
        $expression = func_num_args() > 0 ? func_get_arg(0) : '1';

        return sprintf('SELECT %s FROM DUAL', $expression);
    }

    /**
     * {@inheritDoc}
     */
    protected function initializeDoctrineTypeMappings()
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

    /**
     * {@inheritDoc}
     */
    public function releaseSavePoint($savepoint)
    {
        return '';
    }

    /**
     * {@inheritDoc}
     */
    protected function getReservedKeywordsClass()
    {
        return Keywords\OracleKeywords::class;
    }

    /**
     * {@inheritDoc}
     */
    public function getBlobTypeDeclarationSQL(array $column)
    {
        return 'BLOB';
    }

    public function getListTableCommentsSQL(string $table, string $database): string
    {
        $table    = $this->doTheHellCreateIdentifier($table);
        $database = $this->doTheHellCreateIdentifier($database);

        return sprintf(
            <<<'SQL'
SELECT COMMENTS
  FROM SYS.ALL_TAB_COMMENTS
 WHERE OWNER = %s
   AND TABLE_NAME = %s
SQL
            ,
            $database->toLiteralSQL($this),
            $table->toLiteralSQL($this),
        );
    }

    public function normalizeIdentifier(string $identifier): string
    {
        return strtoupper($identifier);
    }

    public function doTheHellCreateIdentifier($argument): ?IdentifierV2
    {
        if ($argument === null || $argument instanceof IdentifierV2) {
            return $argument;
        }

        if (isset($argument[0]) && $argument[0] === '"') {
            return new QuotedIdentifier(substr($argument, 1, -1));
        }

        return new NonQuotedIdentifier($argument);
    }
}
