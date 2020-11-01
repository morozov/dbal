<?php

namespace Doctrine\DBAL\SQL;

use Doctrine\DBAL\SQL\Parser\Visitor;

use function array_merge;
use function assert;
use function current;
use function implode;
use function key;
use function next;
use function preg_match;
use function reset;
use function sprintf;
use function strlen;

/**
 * The SQL parser that focuses on identifying prepared statement parameters. It implements parsing other tokens like
 * string literals and comments only as a way to not confuse their contents with the the parameter placeholders.
 *
 * The parsing logic and the implementation is inspired by the PHP PDO parser.
 *
 * @internal
 *
 * @see https://github.com/php/php-src/blob/php-7.4.12/ext/pdo/pdo_sql_parser.re#L49-L69
 */
final class Parser
{
    private const ANY     = '.';
    private const SPECIAL = '[:\?\'"`\\[\\-\\/]';

    private const BACKTICK_IDENTIFIER  = '`[^`]*`';
    private const BRACKET_IDENTIFIER   = '(?<!\b(?i:ARRAY))\[(?:[^\]])*\]';
    private const MULTICHAR            = ':{2,}';
    private const NAMED_PARAMETER      = ':[a-zA-Z0-9_]+';
    private const POSITIONAL_PARAMETER = '\\?';
    private const ONE_LINE_COMMENT     = '--[^\r\n]*';
    private const MULTI_LINE_COMMENT   = '/\*([^*]+|\*+[^/*])*\**\*/';
    private const OTHER                = '((?!' . self::SPECIAL . ')' . self::ANY . ')+';

    /** @var string */
    private $sqlPattern;

    public function __construct(bool $mySQLStringEscaping)
    {
        if ($mySQLStringEscaping) {
            $patterns = [
                $this->getMySQLStringLiteralPattern("'"),
                $this->getMySQLStringLiteralPattern('"'),
            ];
        } else {
            $patterns = [
                $this->getAnsiSQLStringLiteralPattern("'"),
                $this->getAnsiSQLStringLiteralPattern('"'),
            ];
        }

        $patterns = array_merge($patterns, [
            self::BACKTICK_IDENTIFIER,
            self::BRACKET_IDENTIFIER,
            self::MULTICHAR,
            self::ONE_LINE_COMMENT,
            self::MULTI_LINE_COMMENT,
            self::OTHER,
        ]);

        $this->sqlPattern = sprintf('(%s)+', implode('|', $patterns));
    }

    /**
     * Parses the given SQL statement
     */
    public function parse(string $sql, Visitor $visitor): void
    {
        /** @var array<string,callable> $patterns */
        $patterns = [
            self::NAMED_PARAMETER => static function (string $sql) use ($visitor): void {
                $visitor->acceptNamedParameter($sql);
            },
            self::POSITIONAL_PARAMETER => static function (string $sql) use ($visitor): void {
                $visitor->acceptPositionalParameter($sql);
            },
            $this->sqlPattern => static function (string $sql) use ($visitor): void {
                $visitor->acceptOther($sql);
            },
            self::SPECIAL => static function (string $sql) use ($visitor): void {
                $visitor->acceptOther($sql);
            },
        ];

        $offset = 0;

        while (($handler = current($patterns)) !== false) {
            $match = $this->match($sql, $offset, key($patterns));

            if ($match !== null) {
                $handler($match);
                reset($patterns);

                $offset += strlen($match);
            } else {
                next($patterns);
            }
        }

        assert($offset === strlen($sql));
    }

    private function getMySQLStringLiteralPattern(string $delimiter): string
    {
        return $delimiter . '((\\\\' . self::ANY . ')|(?![' . $delimiter . '\\\\])' . self::ANY . ')*' . $delimiter;
    }

    private function getAnsiSQLStringLiteralPattern(string $delimiter): string
    {
        return $delimiter . '[^' . $delimiter . ']*' . $delimiter;
    }

    /**
     * If the part of the string from the given offset matches the given pattern, returns the matching substring
     * and updates the offset with the position to resume parsing from. Otherwise, returns NULL.
     *
     * @param string $sql     The SQL statement to parse
     * @param int    $offset  The offset to match from
     * @param string $pattern The pattern
     */
    private function match(string $sql, int $offset, string $pattern): ?string
    {
        if (preg_match('~\G' . $pattern . '~s', $sql, $matches, 0, $offset) === 1) {
            return $matches[0];
        }

        return null;
    }
}
