<?php

namespace Doctrine\DBAL\SQLCommenter;

use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Result as DriverResult;
use Doctrine\DBAL\Driver\Statement as DriverStatement;
use Doctrine\DBAL\ParameterType;

final class Connection implements ConnectionInterface
{
    /** @var ConnectionInterface */
    private $connection;

    /** @var string */
    private $comment;

    public function __construct(ConnectionInterface $connection, string $comment)
    {
        $this->connection = $connection;
        $this->comment    = $comment;
    }

    public function prepare(string $sql): DriverStatement
    {
        return $this->connection->prepare($sql . ' /* ' . $this->comment . ' */');
    }

    public function query(string $sql): DriverResult
    {
        return $this->connection->query($sql);
    }

    /**
     * {@inheritDoc}
     */
    public function quote($value, $type = ParameterType::STRING)
    {
        return $this->connection->quote($value, $type);
    }

    public function exec(string $sql): int
    {
        return $this->connection->exec($sql);
    }

    /**
     * {@inheritDoc}
     */
    public function lastInsertId($name = null)
    {
        return $this->connection->lastInsertId($name);
    }

    /**
     * {@inheritDoc}
     */
    public function beginTransaction()
    {
        return $this->connection->beginTransaction();
    }

    /**
     * {@inheritDoc}
     */
    public function commit()
    {
        return $this->connection->commit();
    }

    /**
     * {@inheritDoc}
     */
    public function rollBack()
    {
        return $this->connection->rollBack();
    }
}
