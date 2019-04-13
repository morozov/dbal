<?php

declare(strict_types=1);

namespace Doctrine\Tests\DBAL\Functional\Driver;

use Doctrine\DBAL\Driver\PDOConnection;
use Doctrine\DBAL\Driver\PDOException;
use Doctrine\Tests\DbalFunctionalTestCase;
use PDO;
use function extension_loaded;
use function sprintf;

class PDOConnectionTest extends DbalFunctionalTestCase
{
    /**
     * The PDO driver connection under test.
     *
     * @var PDOConnection
     */
    protected $driverConnection;

    protected function setUp() : void
    {
        if (! extension_loaded('PDO')) {
            $this->markTestSkipped('PDO is not installed.');
        }

        parent::setUp();

        $this->driverConnection = $this->connection->getWrappedConnection();

        if ($this->driverConnection instanceof PDOConnection) {
            return;
        }

        $this->markTestSkipped('PDO connection only test.');
    }

    protected function tearDown() : void
    {
        $this->resetSharedConn();

        parent::tearDown();
    }

    public function testDoesNotRequireQueryForServerVersion() : void
    {
        self::assertFalse($this->driverConnection->requiresQueryForServerVersion());
    }

    public function testThrowsWrappedExceptionOnConstruct() : void
    {
        $this->expectException(PDOException::class);

        new PDOConnection('foo');
    }

    /**
     * @group DBAL-1022
     */
    public function testThrowsWrappedExceptionOnExec() : void
    {
        $this->expectException(PDOException::class);

        $this->driverConnection->exec('foo');
    }

    public function testThrowsWrappedExceptionOnPrepare() : void
    {
        if ($this->connection->getDriver()->getName() === 'pdo_sqlsrv') {
            $this->markTestSkipped('pdo_sqlsrv does not allow setting PDO::ATTR_EMULATE_PREPARES at connection level.');
        }

        // Emulated prepared statements have to be disabled for this test
        // so that PDO actually communicates with the database server to check the query.
        $this->driverConnection
            ->getWrappedConnection()
            ->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        $this->expectException(PDOException::class);

        $this->driverConnection->prepare('foo');

        // Some PDO adapters like PostgreSQL do not check the query server-side
        // even though emulated prepared statements are disabled,
        // so an exception is thrown only eventually.
        // Skip the test otherwise.
        $this->markTestSkipped(
            sprintf(
                'The PDO adapter %s does not check the query to be prepared server-side, ' .
                'so no assertions can be made.',
                $this->connection->getDriver()->getName()
            )
        );
    }

    public function testThrowsWrappedExceptionOnQuery() : void
    {
        $this->expectException(PDOException::class);

        $this->driverConnection->query('foo');
    }
}
