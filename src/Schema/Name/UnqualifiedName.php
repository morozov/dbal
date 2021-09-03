<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema\Name;

use Doctrine\DBAL\Platform\NameNormalizer;
use Doctrine\DBAL\Schema\Name;
use Doctrine\DBAL\SQL\Builder\LiteralBuilder;
use InvalidArgumentException;

/**
 * An unqualified name represents the own name of an SQL object within its schema or catalog.
 */
abstract class UnqualifiedName implements Name
{
    protected string $name;

    public function __construct(string $name)
    {
        if ($name === '') {
            throw new InvalidArgumentException('Name cannot be empty');
        }

        $this->name = $name;
    }

    /**
     * Returns object name representation as an SQL literal.
     */
    abstract public function toLiteral(NameNormalizer $normalizer, LiteralBuilder $builder): string;

    public function generate(callable $generator): self
    {
        return new static($generator($this->name));
    }

    public function toString(): string
    {
        return $this->name;
    }
}
