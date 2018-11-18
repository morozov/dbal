<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Cache;

use Doctrine\DBAL\DBALException;

class CacheException extends DBALException
{
    public static function noCacheKey() : self
    {
        return new self('No cache key was set.');
    }

    public static function noResultDriverConfigured() : self
    {
        return new self('Trying to cache a query but no result driver is configured.');
    }
}
