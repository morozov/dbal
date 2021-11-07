<?php

namespace Doctrine\DBAL\Portability;

use Doctrine\DBAL\ColumnCase;
use Doctrine\DBAL\Connection as DBALConnection;
use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\PDO;
use Doctrine\DBAL\Platforms\AbstractPlatform;

use const CASE_LOWER;
use const CASE_UPPER;

final class Driver implements DriverInterface
{
    /** @var DriverInterface */
    private $driver;

    /** @var int */
    private $mode;

    /** @var int */
    private $case;

    public function __construct(DriverInterface $driver, int $mode, int $case)
    {
        $this->driver = $driver;
        $this->mode   = $mode;
        $this->case   = $case;
    }

    /**
     * {@inheritDoc}
     */
    public function connect(array $params)
    {
        $mode = $this->mode;
        $case = 0;

        if ($this->case !== 0 && ($mode & Connection::PORTABILITY_FIX_CASE) !== 0) {
            if (
                $this->driver instanceof PDO\MySQL\Driver
                || $this->driver instanceof PDO\OCI\Driver
                || $this->driver instanceof PDO\PgSQL\Driver
                || $this->driver instanceof PDO\SQLite\Driver
                || $this->driver instanceof PDO\SQLSrv\Driver
            ) {
                // make use of c-level support for case handling
                $params['driverOptions'][\PDO::ATTR_CASE] = $this->case;

                $mode &= ~Connection::PORTABILITY_FIX_CASE;
            } else {
                $case = $this->case === ColumnCase::LOWER ? CASE_LOWER : CASE_UPPER;
            }
        }

        $connection = $this->driver->connect($params);

        $mode = (new OptimizeFlags())($this->getDatabasePlatform(), $mode);

        $convertEmptyStringToNull = ($mode & Connection::PORTABILITY_EMPTY_TO_NULL) !== 0;
        $rightTrimString          = ($mode & Connection::PORTABILITY_RTRIM) !== 0;

        if (! $convertEmptyStringToNull && ! $rightTrimString && $case === 0) {
            return $connection;
        }

        return new Connection(
            $connection,
            new Converter($convertEmptyStringToNull, $rightTrimString, $case)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getDatabasePlatform()
    {
        return $this->driver->getDatabasePlatform();
    }

    /**
     * {@inheritDoc}
     */
    public function getSchemaManager(DBALConnection $conn, AbstractPlatform $platform)
    {
        return $this->driver->getSchemaManager($conn, $platform);
    }

    public function getExceptionConverter(): ExceptionConverter
    {
        return $this->driver->getExceptionConverter();
    }
}
