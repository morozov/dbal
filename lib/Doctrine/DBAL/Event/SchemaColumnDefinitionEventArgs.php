<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Event;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Column;

/**
 * Event Arguments used when the portable column definition is generated inside Doctrine\DBAL\Schema\AbstractSchemaManager.
 */
class SchemaColumnDefinitionEventArgs extends SchemaEventArgs
{
    /** @var Column|null */
    private $column = null;

    /**
     * Raw column data as fetched from the database.
     *
     * @var mixed[]
     */
    private $tableColumn;

    /** @var string */
    private $table;

    /** @var string */
    private $database;

    /** @var Connection */
    private $connection;

    /**
     * @param mixed[] $tableColumn
     */
    public function __construct(array $tableColumn, string $table, ?string $database, Connection $connection)
    {
        $this->tableColumn = $tableColumn;
        $this->table       = $table;
        $this->database    = $database;
        $this->connection  = $connection;
    }

    /**
     * Allows to clear the column which means the column will be excluded from
     * tables column list.
     */
    public function setColumn(?Column $column = null) : self
    {
        $this->column = $column;

        return $this;
    }

    public function getColumn() : ?Column
    {
        return $this->column;
    }

    /**
     * @return mixed[]
     */
    public function getTableColumn() : array
    {
        return $this->tableColumn;
    }

    public function getTable() : string
    {
        return $this->table;
    }

    public function getDatabase() : string
    {
        return $this->database;
    }

    public function getConnection() : Connection
    {
        return $this->connection;
    }

    public function getDatabasePlatform() : AbstractPlatform
    {
        return $this->connection->getDatabasePlatform();
    }
}
