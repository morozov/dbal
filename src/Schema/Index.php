<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;

use function array_filter;
use function array_keys;
use function array_shift;
use function count;
use function strtolower;

class Index
{
    private Name $name;

    /**
     * The names of the columns the index is associated with.
     *
     * @var list<UnqualifiedName>
     */
    protected array $columnNames = [];

    protected bool $_isUnique = false;

    protected bool $_isPrimary = false;

    /**
     * Platform specific flags for indexes.
     *
     * @var array<string, true>
     */
    protected array $_flags = [];

    /**
     * Platform specific options
     *
     * @todo $_flags should eventually be refactored into options
     * @var array<string, mixed>
     */
    private array $options;

    /**
     * @param list<UnqualifiedName> $columnNames
     * @param array<int, string>    $flags
     * @param array<string, mixed>  $options
     */
    public function __construct(
        Name $name,
        array $columnNames,
        bool $isUnique = false,
        bool $isPrimary = false,
        array $flags = [],
        array $options = []
    ) {
        $this->name        = $name;
        $this->columnNames = $columnNames;
        $this->_isUnique   = $isUnique || $isPrimary;
        $this->_isPrimary  = $isPrimary;
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
        return $this->columnNames;
    }

    /**
     * @return list<string>
     */
    public function getQuotedColumns(AbstractPlatform $platform): array
    {
        $subParts = $platform->supportsColumnLengthIndexes() && $this->hasOption('lengths')
            ? $this->getOption('lengths') : [];

        $columns = [];

        foreach ($this->columnNames as $column) {
            $length = array_shift($subParts);

            $quotedColumn = $column->getQuotedName($platform);

            if ($length !== null) {
                $quotedColumn .= '(' . $length . ')';
            }

            $columns[] = $quotedColumn;
        }

        return $columns;
    }

    /**
     * Is the index neither unique nor primary key?
     */
    public function isSimpleIndex(): bool
    {
        return ! $this->_isPrimary && ! $this->_isUnique;
    }

    public function isUnique(): bool
    {
        return $this->_isUnique;
    }

    public function isPrimary(): bool
    {
        return $this->_isPrimary;
    }

    /**
     * Checks if this index exactly spans the given column names in the correct order.
     *
     * @param array<int, string> $columnNames
     */
    public function spansColumns(array $columnNames): bool
    {
        $columns         = $this->getColumnNames();
        $numberOfColumns = count($columnNames);
        $sameColumns     = true;

        for ($i = 0; $i < $numberOfColumns; $i++) {
            if (
                isset($columnNames[$i])
                && $columns[$i] === $columnNames[$i]
            ) {
                continue;
            }

            $sameColumns = false;
        }

        return $sameColumns;
    }

    /**
     * Checks if the other index already fulfills all the indexing and constraint needs of the current one.
     */
    public function isFullfilledBy(Index $other): bool
    {
        // allow the other index to be equally large only. It being larger is an option
        // but it creates a problem with scenarios of the kind PRIMARY KEY(foo,bar) UNIQUE(foo)
        if (count($other->getColumnNames()) !== count($this->getColumnNames())) {
            return false;
        }

        // Check if columns are the same, and even in the same order
        $sameColumns = $this->spansColumns($other->getColumnNames());

        if ($sameColumns) {
            if (! $this->samePartialIndex($other)) {
                return false;
            }

            if (! $this->hasSameColumnLengths($other)) {
                return false;
            }

            if (! $this->isUnique() && ! $this->isPrimary()) {
                // this is a special case: If the current key is neither primary or unique, any unique or
                // primary key will always have the same effect for the index and there cannot be any constraint
                // overlaps. This means a primary or unique index can always fulfill the requirements of just an
                // index that has no constraints.
                return true;
            }

            if ($other->isPrimary() !== $this->isPrimary()) {
                return false;
            }

            return $other->isUnique() === $this->isUnique();
        }

        return false;
    }

    /**
     * Detects if the other index is a non-unique, non-primary index that can be overwritten by this one.
     */
    public function overrules(Index $other): bool
    {
        if ($other->isPrimary()) {
            return false;
        }

        if ($this->isSimpleIndex() && $other->isUnique()) {
            return false;
        }

        return $this->spansColumns($other->getColumnNames())
            && ($this->isPrimary() || $this->isUnique())
            && $this->samePartialIndex($other);
    }

    /**
     * Returns platform specific flags for indexes.
     *
     * @return array<int, string>
     */
    public function getFlags(): array
    {
        return array_keys($this->_flags);
    }

    /**
     * Adds Flag for an index that translates to platform specific handling.
     *
     * @example $index->addFlag('CLUSTERED')
     */
    public function addFlag(string $flag): self
    {
        $this->_flags[strtolower($flag)] = true;

        return $this;
    }

    /**
     * Does this index have a specific flag?
     */
    public function hasFlag(string $flag): bool
    {
        return isset($this->_flags[strtolower($flag)]);
    }

    /**
     * Removes a flag.
     */
    public function removeFlag(string $flag): void
    {
        unset($this->_flags[strtolower($flag)]);
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

    /**
     * Return whether the two indexes have the same partial index
     */
    private function samePartialIndex(Index $other): bool
    {
        if (
            $this->hasOption('where')
            && $other->hasOption('where')
            && $this->getOption('where') === $other->getOption('where')
        ) {
            return true;
        }

        return ! $this->hasOption('where') && ! $other->hasOption('where');
    }

    /**
     * Returns whether the index has the same column lengths as the other
     */
    private function hasSameColumnLengths(self $other): bool
    {
        $filter = static function (?int $length): bool {
            return $length !== null;
        };

        return array_filter($this->options['lengths'] ?? [], $filter)
            === array_filter($other->options['lengths'] ?? [], $filter);
    }
}
