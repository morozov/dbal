<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Platforms\MySQL;

use Doctrine\DBAL\SQL\Builder\LiteralBuilder as LiteralBuilderInterface;

use function str_replace;

final class LiteralBuilder implements LiteralBuilderInterface
{
    private LiteralBuilderInterface $literalBuilder;

    public function __construct(LiteralBuilderInterface $nameBuilder)
    {
        $this->literalBuilder = $nameBuilder;
    }

    public function buildLiteral(string $value): string
    {
        return str_replace('\\', '\\\\', $this->literalBuilder->buildLiteral($value));
    }
}
