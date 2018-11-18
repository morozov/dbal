<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Event;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\TableDiff;
use function array_merge;
use function is_array;

/**
 * Event Arguments used when SQL queries for removing table columns are generated inside Doctrine\DBAL\Platform\*Platform.
 */
class SchemaAlterTableRemoveColumnEventArgs extends SchemaEventArgs
{
    /** @var Column */
    private $column;

    /** @var TableDiff */
    private $tableDiff;

    /** @var AbstractPlatform */
    private $platform;

    /** @var string[] */
    private $sql = [];

    public function __construct(Column $column, TableDiff $tableDiff, AbstractPlatform $platform)
    {
        $this->column    = $column;
        $this->tableDiff = $tableDiff;
        $this->platform  = $platform;
    }

    public function getColumn() : Column
    {
        return $this->column;
    }

    public function getTableDiff() : TableDiff
    {
        return $this->tableDiff;
    }

    public function getPlatform() : AbstractPlatform
    {
        return $this->platform;
    }

    /**
     * @param string|string[] $sql
     */
    public function addSql($sql) : self
    {
        if (is_array($sql)) {
            $this->sql = array_merge($this->sql, $sql);
        } else {
            $this->sql[] = $sql;
        }

        return $this;
    }

    /**
     * @return string[]
     */
    public function getSql() : array
    {
        return $this->sql;
    }
}
