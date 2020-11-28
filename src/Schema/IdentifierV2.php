<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema;

use Doctrine\DBAL\Platforms\AbstractPlatform;

abstract class IdentifierV2
{
    /** @var string */
    protected $identifier;

    final public function __construct(string $identifier)
    {
        $this->identifier = $identifier;
    }

    final public function toIdentifierSQL(AbstractPlatform $platform): string
    {
        return $platform->quoteIdentifier($this->toValue($platform));
    }

    final public function toLiteralSQL(AbstractPlatform $platform): string
    {
        return $platform->quoteStringLiteral($this->toValue($platform));
    }

    abstract public function toValue(AbstractPlatform $platform): string;
}
