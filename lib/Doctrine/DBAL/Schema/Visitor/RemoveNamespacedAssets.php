<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema\Visitor;

use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Schema\Table;

/**
 * Removes assets from a schema that are not in the default namespace.
 *
 * Some databases such as MySQL support cross databases joins, but don't
 * allow to call DDLs to a database from another connected database.
 * Before a schema is serialized into SQL this visitor can cleanup schemas with
 * non default namespaces.
 *
 * This visitor filters all these non-default namespaced tables and sequences
 * and removes them from the SChema instance.
 */
class RemoveNamespacedAssets extends AbstractVisitor
{
    /** @var Schema */
    private $schema;

    /**
     * {@inheritdoc}
     */
    public function acceptSchema(Schema $schema) : void
    {
        $this->schema = $schema;
    }

    /**
     * {@inheritdoc}
     */
    public function acceptTable(Table $table) : void
    {
        if ($table->isInDefaultNamespace($this->schema->getName())) {
            return;
        }

        $this->schema->dropTable($table->getName());
    }

    /**
     * {@inheritdoc}
     */
    public function acceptSequence(Sequence $sequence) : void
    {
        if ($sequence->isInDefaultNamespace($this->schema->getName())) {
            return;
        }

        $this->schema->dropSequence($sequence->getName());
    }

    /**
     * {@inheritdoc}
     *
     * @throws SchemaException
     */
    public function acceptForeignKey(Table $localTable, ForeignKeyConstraint $fkConstraint) : void
    {
        // The table may already be deleted in a previous
        // RemoveNamespacedAssets#acceptTable call. Removing Foreign keys that
        // point to nowhere.
        if (! $this->schema->hasTable($fkConstraint->getForeignTableName())) {
            $this->removeForeignKey($localTable, $fkConstraint);

            return;
        }

        $foreignTable = $this->schema->getTable($fkConstraint->getForeignTableName());
        if ($foreignTable->isInDefaultNamespace($this->schema->getName())) {
            return;
        }

        $this->removeForeignKey($localTable, $fkConstraint);
    }

    /**
     * @throws SchemaException
     */
    private function removeForeignKey(Table $table, ForeignKeyConstraint $constraint) : void
    {
        $name = $constraint->getName();

        if ($name === null) {
            throw SchemaException::namedForeignKeyRequired($table, $constraint);
        }

        $table->removeForeignKey($name);
    }
}
