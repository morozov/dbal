<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Tests\Functional\Ticket;

use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Tests\FunctionalTestCase;

class DBAL510Test extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if ($this->connection->getDatabasePlatform()->getName() === 'postgresql') {
            return;
        }

        self::markTestSkipped('PostgreSQL Only test');
    }

    public function testSearchPathSchemaChanges(): void
    {
        $table = new Table('dbal510tbl');
        $table->addColumn('id', 'integer');
        $table->setPrimaryKey(['id']);

        $this->connection->createSchemaManager()->createTable($table);

        $onlineTable = $this->connection->createSchemaManager()->listTableDetails('dbal510tbl');

        $comparator = new Comparator();
        $diff       = $comparator->diffTable($onlineTable, $table);

        self::assertNull($diff);
    }
}
