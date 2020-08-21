<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\DebugStack;
use LogicException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Runner\AfterSuccessfulTestHook;
use PHPUnit\Runner\BeforeTestHook;

final class Connector implements BeforeTestHook, AfterSuccessfulTestHook
{
    /** @var array<string, mixed> */
    private $params;

    /**
     * Shared connection when a TestCase is run alone (outside of it's functional suite)
     *
     * @var Connection|null
     */
    private $connection;

    /** @var DebugStack */
    protected $logger;

    /** @var self */
    private static $instance;

    /**
     * @param array<string, mixed> $params
     */
    public function __construct(array $params)
    {
        $this->params = $params;

        self::$instance = $this;
    }

    public static function connect(): Connection
    {
        if (self::$instance === null) {
            throw new LogicException('The hook is not registered. Please check PHPUnit configuration.');
        }

        return DriverManager::getConnection(self::$instance->params);
    }

    public function executeBeforeTest(string $test): void
    {
        $this->logger = new DebugStack();

        if (! isset(self::$sharedConnection)) {
            self::$sharedConnection = TestUtil::getConnection();
        }

        $this->connection = self::$sharedConnection;

        $this->connection->getConfiguration()->setSQLLogger($this->logger);
    }

    protected function onNotSuccessfulTest(Throwable $t): void
    {
        if ($t instanceof AssertionFailedError) {
            throw $t;
        }

        if (count($this->sqlLoggerStack->queries) > 0) {
            $queries = '';
            $i       = count($this->sqlLoggerStack->queries);
            foreach (array_reverse($this->sqlLoggerStack->queries) as $query) {
                $params   = array_map(static function ($p): string {
                    if (is_object($p)) {
                        return get_class($p);
                    }

                    if (is_scalar($p)) {
                        return "'" . $p . "'";
                    }

                    return var_export($p, true);
                }, $query['params'] ?? []);
                $queries .= $i . ". SQL: '" . $query['sql'] . "' Params: " . implode(', ', $params) . PHP_EOL;
                $i--;
            }

            $trace    = $t->getTrace();
            $traceMsg = '';
            foreach ($trace as $part) {
                if (! isset($part['file'])) {
                    continue;
                }

                if (strpos($part['file'], 'PHPUnit/') !== false) {
                    // Beginning with PHPUnit files we don't print the trace anymore.
                    break;
                }

                $traceMsg .= $part['file'] . ':' . $part['line'] . PHP_EOL;
            }

            $message = '[' . get_class($t) . '] ' . $t->getMessage() . PHP_EOL . PHP_EOL
                . 'With queries:' . PHP_EOL . $queries . PHP_EOL . 'Trace:' . PHP_EOL . $traceMsg;

            throw new Exception($message, (int) $t->getCode(), $t);
        }

        throw $t;
    }
    protected function resetSharedConn(): void
    {
        if (self::$sharedConnection === null) {
            return;
        }

        self::$sharedConnection->close();
        self::$sharedConnection = null;
    }
}
