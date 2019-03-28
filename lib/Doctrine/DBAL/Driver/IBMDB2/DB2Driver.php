<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Driver\IBMDB2;

use Doctrine\DBAL\Driver\AbstractDB2Driver;
use Doctrine\DBAL\Driver\Connection;
use function implode;
use function sprintf;

/**
 * IBM DB2 Driver.
 */
class DB2Driver extends AbstractDB2Driver
{
    /**
     * {@inheritdoc}
     */
    public function connect(
        array $params,
        array $driverOptions = []
    ) : Connection {
        if (! isset($params['protocol'])) {
            $params['protocol'] = 'TCPIP';
        }

        // if the host isn't localhost, use extended connection params
        if ($params['host'] !== 'localhost' && $params['host'] !== '127.0.0.1') {
            $dsnParams = [
                'DRIVER' => '{IBM DB2 ODBC DRIVER}',
                'DATABASE' => $params['dbname'],
                'HOSTNAME' => $params['host'],
                'PROTOCOL' => $params['protocol'],
            ];

            if (isset($params['username'])) {
                $dsnParams['UID'] = $params['username'];
            }

            if (isset($params['password'])) {
                $dsnParams['PWD'] = $params['password'];
            }

            if (isset($params['port'])) {
                $dsnParams['PORT'] = $params['port'];
            }

            $pairs = [];

            foreach ($dsnParams as $param => $value) {
                $pairs[] = sprintf('%s=%s', $param, $value);
            }

            $params['dbname'] = implode(';', $pairs);

            unset($params['username'], $params['password']);
        }

        return new DB2Connection($params, $driverOptions);
    }

    /**
     * {@inheritdoc}
     */
    public function getName() : string
    {
        return 'ibm_db2';
    }
}
