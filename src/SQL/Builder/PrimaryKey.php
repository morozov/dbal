<?php

namespace Doctrine\DBAL\SQL\Builder;

use function array_merge;
use function implode;

final class PrimaryKey
{
    /** @var string */
    private $name;

    /** @var non-empty-list<string> */
    private $columnNames;

    /** @var array<string> */
    private $flags = ['PRIMARY KEY'];

    /**
     * @param non-empty-list<string> $columnNames
     * @param array<string>          $flags
     */
    public function __construct(string $name, array $columnNames, array $flags)
    {
        $this->name        = $name;
        $this->columnNames = $columnNames;
        $this->flags       = array_merge($this->flags, $flags);
    }

    public function toString(): string
    {
        $sql = implode(' ', $this->flags) . ' (' . implode(', ', $this->columnNames) . ')';

        if ($this->name !== '') {
            $sql = 'CONSTRAINT ' . $this->name . ' ' . $sql;
        }

        return $sql;
    }
}
