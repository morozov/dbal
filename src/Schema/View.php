<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema;

/**
 * Representation of a Database View.
 */
class View
{
    private string $sql;

    public function __construct(string $name, string $sql)
    {
        $this->_setName($name);
        $this->sql = $sql;
    }

    public function getSql(): string
    {
        return $this->sql;
    }
}
