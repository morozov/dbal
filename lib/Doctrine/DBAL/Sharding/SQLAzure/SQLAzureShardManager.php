<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Sharding\SQLAzure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Sharding\ShardingException;
use Doctrine\DBAL\Sharding\ShardManager;
use Doctrine\DBAL\Types\Type;
use RuntimeException;
use function sprintf;

/**
 * Sharding using the SQL Azure Federations support.
 */
class SQLAzureShardManager implements ShardManager
{
    /** @var string */
    private $federationName;

    /** @var bool */
    private $filteringEnabled;

    /** @var string */
    private $distributionKey;

    /** @var string */
    private $distributionType;

    /** @var Connection */
    private $conn;

    /** @var string|null */
    private $currentDistributionValue;

    /**
     * @throws ShardingException
     */
    public function __construct(Connection $conn)
    {
        $this->conn = $conn;
        $params     = $conn->getParams();

        if (! isset($params['sharding']['federationName'])) {
            throw ShardingException::missingDefaultFederationName();
        }

        if (! isset($params['sharding']['distributionKey'])) {
            throw ShardingException::missingDefaultDistributionKey();
        }

        if (! isset($params['sharding']['distributionType'])) {
            throw ShardingException::missingDistributionType();
        }

        $this->federationName   = $params['sharding']['federationName'];
        $this->distributionKey  = $params['sharding']['distributionKey'];
        $this->distributionType = $params['sharding']['distributionType'];
        $this->filteringEnabled = (bool) ($params['sharding']['filteringEnabled'] ?? false);
    }

    /**
     * Gets the name of the federation.
     */
    public function getFederationName() : string
    {
        return $this->federationName;
    }

    /**
     * Gets the distribution key.
     */
    public function getDistributionKey() : string
    {
        return $this->distributionKey;
    }

    /**
     * Gets the Doctrine Type name used for the distribution.
     */
    public function getDistributionType() : string
    {
        return $this->distributionType;
    }

    /**
     * Sets Enabled/Disable filtering on the fly.
     */
    public function setFilteringEnabled(bool $flag) : void
    {
        $this->filteringEnabled = $flag;
    }

    /**
     * {@inheritDoc}
     */
    public function selectGlobal() : void
    {
        if ($this->conn->isTransactionActive()) {
            throw ShardingException::activeTransaction();
        }

        $sql = 'USE FEDERATION ROOT WITH RESET';
        $this->conn->exec($sql);
        $this->currentDistributionValue = null;
    }

    /**
     * {@inheritDoc}
     */
    public function selectShard(string $distributionValue) : void
    {
        if ($this->conn->isTransactionActive()) {
            throw ShardingException::activeTransaction();
        }

        $platform = $this->conn->getDatabasePlatform();
        $sql      = sprintf(
            'USE FEDERATION %s (%s = %s) WITH RESET, FILTERING = %s;',
            $platform->quoteIdentifier($this->federationName),
            $platform->quoteIdentifier($this->distributionKey),
            $this->conn->quote($distributionValue),
            ($this->filteringEnabled ? 'ON' : 'OFF')
        );

        $this->conn->exec($sql);
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
        $sql = 'SELECT member_id as id,
                      distribution_name as distribution_key,
                      CAST(range_low AS CHAR) AS rangeLow,
                      CAST(range_high AS CHAR) AS rangeHigh
                      FROM sys.federation_member_distributions d
                      INNER JOIN sys.federations f ON f.federation_id = d.federation_id
                      WHERE f.name = ' . $this->conn->quote($this->federationName);

        return $this->conn->fetchAll($sql);
    }

     /**
      * {@inheritDoc}
      */
    public function queryAll(string $sql, array $params = [], array $types = []) : array
    {
        $shards = $this->getShards();
        if (! $shards) {
            throw new RuntimeException('No shards found for ' . $this->federationName);
        }

        $result          = [];
        $oldDistribution = $this->getCurrentDistributionValue();

        foreach ($shards as $shard) {
            $this->selectShard($shard['rangeLow']);
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

    /**
     * Splits Federation at a given distribution value.
     *
     * @param mixed $splitDistributionValue
     */
    public function splitFederation($splitDistributionValue) : void
    {
        $type = Type::getType($this->distributionType);

        $sql = 'ALTER FEDERATION ' . $this->getFederationName() . ' ' .
               'SPLIT AT (' . $this->getDistributionKey() . ' = ' .
               $this->conn->quote($splitDistributionValue) . ')';
        $this->conn->exec($sql);
    }
}
