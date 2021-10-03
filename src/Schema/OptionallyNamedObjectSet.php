<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema;

use LogicException;

use function array_splice;
use function count;

/**
 * Set of database objects, not all of which are necessarily named. Both named and unnamed objects can be
 * added to the set but only the named ones can be referenced.
 *
 * @template E of AbstractAsset
 */
final class OptionallyNamedObjectSet
{
    /**
     * List of all (named and unnamed) objects.
     *
     * @var list<E>
     */
    private array $objects = [];

    /**
     * Map of named object names to their offset in the list.
     *
     * @var array<string,int>
     */
    private array $nameOffsets = [];

    /**
     * @param list<E> $objects
     */
    public function __construct(array $objects = [])
    {
        foreach ($objects as $object) {
            $this->add($object);
        }
    }

    /**
     * Adds the specified object to the set if an object with the same name is not already present
     * or the object has no name.
     *
     * @param E $object
     */
    public function add(AbstractAsset $object): void
    {
        $name = $object->getName();

        if ($name !== '') {
            if (isset($this->nameOffsets[$name])) {
                throw new LogicException('Already contains');
            }

            $this->nameOffsets[$name] = count($this->objects);
        }

        $this->objects[] = $object;
    }

    /**
     * @return bool Whether the set contains an object with the specified name.
     */
    public function contains(string $name): bool
    {
        return isset($this->nameOffsets[$name]);
    }

    /**
     * Returns the object with the specified name.
     *
     * @return ?E The object or NULL if the set does not contain an object with the specified name.
     */
    public function get(string $name): ?AbstractAsset
    {
        if (! isset($this->nameOffsets[$name])) {
            return null;
        }

        return $this->objects[$this->nameOffsets[$name]];
    }

    /**
     * Removes the object with the specified name from the set.
     *
     * @return bool Whether the set contained an object with the specified name.
     */
    public function remove(string $name): bool
    {
        if (! $this->contains($name)) {
            return false;
        }

        $offset = $this->nameOffsets[$name];

        unset($this->nameOffsets[$name]);
        array_splice($this->objects, $offset, 1);

        foreach ($this->nameOffsets as $compactedName => $compactedOffset) {
            if ($compactedOffset < $offset) {
                continue;
            }

            $this->nameOffsets[$compactedName] = $compactedOffset - 1;
        }

        return true;
    }

    /**
     * @return list<E>
     */
    public function toArray(): array
    {
        return $this->objects;
    }
}
