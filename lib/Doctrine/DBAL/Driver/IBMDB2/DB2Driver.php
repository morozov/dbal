<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Driver\IBMDB2;

use Doctrine\DBAL\Driver\AbstractDB2Driver;
use Doctrine\DBAL\Driver\Connection;

/**
 * IBM DB2 Driver.
 */
class DB2Driver extends AbstractDB2Driver
{
    /**
     * {@inheritdoc}
     */
    public function connect(array $params, ?string $username = null, ?string $password = null, array $driverOptions = []) : Connection
    {
        if (! isset($params['protocol'])) {
            $params['protocol'] = 'TCPIP';
        }

        if ($params['host'] !== 'localhost' && $params['host'] !== '127.0.0.1') {
            // if the host isn't localhost, use extended connection params
            $params['dbname'] = 'DRIVER={IBM DB2 ODBC DRIVER}' .
                     ';DATABASE=' . $params['dbname'] .
                     ';HOSTNAME=' . $params['host'] .
                     ';PROTOCOL=' . $params['protocol'] .
                     ';UID=' . $username .
                     ';PWD=' . $password . ';';
            if (isset($params['port'])) {
                $params['dbname'] .= 'PORT=' . $params['port'];
            }

            $username = null;
            $password = null;
        }

        return new DB2Connection($params, (string) $username, (string) $password, $driverOptions);
    }

    /**
     * {@inheritdoc}
     */
    public function getName() : string
    {
        return 'ibm_db2';
    }
}
