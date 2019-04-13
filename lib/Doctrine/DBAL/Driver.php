<?php

declare(strict_types=1);

namespace Doctrine\DBAL;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;

/**
 * Driver interface.
 * Interface that all DBAL drivers must implement.
 */
interface Driver
{
    /**
     * Attempts to create a connection with the database.
     *
     * @param mixed[]     $params        All connection parameters passed by the user.
     * @param string|null $username      The username to use when connecting.
     * @param string|null $password      The password to use when connecting.
     * @param mixed[]     $driverOptions The driver options to use when connecting.
     *
     * @return DriverConnection The database connection.
     */
    public function connect(array $params, ?string $username = null, ?string $password = null, array $driverOptions = []) : DriverConnection;

    /**
     * Gets the DatabasePlatform instance that provides all the metadata about
     * the platform this driver connects to.
     *
     * @return AbstractPlatform The database platform.
     */
    public function getDatabasePlatform() : AbstractPlatform;

    /**
     * Gets the SchemaManager that can be used to inspect and change the underlying
     * database schema of the platform this driver connects to.
     */
    public function getSchemaManager(Connection $conn) : AbstractSchemaManager;

    /**
     * Gets the name of the driver.
     *
     * @return string The name of the driver.
     */
    public function getName() : string;

    /**
     * Gets the name of the database connected to for this driver.
     *
     * @return string|null The name of the database.
     */
    public function getDatabase(Connection $conn) : ?string;
}
