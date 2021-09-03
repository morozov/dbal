<?php

declare(strict_types=1);

namespace Doctrine\DBAL\SQL\Builder\IdentifierBuilder;

use Doctrine\DBAL\SQL\Builder\IdentifierBuilder;

use function str_replace;

final class DefaultIdentifierBuilder implements IdentifierBuilder
{
    private string $openingQuoteCharacter;
    private string $closingQuoteCharacter;

    public function __construct(
        string $openingQuoteCharacter,
        string $closingQuoteCharacter
    ) {
        $this->openingQuoteCharacter = $openingQuoteCharacter;
        $this->closingQuoteCharacter = $closingQuoteCharacter;
    }

    public function buildIdentifier(string $name): string
    {
        return $this->openingQuoteCharacter
            . str_replace(
                $this->closingQuoteCharacter,
                $this->closingQuoteCharacter . $this->closingQuoteCharacter,
                $name
            ) . $this->closingQuoteCharacter;
    }
}
