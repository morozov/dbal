<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Sharding\ShardChoser;

use Doctrine\DBAL\Sharding\PoolingShardConnection;

/**
 * Given a distribution value this shard-choser strategy will pick the shard to
 * connect to for retrieving rows with the distribution value.
 */
interface ShardChoser
{
    /**
     * Picks a shard for the given distribution value.
     */
    public function pickShard(string $distributionValue, PoolingShardConnection $conn) : string;
}
