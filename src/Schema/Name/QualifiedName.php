<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema\Name;

use Doctrine\DBAL\Platform\NameNormalizer;
use Doctrine\DBAL\Schema\Name;
use Doctrine\DBAL\SQL\Builder\IdentifierBuilder;

/**
 * A qualified name consists of an unqualified name and a qualifier.
 */
final class QualifiedName implements Name
{
    private Name $qualifier;

    private UnqualifiedName $name;

    public function __construct(Name $qualifier, UnqualifiedName $name)
    {
        $this->qualifier = $qualifier;
        $this->name      = $name;
    }

    public function toIdentifier(NameNormalizer $normalizer, IdentifierBuilder $builder): string
    {
        return $this->join(static function (Name $name) use ($normalizer, $builder): string {
            return $name->toIdentifier($normalizer, $builder);
        });
    }

    public function toNormalizedValue(NameNormalizer $normalizer, ?Name $defaultQualifier): string
    {
        return $this->join(static function (Name $name) use ($normalizer): string {
            return $name->toNormalizedValue($normalizer, null);
        });
    }

    public function generate(callable $generator): Name
    {
        return new self($this->qualifier, $this->name->generate($generator));
    }

    public function toString(): string
    {
        return $this->join(static function (Name $name): string {
            return $name->toString();
        });
    }

    private function join(callable $modifier): string
    {
        return $modifier($this->qualifier) . '.' . $modifier($this->name);
    }
}
