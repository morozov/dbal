<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Tests\Functional\Types;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Tests\FunctionalTestCase;
use Doctrine\DBAL\Types\IntegerType;

use const PHP_INT_MAX;

class IntegerTest extends FunctionalTestCase
{
    public function testUnsignedPrecision(): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if (! $platform instanceof MySQLPlatform) {
            self::markTestSkipped('Currently only tested with MySQL');
        }

        $type = new IntegerType();

        self::assertNotSame(
            (string) PHP_INT_MAX,
            $type->convertToPHPValue(
                $this->connection->fetchOne('SELECT CAST(? AS UNSIGNED) + 1', [PHP_INT_MAX]),
                $platform
            )
        );
    }
}
