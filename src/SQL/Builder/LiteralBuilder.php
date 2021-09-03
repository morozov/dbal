<?php

declare(strict_types=1);

namespace Doctrine\DBAL\SQL\Builder;

interface LiteralBuilder
{
    public function buildLiteral(string $value): string;
}
