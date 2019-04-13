<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Sharding\SQLAzure\Schema;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\Visitor\Visitor;
use RuntimeException;
use function in_array;

/**
 * Converts a single tenant schema into a multi-tenant schema for SQL Azure
 * Federations under the following assumptions:
 *
 * - Every table is part of the multi-tenant application, only explicitly
 *   excluded tables are non-federated. The behavior of the tables being in
 *   global or federated database is undefined. It depends on you selecting a
 *   federation before DDL statements or not.
 * - Every Primary key of a federated table is extended by another column
 *   'tenant_id' with a default value of the SQLAzure function
 *   `federation_filtering_value('tenant_id')`.
 * - You always have to work with `filtering=On` when using federations with this
 *   multi-tenant approach.
 * - Primary keys are either using globally unique ids (GUID, Table Generator)
 *   or you explicitly add the tenant_id in every UPDATE or DELETE statement
 *   (otherwise they will affect the same-id rows from other tenants as well).
 *   SQLAzure throws errors when you try to create IDENTIY columns on federated
 *   tables.
 */
class MultiTenantVisitor implements Visitor
{
    /** @var string[] */
    private $excludedTables = [];

    /** @var string */
    private $tenantColumnName;

    /** @var string */
    private $tenantColumnType = 'integer';

    /**
     * Name of the federation distribution, defaulting to the tenantColumnName
     * if not specified.
     *
     * @var string
     */
    private $distributionName;

    /**
     * @param string[] $excludedTables
     */
    public function __construct(array $excludedTables = [], string $tenantColumnName = 'tenant_id', ?string $distributionName = null)
    {
        $this->excludedTables   = $excludedTables;
        $this->tenantColumnName = $tenantColumnName;
        $this->distributionName = $distributionName ?: $tenantColumnName;
    }

    /**
     * {@inheritdoc}
     *
     * @throws SchemaException
     */
    public function acceptTable(Table $table) : void
    {
        if (in_array($table->getName(), $this->excludedTables)) {
            return;
        }

        $table->addColumn($this->tenantColumnName, $this->tenantColumnType, [
            'default' => "federation_filtering_value('" . $this->distributionName . "')",
        ]);

        $clusteredIndex = $this->getClusteredIndex($table);

        $indexColumns   = $clusteredIndex->getColumns();
        $indexColumns[] = $this->tenantColumnName;

        if ($clusteredIndex->isPrimary()) {
            $table->dropPrimaryKey();
            $table->setPrimaryKey($indexColumns);
        } else {
            $name = $clusteredIndex->getName();

            if ($name === null) {
                throw SchemaException::namedIndexRequired($table, $clusteredIndex);
            }

            $table->dropIndex($name);
            $table->addIndex($indexColumns, $name);
            $table->getIndex($name)->addFlag('clustered');
        }
    }

    /**
     * @throws RuntimeException
     */
    private function getClusteredIndex(Table $table) : Index
    {
        foreach ($table->getIndexes() as $index) {
            if ($index->isPrimary() && ! $index->hasFlag('nonclustered')) {
                return $index;
            }

            if ($index->hasFlag('clustered')) {
                return $index;
            }
        }
        throw new RuntimeException('No clustered index found on table ' . $table->getName());
    }

    /**
     * {@inheritdoc}
     */
    public function acceptSchema(Schema $schema) : void
    {
    }

    /**
     * {@inheritdoc}
     */
    public function acceptColumn(Table $table, Column $column) : void
    {
    }

    /**
     * {@inheritdoc}
     */
    public function acceptForeignKey(Table $localTable, ForeignKeyConstraint $fkConstraint) : void
    {
    }

    /**
     * {@inheritdoc}
     */
    public function acceptIndex(Table $table, Index $index) : void
    {
    }

    /**
     * {@inheritdoc}
     */
    public function acceptSequence(Sequence $sequence) : void
    {
    }
}
