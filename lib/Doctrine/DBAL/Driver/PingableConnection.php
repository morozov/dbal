<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Driver;

/**
 * An interface for connections which support a "native" ping method.
 */
interface PingableConnection
{
    /**
     * Pings the database server to determine if the connection is still
     * available. Return true/false based on if that was successful or not.
     */
    public function ping() : bool;
}
