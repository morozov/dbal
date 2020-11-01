<?php

namespace Doctrine\DBAL;

use Doctrine\DBAL\ArrayParameters\Exception\MissingNamedParameter;
use Doctrine\DBAL\ArrayParameters\Exception\MissingPositionalParameter;
use Doctrine\DBAL\SQL\Parser\Visitor;
use Doctrine\DBAL\Types\Type;

use function array_fill;
use function array_key_exists;
use function count;
use function implode;
use function substr;

final class ExpandArrayParameters implements Visitor
{
    /** @var array<int,mixed>|array<string,mixed> */
    private $originalValues;

    /** @var array<int,Type|int|string|null>|array<string,Type|int|string|null> */
    private $originalTypes;

    /** @var int */
    private $originalParameterIndex = 0;

    /** @var list<string> */
    private $convertedSQL = [];

    /** @var list<mixed> */
    private $convertedValues = [];

    /** @var array<int,Type|int|string|null> */
    private $convertedTypes = [];

    /**
     * @param array<int, mixed>|array<string, mixed>                             $values
     * @param array<int,Type|int|string|null>|array<string,Type|int|string|null> $types
     */
    public function __construct(array $values, array $types)
    {
        $this->originalValues = $values;
        $this->originalTypes  = $types;
    }

    public function acceptPositionalParameter(string $sql): void
    {
        $index = $this->originalParameterIndex;

        if (! array_key_exists($index, $this->originalValues)) {
            throw MissingPositionalParameter::new($index);
        }

        $this->acceptParameter($index, $this->originalValues[$index]);

        $this->originalParameterIndex++;
    }

    public function acceptNamedParameter(string $sql): void
    {
        $name = substr($sql, 1);

        if (! array_key_exists($name, $this->originalValues)) {
            throw MissingNamedParameter::new($name);
        }

        $this->acceptParameter($name, $this->originalValues[$name]);
    }

    public function acceptOther(string $sql): void
    {
        $this->convertedSQL[] = $sql;
    }

    public function getSQL(): string
    {
        return implode('', $this->convertedSQL);
    }

    /**
     * @return list<mixed>
     */
    public function getParameters(): array
    {
        return $this->convertedValues;
    }

    /**
     * @param int|string $key
     * @param mixed      $value
     */
    private function acceptParameter($key, $value): void
    {
        if (isset($this->originalTypes[$key])) {
            $type = $this->originalTypes[$key];

            if ($type === Connection::PARAM_INT_ARRAY || $type === Connection::PARAM_STR_ARRAY) {
                if (count($value) === 0) {
                    $this->appendSQL('NULL');
                } else {
                    $this->appendTypedParameters($value, $type - Connection::ARRAY_PARAM_OFFSET);
                }
            } else {
                $this->appendTypedParameters([$value], $type);
            }
        } else {
            $this->appendUntypedParameter($value);
        }
    }

    /**
     * @return array<int,Type|int|string|null>
     */
    public function getTypes(): array
    {
        return $this->convertedTypes;
    }

    private function appendSQL(string $sql): void
    {
        $this->convertedSQL[] = $sql;
    }

    /**
     * @param list<mixed>          $values
     * @param Type|int|string|null $type
     */
    private function appendTypedParameters(array $values, $type): void
    {
        $this->convertedSQL[] = implode(', ', array_fill(0, count($values), '?'));

        $index = count($this->convertedValues);

        foreach ($values as $value) {
            $this->convertedValues[]      = $value;
            $this->convertedTypes[$index] = $type;

            $index++;
        }
    }

    /**
     * @param mixed $value
     */
    private function appendUntypedParameter($value): void
    {
        $this->convertedSQL[]    = '?';
        $this->convertedValues[] = $value;
    }
}
