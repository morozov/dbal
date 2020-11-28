<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema;

use Doctrine\DBAL\Platforms\AbstractPlatform;

final class QuotedIdentifier extends IdentifierV2
{
    public function toValue(AbstractPlatform $platform): string
    {
        return $this->identifier;
    }
}
