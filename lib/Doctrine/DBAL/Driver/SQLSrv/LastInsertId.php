<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Driver\SQLSrv;

/**
 * Last Id Data Container.
 */
class LastInsertId
{
    /** @var int|null */
    private $id;

    public function setId(?int $id) : void
    {
        $this->id = $id;
    }

    public function getId() : ?int
    {
        return $this->id;
    }
}
