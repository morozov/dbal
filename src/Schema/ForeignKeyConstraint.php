<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema;

use Doctrine\DBAL\Schema\Name\UnqualifiedName;

use function strtoupper;

/**
 * An abstraction class for a foreign key constraint.
 */
class ForeignKeyConstraint
{
    private Name $name;

    /**
     * Asset identifier instances of the referencing table column names the foreign key constraint is associated with.
     *
     * @var list<UnqualifiedName>
     */
    protected array $localColumnNames;

    /**
     * Table or asset identifier instance of the referenced table name the foreign key constraint is associated with.
     */
    protected Name $foreignTableName;

    /**
     * Asset identifier instances of the referenced table column names the foreign key constraint is associated with.
     *
     * @var list<UnqualifiedName>
     */
    protected array $foreignColumnNames;

    /**
     * Options associated with the foreign key constraint.
     *
     * @var array<string, mixed>
     */
    protected array $_options;

    /**
     * Initializes the foreign key constraint.
     *
     * @param array<int, string>   $localColumnNames   Names of the referencing table columns.
     * @param Name                 $foreignTableName   Referenced table.
     * @param array<int, string>   $foreignColumnNames Names of the referenced table columns.
     * @param Name                 $name               Name of the foreign key constraint.
     * @param array<string, mixed> $options            Options associated with the foreign key constraint.
     */
    public function __construct(
        array $localColumnNames,
        Name $foreignTableName,
        array $foreignColumnNames,
        Name $name,
        array $options = []
    ) {
        $this->name = $name;

        $this->localColumnNames = $localColumnNames;
        $this->foreignTableName = $foreignTableName;

        $this->foreignColumnNames = $foreignColumnNames;
        $this->_options           = $options;
    }

    public function getName(): Name
    {
        return $this->name;
    }

    /**
     * Returns the names of the referencing table columns
     * the foreign key constraint is associated with.
     *
     * @return list<UnqualifiedName>
     */
    public function getLocalColumnNames(): array
    {
        return $this->localColumnNames;
    }

    /**
     * Returns the name of the referenced table
     * the foreign key constraint is associated with.
     */
    public function getForeignTableName(): Name
    {
        return $this->foreignTableName;
    }

    /**
     * Returns the names of the referenced table columns
     * the foreign key constraint is associated with.
     *
     * @return list<UnqualifiedName>
     */
    public function getForeignColumnNames(): array
    {
        return $this->foreignColumnNames;
    }

    /**
     * Returns whether or not a given option
     * is associated with the foreign key constraint.
     */
    public function hasOption(string $name): bool
    {
        return isset($this->_options[$name]);
    }

    /**
     * Returns an option associated with the foreign key constraint.
     *
     * @return mixed
     */
    public function getOption(string $name)
    {
        return $this->_options[$name];
    }

    /**
     * Returns the options associated with the foreign key constraint.
     *
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->_options;
    }

    /**
     * Returns the referential action for UPDATE operations
     * on the referenced table the foreign key constraint is associated with.
     */
    public function onUpdate(): ?string
    {
        return $this->onEvent('onUpdate');
    }

    /**
     * Returns the referential action for DELETE operations
     * on the referenced table the foreign key constraint is associated with.
     */
    public function onDelete(): ?string
    {
        return $this->onEvent('onDelete');
    }

    /**
     * Returns the referential action for a given database operation
     * on the referenced table the foreign key constraint is associated with.
     *
     * @param string $event Name of the database operation/event to return the referential action for.
     */
    private function onEvent(string $event): ?string
    {
        if (isset($this->_options[$event])) {
            $onEvent = strtoupper($this->_options[$event]);

            if ($onEvent !== 'NO ACTION' && $onEvent !== 'RESTRICT') {
                return $onEvent;
            }
        }

        return null;
    }

    /**
     * Checks whether this foreign key constraint intersects the given index columns.
     *
     * Returns `true` if at least one of this foreign key's local columns
     * matches one of the given index's columns, `false` otherwise.
     *
     * @param Index $index The index to be checked against.
     */
    public function intersectsIndexColumns(Index $index): bool
    {
        foreach ($index->getColumnNames() as $indexColumn) {
            foreach ($this->localColumnNames as $localColumn) {
                if ($indexColumn === $localColumn->getName()) {
                    return true;
                }
            }
        }

        return false;
    }
}
