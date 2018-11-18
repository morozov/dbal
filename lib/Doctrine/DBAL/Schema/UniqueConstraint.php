<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use InvalidArgumentException;
use function array_keys;
use function array_map;
use function is_string;
use function strtolower;

/**
 * Class for a unique constraint.
 */
class UniqueConstraint extends AbstractAsset implements Constraint
{
    /**
     * Asset identifier instances of the column names the unique constraint is associated with.
     * array($columnName => Identifier)
     *
     * @var Identifier[]
     */
    protected $columns = [];

    /**
     * Platform specific flags
     * array($flagName => true)
     *
     * @var true[]
     */
    protected $flags = [];

    /**
     * Platform specific options
     *
     * @var mixed[]
     */
    private $options = [];

    /**
     * @param string[] $columns
     * @param string[] $flags
     * @param mixed[]  $options
     */
    public function __construct(?string $indexName, array $columns, array $flags = [], array $options = [])
    {
        if ($indexName !== null) {
            $this->_setName($indexName);
        }

        $this->options = $options;

        foreach ($columns as $column) {
            $this->_addColumn($column);
        }

        foreach ($flags as $flag) {
            $this->addFlag($flag);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getColumns() : array
    {
        return array_keys($this->columns);
    }

    /**
     * {@inheritdoc}
     */
    public function getQuotedColumns(AbstractPlatform $platform) : array
    {
        $columns = [];

        foreach ($this->columns as $column) {
            $columns[] = $column->getQuotedName($platform);
        }

        return $columns;
    }

    /**
     * @return string[]
     */
    public function getUnquotedColumns() : array
    {
        return array_map([$this, 'trimQuotes'], $this->getColumns());
    }

    /**
     * Returns platform specific flags for unique constraint.
     *
     * @return string[]
     */
    public function getFlags() : array
    {
        return array_keys($this->flags);
    }

    /**
     * Adds flag for a unique constraint that translates to platform specific handling.
     *
     * @example $uniqueConstraint->addFlag('CLUSTERED')
     */
    public function addFlag(string $flag) : self
    {
        $this->flags[strtolower($flag)] = true;

        return $this;
    }

    /**
     * Does this unique constraint have a specific flag?
     */
    public function hasFlag(string $flag) : bool
    {
        return isset($this->flags[strtolower($flag)]);
    }

    /**
     * Removes a flag.
     */
    public function removeFlag(string $flag) : void
    {
        unset($this->flags[strtolower($flag)]);
    }

    public function hasOption(string $name) : bool
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
     * @return mixed[]
     */
    public function getOptions() : array
    {
        return $this->options;
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function _addColumn(string $column) : void
    {
        if (! is_string($column)) {
            throw new InvalidArgumentException('Expecting a string as Index Column');
        }

        $this->columns[$column] = new Identifier($column);
    }
}
