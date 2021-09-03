<?php

declare(strict_types=1);

namespace Doctrine\DBAL\SQL\Builder;

interface IdentifierBuilder
{
    public function buildIdentifier(string $name): string;
}
