<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Platform\NameNormalizer;

use Doctrine\DBAL\Platform\NameNormalizer;

use function strtoupper;

final class UpperCasingNameNormalizer implements NameNormalizer
{
    public function normalizeName(string $name): string
    {
        return strtoupper($name);
    }
}
