<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Tests\Functional\Schema;

use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Tests\FunctionalTestCase;

class ComparatorTest extends FunctionalTestCase
{
    private AbstractSchemaManager $schemaManager;

    protected function setUp(): void
    {
        $this->schemaManager = $this->connection->createSchemaManager();
    }

    /**
     * @param mixed $value
     *
     * @dataProvider defaultValueProvider
     */
    public function testDefaultValueComparison(string $type, $value): void
    {
        $table = new Table('default_value');
        $table->addColumn('test', $type, ['default' => $value]);

        $this->dropAndCreateTable($table);

        $onlineTable = $this->schemaManager->listTableDetails('default_value');

        self::assertNull(
            $this->schemaManager->createComparator()
                ->diffTable($table, $onlineTable)
        );
    }

    /**
     * @return iterable<mixed[]>
     */
    public static function defaultValueProvider(): iterable
    {
        return [
            ['integer', 1],
            ['boolean', false],
        ];
    }

    public function testImplicitIndexDoesDoesNotProduceDiff(): void
    {
        $table1 = new Table('t1');
        $table1->addColumn('id', 'integer');
        $table1->addIndex(['id'], 'id');

        $table2 = new Table('t2');
        $table2->addColumn('id', 'integer');
        $table2->addColumn('t1_id', 'integer');
        $table2->addForeignKeyConstraint('t1', ['t1_id'], ['id']);

        $sm = $this->connection->createSchemaManager();
        $sm->tryMethod('dropTable', $table2->getName());
        $sm->tryMethod('dropTable', $table1->getName());
        $sm->createTable($table1);
        $sm->createTable($table2);

        $table2Online = $sm->listTableDetails('t2');
        $comparator   = $sm->createComparator();
        self::assertNull($comparator->diffTable($table2Online, $table2));
    }
}
