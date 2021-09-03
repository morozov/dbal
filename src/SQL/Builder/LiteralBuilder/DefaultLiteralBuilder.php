<?php

declare(strict_types=1);

namespace Doctrine\DBAL\SQL\Builder\LiteralBuilder;

use Doctrine\DBAL\SQL\Builder\LiteralBuilder;

use function str_replace;

final class DefaultLiteralBuilder implements LiteralBuilder
{
    private const QUOTE_CHARACTER = "'";

    public function buildLiteral(string $value): string
    {
        return self::QUOTE_CHARACTER
            . str_replace(
                self::QUOTE_CHARACTER,
                self::QUOTE_CHARACTER . self::QUOTE_CHARACTER,
                $value
            ) . self::QUOTE_CHARACTER;
    }
}
