<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Driver\PDOOracle;

use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Driver\AbstractOracleDriver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\PDOConnection;
use Doctrine\DBAL\Driver\PDOException;

/**
 * PDO Oracle driver.
 *
 * WARNING: This driver gives us segfaults in our testsuites on CLOB and other
 * stuff. PDO Oracle is not maintained by Oracle or anyone in the PHP community,
 * which leads us to the recommendation to use the "oci8" driver to connect
 * to Oracle instead.
 */
class Driver extends AbstractOracleDriver
{
    /**
     * {@inheritdoc}
     */
    public function connect(
        array $params,
        array $driverOptions = []
    ) : Connection {
        try {
            return new PDOConnection(
                $this->constructPdoDsn($params),
                $params['username'] ?? '',
                $params['password'] ?? '',
                $driverOptions
            );
        } catch (PDOException $e) {
            throw DBALException::driverException($this, $e);
        }
    }

    /**
     * Constructs the Oracle PDO DSN.
     *
     * @param mixed[] $params
     *
     * @return string The DSN.
     */
    private function constructPdoDsn(array $params) : string
    {
        $dsn = 'oci:dbname=' . $this->getEasyConnectString($params);

        if (isset($params['charset'])) {
            $dsn .= ';charset=' . $params['charset'];
        }

        return $dsn;
    }

    /**
     * {@inheritdoc}
     */
    public function getName() : string
    {
        return 'pdo_oracle';
    }
}
