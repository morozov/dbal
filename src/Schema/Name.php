<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema;

use Doctrine\DBAL\Platform\NameNormalizer;
use Doctrine\DBAL\SQL\Builder\IdentifierBuilder;

/**
 * SQL object name.
 */
interface Name
{
    /**
     * Returns object name representation as an SQL identifier.
     */
    public function toIdentifier(NameNormalizer $normalizer, IdentifierBuilder $builder): string;

    public function toNormalizedValue(NameNormalizer $normalizer, ?Name $defaultQualifier): string;

    /**
     * Returns object name representation as a string.
     *
     * This value must not be used to build SQL statements.
     */
    public function toString(): string;

    public function generate(callable $generator): self;
}
