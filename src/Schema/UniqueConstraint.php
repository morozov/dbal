<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema;

use Doctrine\DBAL\Schema\Name\UnqualifiedName;

use function array_keys;
use function strtolower;

/**
 * Class for a unique constraint.
 */
class UniqueConstraint
{
    private Name $name;

    /**
     * The column names the unique constraint is associated with.
     *
     * @var NameSet<UnqualifiedName>
     */
    protected NameSet $columnNames;

    /**
     * Platform specific flags
     *
     * @var array<string, true>
     */
    protected array $flags = [];

    /**
     * Platform specific options
     *
     * @var array<string, mixed>
     */
    private array $options;

    /**
     * @param list<Name>           $columnNames
     * @param array<string>        $flags
     * @param array<string, mixed> $options
     */
    public function __construct(Name $name, array $columnNames, array $flags = [], array $options = [])
    {
        $this->name        = $name;
        $this->columnNames = new NameSet($columnNames);
        $this->options     = $options;

        foreach ($flags as $flag) {
            $this->addFlag($flag);
        }
    }

    public function getName(): Name
    {
        return $this->name;
    }

    /**
     * {@inheritdoc}
     */
    public function getColumnNames(): array
    {
        return $this->columnNames->toArray();
    }

    /**
     * Returns platform specific flags for unique constraint.
     *
     * @return array<int, string>
     */
    public function getFlags(): array
    {
        return array_keys($this->flags);
    }

    /**
     * Adds flag for a unique constraint that translates to platform specific handling.
     *
     * @return $this
     *
     * @example $uniqueConstraint->addFlag('CLUSTERED')
     */
    public function addFlag(string $flag): self
    {
        $this->flags[strtolower($flag)] = true;

        return $this;
    }

    /**
     * Does this unique constraint have a specific flag?
     */
    public function hasFlag(string $flag): bool
    {
        return isset($this->flags[strtolower($flag)]);
    }

    /**
     * Removes a flag.
     */
    public function removeFlag(string $flag): void
    {
        unset($this->flags[strtolower($flag)]);
    }

    public function hasOption(string $name): bool
    {
        return isset($this->options[strtolower($name)]);
    }

    /**
     * @return mixed
     */
    public function getOption(string $name)
    {
        return $this->options[strtolower($name)];
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    protected function addColumnName(UnqualifiedName $columnName): void
    {
        $this->columnNames[] = $columnName;
    }
}
