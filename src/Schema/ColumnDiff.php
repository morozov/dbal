<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema;

use Doctrine\DBAL\Schema\Name\UnqualifiedName;

use function in_array;

/**
 * Represents the change of a column.
 */
class ColumnDiff
{
    public UnqualifiedName $oldColumnName;

    public Column $column;

    /** @var array<int, string> */
    public array $changedProperties;

    public Column $fromColumn;

    /**
     * @param array<string> $changedProperties
     */
    public function __construct(
        string $oldColumnName,
        Column $column,
        array $changedProperties,
        Column $fromColumn
    ) {
        $this->oldColumnName     = $oldColumnName;
        $this->column            = $column;
        $this->changedProperties = $changedProperties;
        $this->fromColumn        = $fromColumn;
    }

    public function hasChanged(string $propertyName): bool
    {
        return in_array($propertyName, $this->changedProperties, true);
    }

    public function getOldColumnName(): UnqualifiedName
    {
        return $this->oldColumnName;
    }
}
