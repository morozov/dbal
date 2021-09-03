<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Event;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Name;

/**
 * Event Arguments used when the SQL query for dropping tables are generated inside {@link AbstractPlatform}.
 */
class SchemaDropTableEventArgs extends SchemaEventArgs
{
    private Name $tableName;

    private AbstractPlatform $platform;

    private ?string $sql = null;

    public function __construct(Name $tableName, AbstractPlatform $platform)
    {
        $this->tableName = $tableName;
        $this->platform  = $platform;
    }

    public function getTableName(): Name
    {
        return $this->tableName;
    }

    public function getPlatform(): AbstractPlatform
    {
        return $this->platform;
    }

    /**
     * @return $this
     */
    public function setSql(string $sql): self
    {
        $this->sql = $sql;

        return $this;
    }

    public function getSql(): ?string
    {
        return $this->sql;
    }
}
