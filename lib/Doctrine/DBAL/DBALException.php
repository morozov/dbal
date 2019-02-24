<?php

declare(strict_types=1);

namespace Doctrine\DBAL;

use Doctrine\DBAL\Driver\DriverException as DriverExceptionInterface;
use Doctrine\DBAL\Driver\ExceptionConverterDriver;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Exception;
use Throwable;
use function array_map;
use function bin2hex;
use function get_class;
use function gettype;
use function implode;
use function is_object;
use function is_resource;
use function is_string;
use function json_encode;
use function preg_replace;
use function sprintf;

class DBALException extends Exception
{
    public static function notSupported(string $method) : self
    {
        return new self(sprintf("Operation '%s' is not supported by platform.", $method));
    }

    public static function invalidPlatformSpecified() : self
    {
        return new self(
            "Invalid 'platform' option specified, need to give an instance of " . AbstractPlatform::class . '.'
        );
    }

    /**
     * @param mixed $invalidPlatform
     */
    public static function invalidPlatformType($invalidPlatform) : self
    {
        if (is_object($invalidPlatform)) {
            return new self(
                sprintf(
                    "Option 'platform' must be a subtype of '%s', instance of '%s' given",
                    AbstractPlatform::class,
                    get_class($invalidPlatform)
                )
            );
        }

        return new self(
            sprintf(
                "Option 'platform' must be an object and subtype of '%s'. Got '%s'",
                AbstractPlatform::class,
                gettype($invalidPlatform)
            )
        );
    }

    /**
     * Returns a new instance for an invalid specified platform version.
     *
     * @param string $version        The invalid platform version given.
     * @param string $expectedFormat The expected platform version format.
     */
    public static function invalidPlatformVersionSpecified(string $version, string $expectedFormat) : self
    {
        return new self(
            sprintf(
                'Invalid platform version "%s" specified. ' .
                'The platform version has to be specified in the format: "%s".',
                $version,
                $expectedFormat
            )
        );
    }

    public static function invalidPdoInstance() : self
    {
        return new self(
            "The 'pdo' option was used in DriverManager::getConnection() but no " .
            'instance of PDO was given.'
        );
    }

    /**
     * @param string|null $url The URL that was provided in the connection parameters (if any).
     */
    public static function driverRequired(?string $url = null) : self
    {
        if ($url) {
            return new self(
                sprintf(
                    "The options 'driver' or 'driverClass' are mandatory if a connection URL without scheme " .
                    'is given to DriverManager::getConnection(). Given URL: %s',
                    $url
                )
            );
        }

        return new self("The options 'driver' or 'driverClass' are mandatory if no PDO " .
            'instance is given to DriverManager::getConnection().');
    }

    /**
     * @param string[] $knownDrivers
     */
    public static function unknownDriver(string $unknownDriverName, array $knownDrivers) : self
    {
        return new self("The given 'driver' " . $unknownDriverName . ' is unknown, ' .
            'Doctrine currently supports only the following drivers: ' . implode(', ', $knownDrivers));
    }

    /**
     * @param mixed[] $params
     */
    public static function driverExceptionDuringQuery(Driver $driver, Throwable $driverEx, string $sql, array $params = []) : self
    {
        $msg = "An exception occurred while executing '" . $sql . "'";
        if ($params) {
            $msg .= ' with params ' . self::formatParameters($params);
        }
        $msg .= ":\n\n" . $driverEx->getMessage();

        return static::wrapException($driver, $driverEx, $msg);
    }

    public static function driverException(Driver $driver, Throwable $driverEx) : self
    {
        return static::wrapException($driver, $driverEx, 'An exception occurred in driver: ' . $driverEx->getMessage());
    }

    private static function wrapException(Driver $driver, Throwable $driverEx, string $msg) : self
    {
        if ($driverEx instanceof DriverException) {
            return $driverEx;
        }
        if ($driver instanceof ExceptionConverterDriver && $driverEx instanceof DriverExceptionInterface) {
            return $driver->convertException($msg, $driverEx);
        }

        return new self($msg, 0, $driverEx);
    }

    /**
     * Returns a human-readable representation of an array of parameters.
     * This properly handles binary data by returning a hex representation.
     *
     * @param mixed[] $params
     */
    private static function formatParameters(array $params) : string
    {
        return '[' . implode(', ', array_map(static function ($param) : string {
            if (is_resource($param)) {
                return (string) $param;
            }

            $json = @json_encode($param);

            if (! is_string($json) || $json === 'null' && is_string($param)) {
                // JSON encoding failed, this is not a UTF-8 string.
                return sprintf('"%s"', preg_replace('/.{2}/', '\\x$0', bin2hex($param)));
            }

            return $json;
        }, $params)) . ']';
    }

    public static function invalidWrapperClass(string $wrapperClass) : self
    {
        return new self("The given 'wrapperClass' " . $wrapperClass . ' has to be a ' .
            'subtype of \Doctrine\DBAL\Connection.');
    }

    public static function invalidDriverClass(string $driverClass) : self
    {
        return new self("The given 'driverClass' " . $driverClass . ' has to implement the ' . Driver::class . ' interface.');
    }

    public static function invalidTableName(string $tableName) : self
    {
        return new self('Invalid table name specified: ' . $tableName);
    }

    public static function noColumnsSpecifiedForTable(string $tableName) : self
    {
        return new self('No columns specified for table ' . $tableName);
    }

    public static function limitOffsetInvalid() : self
    {
        return new self('Invalid Offset in Limit Query, it has to be larger than or equal to 0.');
    }

    public static function typeExists(string $name) : self
    {
        return new self('Type ' . $name . ' already exists.');
    }

    public static function unknownColumnType(string $name) : self
    {
        return new self('Unknown column type "' . $name . '" requested. Any Doctrine type that you use has ' .
            'to be registered with \Doctrine\DBAL\Types\Type::addType(). You can get a list of all the ' .
            'known types with \Doctrine\DBAL\Types\Type::getTypesMap(). If this error occurs during database ' .
            'introspection then you might have forgotten to register all database types for a Doctrine Type. Use ' .
            'AbstractPlatform#registerDoctrineTypeMapping() or have your custom types implement ' .
            'Type#getMappedDatabaseTypes(). If the type name is empty you might ' .
            'have a problem with the cache or forgot some mapping information.');
    }

    public static function typeNotFound(string $name) : self
    {
        return new self('Type to be overwritten ' . $name . ' does not exist.');
    }

    public static function invalidColumnIndex(int $index, int $count) : self
    {
        return new self(sprintf(
            'Invalid column index %d. The statement result contains %d column%s.',
            $index,
            $count,
            $count === 1 ? '' : 's'
        ));
    }
}
