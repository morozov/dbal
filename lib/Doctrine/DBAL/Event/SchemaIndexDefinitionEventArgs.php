<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Event;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Index;

/**
 * Event Arguments used when the portable index definition is generated inside Doctrine\DBAL\Schema\AbstractSchemaManager.
 */
class SchemaIndexDefinitionEventArgs extends SchemaEventArgs
{
    /** @var Index|null */
    private $index = null;

    /**
     * Raw index data as fetched from the database.
     *
     * @var mixed[]
     */
    private $tableIndex;

    /** @var string */
    private $table;

    /** @var Connection */
    private $connection;

    /**
     * @param mixed[] $tableIndex
     */
    public function __construct(array $tableIndex, string $table, Connection $connection)
    {
        $this->tableIndex = $tableIndex;
        $this->table      = $table;
        $this->connection = $connection;
    }

    /**
     * Allows to clear the index which means the index will be excluded from tables index list.
     */
    public function setIndex(?Index $index = null) : SchemaIndexDefinitionEventArgs
    {
        $this->index = $index;

        return $this;
    }

    public function getIndex() : ?Index
    {
        return $this->index;
    }

    /**
     * @return mixed[]
     */
    public function getTableIndex() : array
    {
        return $this->tableIndex;
    }

    public function getTable() : string
    {
        return $this->table;
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
