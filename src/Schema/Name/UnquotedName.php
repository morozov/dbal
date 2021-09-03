<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema\Name;

use Doctrine\DBAL\Platform\NameNormalizer;
use Doctrine\DBAL\Schema\Name;
use Doctrine\DBAL\SQL\Builder\IdentifierBuilder;
use Doctrine\DBAL\SQL\Builder\LiteralBuilder;

/**
 * An unquoted name is an unqualified name that is not quoted.
 */
final class UnquotedName extends UnqualifiedName
{
    public function toLiteral(NameNormalizer $normalizer, LiteralBuilder $builder): string
    {
        return $builder->buildLiteral($normalizer->normalizeName($this->name));
    }

    public function toIdentifier(NameNormalizer $normalizer, IdentifierBuilder $builder): string
    {
        return $builder->buildIdentifier($normalizer->normalizeName($this->name));
    }

    public function toNormalizedValue(NameNormalizer $normalizer, ?Name $defaultQualifier): string
    {
        if ($defaultQualifier === null) {
            return $normalizer->normalizeName($this->name);
        }

        return (new QualifiedName($defaultQualifier, $this))->toNormalizedValue($normalizer, null);
    }
}
