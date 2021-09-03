<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Platform\NameNormalizer;

use Doctrine\DBAL\Platform\NameNormalizer;

final class DefaultNameNormalizer implements NameNormalizer
{
    public function normalizeName(string $name): string
    {
        return $name;
    }
}
