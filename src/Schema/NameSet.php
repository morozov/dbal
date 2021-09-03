<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema;

/**
 * @template E of Name
 */
final class NameSet
{
    /**
     * @param list<E> $names
     */
    public function __construct(array $names)
    {
        foreach ($names as $name) {
            $this->add($name);
        }
    }

    /**
     * @param E $name
     */
    public function add(Name $name): void
    {
    }

    /**
     * @param E $name
     */
    public function contains(Name $name): bool
    {
    }

    /**
     * @param E $name
     */
    public function remove(Name $name): void
    {
    }

    /**
     * @return list<E>
     */
    public function toArray(): array
    {
        return [];
    }

    public function __clone()
    {
    }
}
