<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Tests\Functional\Schema;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Exception\UnspecifiedConstraintName;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Tests\FunctionalTestCase;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\DataProvider;

class ComparatorTest extends FunctionalTestCase
{
    private AbstractSchemaManager $schemaManager;

    protected function setUp(): void
    {
        $this->schemaManager = $this->connection->createSchemaManager();
    }

    #[DataProvider('defaultValueProvider')]
    public function testDefaultValueComparison(string $typeName, mixed $value): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if (
            $typeName === Types::TEXT && $platform instanceof AbstractMySQLPlatform
            && ! $platform instanceof MariaDBPlatform
        ) {
            // See https://dev.mysql.com/doc/relnotes/mysql/8.0/en/news-8-0-13.html#mysqld-8-0-13-data-types
            self::markTestSkipped('Oracle MySQL does not support default values on TEXT/BLOB columns until 8.0.13.');
        }

        $table = Table::editor()
            ->setUnquotedName('default_value')
            ->setColumns(
                Column::editor()
                    ->setUnquotedName('test')
                    ->setTypeName($typeName)
                    ->setDefaultValue($value)
                    ->create(),
            )
            ->create();

        $this->dropAndCreateTable($table);

        $onlineTable = $this->schemaManager->introspectTableByUnquotedName('default_value');

        self::assertTrue(
            $this->schemaManager->createComparator()
                ->compareTables($table, $onlineTable)
                ->isEmpty(),
        );
    }

    /** @return iterable<mixed[]> */
    public static function defaultValueProvider(): iterable
    {
        return [
            [Types::INTEGER, 1],
            [Types::BOOLEAN, false],
            [Types::TEXT, 'Doctrine'],
        ];
    }

    public function testDropUnnamedForeignKeyConstraint(): void
    {
        $this->dropTableIfExists('tree');

        $table1 = Table::editor()
            ->setUnquotedName('tree')
            ->setColumns(
                Column::editor()
                    ->setUnquotedName('id')
                    ->setTypeName(Types::INTEGER)
                    ->create(),
                Column::editor()
                    ->setUnquotedName('parent_id')
                    ->setTypeName(Types::INTEGER)
                    ->create(),
            )
            ->setPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()
                    ->setUnquotedColumnNames('id')
                    ->create(),
            )
            ->setForeignKeyConstraints(
                ForeignKeyConstraint::editor()
                    ->setUnquotedReferencingColumnNames('parent_id')
                    ->setUnquotedReferencedTableName('tree')
                    ->setUnquotedReferencedColumnNames('id')
                    ->create(),
            )
            ->create();

        $this->schemaManager->createTable($table1);

        $table1 = $this->schemaManager->introspectTableByUnquotedName('tree');

        $table2 = $table1->edit()
            ->setForeignKeyConstraints()
            ->create();

        $comparator = $this->schemaManager->createComparator();

        // SQLite does not automatically generate names for unnamed constraints
        if ($this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->expectException(UnspecifiedConstraintName::class);
        }

        $diff = $comparator->compareTables($table1, $table2);

        $this->schemaManager->alterTable($diff);

        $table2 = $this->schemaManager->introspectTableByUnquotedName('tree');

        self::assertEmpty($table2->getForeignKeys());
    }
}
