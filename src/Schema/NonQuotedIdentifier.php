<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema;

use Doctrine\DBAL\Platforms\AbstractPlatform;

final class NonQuotedIdentifier extends IdentifierV2
{
    public function toValue(AbstractPlatform $platform): string
    {
        return $platform->normalizeIdentifier($this->identifier);
    }
}
