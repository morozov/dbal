<?php

declare(strict_types=1);

namespace Doctrine\DBAL\SQLCommenter;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware as MiddlewareInterface;

final class Middleware implements MiddlewareInterface
{
    /** @var string */
    private $comment;

    public function __construct(string $comment)
    {
        $this->comment = $comment;
    }

    public function wrap(DriverInterface $driver): DriverInterface
    {
        return new Driver($driver, $this->comment);
    }
}
