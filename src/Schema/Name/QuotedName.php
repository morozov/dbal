<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema\Name;

use Doctrine\DBAL\Platform\NameNormalizer;
use Doctrine\DBAL\Schema\Name;
use Doctrine\DBAL\SQL\Builder\IdentifierBuilder;
use Doctrine\DBAL\SQL\Builder\LiteralBuilder;

/**
 * An unquoted name is an unqualified name that is quoted.
 */
final class QuotedName extends UnqualifiedName
{
    public function toLiteral(NameNormalizer $normalizer, LiteralBuilder $builder): string
    {
        return $builder->buildLiteral($this->name);
    }

    public function toIdentifier(NameNormalizer $normalizer, IdentifierBuilder $builder): string
    {
        return $builder->buildIdentifier($this->name);
    }

    public function toNormalizedValue(NameNormalizer $normalizer, ?Name $defaultQualifier): string
    {
        if ($defaultQualifier === null) {
            return $this->name;
        }

        return (new QualifiedName($defaultQualifier, $this))->toNormalizedValue($normalizer, null);
    }

    public function toString(): string
    {
        return '';
    }
}
