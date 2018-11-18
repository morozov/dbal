<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Sharding\ShardChoser;

use Doctrine\DBAL\Sharding\PoolingShardConnection;

/**
 * The MultiTenant Shard choser assumes that the distribution value directly
 * maps to the shard id.
 */
class MultiTenantShardChoser implements ShardChoser
{
    /**
     * {@inheritdoc}
     */
    public function pickShard(string $distributionValue, PoolingShardConnection $conn) : string
    {
        return $distributionValue;
    }
}
