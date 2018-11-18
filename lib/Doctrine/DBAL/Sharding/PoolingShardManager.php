<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Sharding;

use Doctrine\DBAL\Sharding\ShardChoser\ShardChoser;
use RuntimeException;

/**
 * Shard Manager for the Connection Pooling Shard Strategy
 */
class PoolingShardManager implements ShardManager
{
    /** @var PoolingShardConnection */
    private $conn;

    /** @var ShardChoser */
    private $choser;

    /** @var string|null */
    private $currentDistributionValue;

    public function __construct(PoolingShardConnection $conn)
    {
        $params       = $conn->getParams();
        $this->conn   = $conn;
        $this->choser = $params['shardChoser'];
    }

    /**
     * {@inheritDoc}
     */
    public function selectGlobal() : void
    {
        $this->conn->connect(0);
        $this->currentDistributionValue = null;
    }

    /**
     * {@inheritDoc}
     */
    public function selectShard(string $distributionValue) : void
    {
        $shardId = $this->choser->pickShard($distributionValue, $this->conn);
        $this->conn->connect($shardId);
        $this->currentDistributionValue = $distributionValue;
    }

    /**
     * {@inheritDoc}
     */
    public function getCurrentDistributionValue() : ?string
    {
        return $this->currentDistributionValue;
    }

    /**
     * {@inheritDoc}
     */
    public function getShards() : array
    {
        $params = $this->conn->getParams();
        $shards = [];

        foreach ($params['shards'] as $shard) {
            $shards[] = ['id' => $shard['id']];
        }

        return $shards;
    }

    /**
     * {@inheritDoc}
     *
     * @throws RuntimeException
     */
    public function queryAll(string $sql, array $params, array $types) : array
    {
        $shards = $this->getShards();
        if (! $shards) {
            throw new RuntimeException('No shards found.');
        }

        $result          = [];
        $oldDistribution = $this->getCurrentDistributionValue();

        foreach ($shards as $shard) {
            $this->conn->connect($shard['id']);
            foreach ($this->conn->fetchAll($sql, $params, $types) as $row) {
                $result[] = $row;
            }
        }

        if ($oldDistribution === null) {
            $this->selectGlobal();
        } else {
            $this->selectShard($oldDistribution);
        }

        return $result;
    }
}
