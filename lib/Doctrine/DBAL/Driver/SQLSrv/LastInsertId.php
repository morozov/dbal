<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Driver\SQLSrv;

/**
 * Last Id Data Container.
 */
class LastInsertId
{
    /** @var string */
    private $id = '0';

    public function setId(string $id) : void
    {
        $this->id = $id;
    }

    public function getId() : string
    {
        return $this->id;
    }
}
