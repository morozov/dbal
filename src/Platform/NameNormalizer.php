<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Platform;

interface NameNormalizer
{
    public function normalizeName(string $name): string;
}
