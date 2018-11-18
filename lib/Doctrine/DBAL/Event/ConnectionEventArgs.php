<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Event;

use Doctrine\Common\EventArgs;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;

/**
 * Event Arguments used when a Driver connection is established inside Doctrine\DBAL\Connection.
 */
class ConnectionEventArgs extends EventArgs
{
    /** @var Connection */
    private $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function getConnection() : Connection
    {
        return $this->connection;
    }

    public function getDriver() : Driver
    {
        return $this->connection->getDriver();
    }

    public function getDatabasePlatform() : AbstractPlatform
    {
        return $this->connection->getDatabasePlatform();
    }

    public function getSchemaManager() : AbstractSchemaManager
    {
        return $this->connection->getSchemaManager();
    }
}
