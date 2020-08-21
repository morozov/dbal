<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Logging\DebugStack;
use Exception;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Throwable;

use function array_map;
use function array_reverse;
use function count;
use function get_class;
use function implode;
use function is_object;
use function is_scalar;
use function strpos;
use function var_export;

use const PHP_EOL;

abstract class FunctionalTestCase extends TestCase
{
    /** @var Connection */
    protected $connection;

    protected function tearDown(): void
    {
        while ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
    }
}
