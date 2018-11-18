<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Sharding;

use Doctrine\DBAL\DBALException;

/**
 * Sharding related Exceptions
 */
class ShardingException extends DBALException
{
    public static function notImplemented() : self
    {
        return new self('This functionality is not implemented with this sharding provider.', 1331557937);
    }

    public static function missingDefaultFederationName() : self
    {
        return new self('SQLAzure requires a federation name to be set during sharding configuration.', 1332141280);
    }

    public static function missingDefaultDistributionKey() : self
    {
        return new self('SQLAzure requires a distribution key to be set during sharding configuration.', 1332141329);
    }

    public static function activeTransaction() : self
    {
        return new self('Cannot switch shard during an active transaction.', 1332141766);
    }

    public static function missingDistributionType() : self
    {
        return new self("You have to specify a sharding distribution type such as 'integer', 'string', 'guid'.");
    }
}
