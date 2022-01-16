<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Platforms\MariaDB;

use Doctrine\DBAL\Platforms\MySQL\ColumnProvider as BaseColumnProvider;

use function preg_match;
use function strtr;

class ColumnProvider extends BaseColumnProvider
{
    /** @see https://mariadb.com/kb/en/library/string-literals/#escape-sequences */
    private const ESCAPE_SEQUENCES = [
        '\\0' => "\0",
        "\\'" => "'",
        '\\"' => '"',
        '\\b' => "\b",
        '\\n' => "\n",
        '\\r' => "\r",
        '\\t' => "\t",
        '\\Z' => "\x1a",
        '\\\\' => '\\',
        '\\%' => '%',
        '\\_' => '_',

        // Internally, MariaDB escapes single quotes using the standard syntax
        "''" => "'",
    ];

    /**
     * Return Doctrine/Mysql-compatible column default values for MariaDB 10.2.7+ servers.
     *
     * - Since MariaDb 10.2.7 column defaults stored in information_schema are now quoted
     *   to distinguish them from expressions (see MDEV-10134).
     * - CURRENT_TIMESTAMP, CURRENT_TIME, CURRENT_DATE are stored in information_schema
     *   as current_timestamp(), currdate(), currtime()
     * - Quoted 'NULL' is not enforced by MariaDB, it is technically possible to have
     *   null in some circumstances (see https://jira.mariadb.org/browse/MDEV-14053)
     * - \' is always stored as '' in information_schema (normalized)
     *
     * @link https://mariadb.com/kb/en/library/information-schema-columns-table/
     * @link https://jira.mariadb.org/browse/MDEV-13132
     */
    protected function getDefault(?string $default): ?string
    {
        if ($default === 'NULL' || $default === null) {
            return null;
        }

        if (preg_match('/^\'(.*)\'$/', $default, $matches) === 1) {
            return strtr($matches[1], self::ESCAPE_SEQUENCES);
        }

        switch ($default) {
            case 'current_timestamp()':
                return $this->platform->getCurrentTimestampSQL();

            case 'curdate()':
                return $this->platform->getCurrentDateSQL();

            case 'curtime()':
                return $this->platform->getCurrentTimeSQL();
        }

        return $default;
    }
}
