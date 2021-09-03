<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema;

/**
 * @template E of Object_
 */
final class ObjectMap
{
    public function get(Name $name): ?Named
    {
    }

    public function put(Named $object): void
    {
    }

    public function contains(Name $name): bool
    {
    }

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
