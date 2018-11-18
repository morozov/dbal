<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema;

use Doctrine\DBAL\DBALException;
use function implode;
use function sprintf;

class SchemaException extends DBALException
{
    public const TABLE_DOESNT_EXIST       = 10;
    public const TABLE_ALREADY_EXISTS     = 20;
    public const COLUMN_DOESNT_EXIST      = 30;
    public const COLUMN_ALREADY_EXISTS    = 40;
    public const INDEX_DOESNT_EXIST       = 50;
    public const INDEX_ALREADY_EXISTS     = 60;
    public const SEQUENCE_DOENST_EXIST    = 70;
    public const SEQUENCE_ALREADY_EXISTS  = 80;
    public const INDEX_INVALID_NAME       = 90;
    public const FOREIGNKEY_DOESNT_EXIST  = 100;
    public const CONSTRAINT_DOESNT_EXIST  = 110;
    public const NAMESPACE_ALREADY_EXISTS = 120;

    public static function tableDoesNotExist(string $tableName) : self
    {
        return new self("There is no table with name '" . $tableName . "' in the schema.", self::TABLE_DOESNT_EXIST);
    }

    public static function indexNameInvalid(string $indexName) : self
    {
        return new self(
            sprintf('Invalid index-name %s given, has to be [a-zA-Z0-9_]', $indexName),
            self::INDEX_INVALID_NAME
        );
    }

    public static function indexDoesNotExist(string $indexName, string $table) : self
    {
        return new self(
            sprintf("Index '%s' does not exist on table '%s'.", $indexName, $table),
            self::INDEX_DOESNT_EXIST
        );
    }

    public static function indexAlreadyExists(string $indexName, string $table) : self
    {
        return new self(
            sprintf("An index with name '%s' was already defined on table '%s'.", $indexName, $table),
            self::INDEX_ALREADY_EXISTS
        );
    }

    public static function columnDoesNotExist(string $columnName, string $table) : self
    {
        return new self(
            sprintf("There is no column with name '%s' on table '%s'.", $columnName, $table),
            self::COLUMN_DOESNT_EXIST
        );
    }

    public static function namespaceAlreadyExists(string $namespaceName) : self
    {
        return new self(
            sprintf("The namespace with name '%s' already exists.", $namespaceName),
            self::NAMESPACE_ALREADY_EXISTS
        );
    }

    public static function tableAlreadyExists(string $tableName) : self
    {
        return new self("The table with name '" . $tableName . "' already exists.", self::TABLE_ALREADY_EXISTS);
    }

    public static function columnAlreadyExists(string $tableName, string $columnName) : self
    {
        return new self(
            "The column '" . $columnName . "' on table '" . $tableName . "' already exists.",
            self::COLUMN_ALREADY_EXISTS
        );
    }

    public static function sequenceAlreadyExists(string $sequenceName) : self
    {
        return new self("The sequence '" . $sequenceName . "' already exists.", self::SEQUENCE_ALREADY_EXISTS);
    }

    public static function sequenceDoesNotExist(string $sequenceName) : self
    {
        return new self("There exists no sequence with the name '" . $sequenceName . "'.", self::SEQUENCE_DOENST_EXIST);
    }

    public static function uniqueConstraintDoesNotExist(string $constraintName, string $table) : self
    {
        return new self(sprintf(
            'There exists no unique constraint with the name "%s" on table "%s".',
            $constraintName,
            $table
        ), self::CONSTRAINT_DOESNT_EXIST);
    }

    public static function foreignKeyDoesNotExist(string $fkName, string $table) : self
    {
        return new self(
            sprintf("There exists no foreign key with the name '%s' on table '%s'.", $fkName, $table),
            self::FOREIGNKEY_DOESNT_EXIST
        );
    }

    public static function namedForeignKeyRequired(Table $localTable, ForeignKeyConstraint $foreignKey) : self
    {
        return new self(
            'The performed schema operation on ' . $localTable->getName() . ' requires a named foreign key, ' .
            'but the given foreign key from (' . implode(', ', $foreignKey->getColumns()) . ') onto foreign table ' .
            "'" . $foreignKey->getForeignTableName() . "' (" . implode(', ', $foreignKey->getForeignColumns()) . ') is currently ' .
            'unnamed.'
        );
    }

    public static function alterTableChangeNotSupported(string $changeName) : self
    {
        return new self(
            sprintf("Alter table change not supported, given '%s'", $changeName)
        );
    }
}
