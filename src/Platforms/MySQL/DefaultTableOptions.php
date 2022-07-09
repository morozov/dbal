<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Platforms\MySQL;

/**
 * @internal
 */
final class DefaultTableOptions
{
    private string $charset;

    private string $collation;

    public function __construct(string $charset, string $collation)
    {
        $this->charset   = $charset;
        $this->collation = $collation;
    }

    public function getCharset(): string
    {
        return $this->charset;
    }

    public function getCollation(): string
    {
        return $this->collation;
    }
}
