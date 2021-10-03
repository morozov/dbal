<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema;

use Doctrine\DBAL\Platforms\AbstractPlatform;

use function explode;
use function implode;
use function str_replace;
use function strpos;
use function strtolower;

/**
 * The abstract asset allows to reset the name of all assets without publishing this to the public userland.
 *
 * This encapsulation hack is necessary to keep a consistent state of the database schema. Say we have a list of tables
 * array($tableName => Table($tableName)); if you want to rename the table, you have to make sure
 */
abstract class AbstractAsset
{
    protected string $_name = '';

    /**
     * Namespace of the asset. If none isset the default namespace is assumed.
     */
    protected ?string $_namespace = null;

    protected bool $_quoted = false;

    /**
     * Sets the name of this asset.
     */
    protected function _setName(string $name): void
    {
        if ($this->isIdentifierQuoted($name)) {
            $this->_quoted = true;
            $name          = $this->trimQuotes($name);
        }

        if (strpos($name, '.') !== false) {
            $parts            = explode('.', $name);
            $this->_namespace = $parts[0];
            $name             = $parts[1];
        }

        $this->_name = $name;
    }

    /**
     * Is this asset in the default namespace?
     */
    public function isInDefaultNamespace(string $defaultNamespaceName): bool
    {
        return $this->_namespace === $defaultNamespaceName || $this->_namespace === null;
    }

    /**
     * Gets the namespace name of this asset.
     *
     * If NULL is returned this means the default namespace is used.
     */
    public function getNamespaceName(): ?string
    {
        return $this->_namespace;
    }

    /**
     * The shortest name is stripped of the default namespace. All other
     * namespaced elements are returned as full-qualified names.
     */
    public function getShortestName(?string $defaultNamespaceName): string
    {
        $shortestName = $this->getName();
        if ($this->_namespace === $defaultNamespaceName) {
            $shortestName = $this->_name;
        }

        return strtolower($shortestName);
    }

    /**
     * Checks if this asset's name is quoted.
     */
    public function isQuoted(): bool
    {
        return $this->_quoted;
    }

    /**
     * Checks if this identifier is quoted.
     */
    protected function isIdentifierQuoted(string $identifier): bool
    {
        return isset($identifier[0]) && ($identifier[0] === '`' || $identifier[0] === '"' || $identifier[0] === '[');
    }

    /**
     * Trim quotes from the identifier.
     */
    protected function trimQuotes(string $identifier): string
    {
        return str_replace(['`', '"', '[', ']'], '', $identifier);
    }

    /**
     * Returns the name of this schema asset.
     */
    public function getName(): string
    {
        if ($this->_namespace !== null) {
            return $this->_namespace . '.' . $this->_name;
        }

        return $this->_name;
    }

    /**
     * Gets the quoted representation of this asset but only if it was defined with one. Otherwise
     * return the plain unquoted value as inserted.
     */
    public function getQuotedName(AbstractPlatform $platform): string
    {
        $keywords = $platform->getReservedKeywordsList();
        $parts    = explode('.', $this->getName());
        foreach ($parts as $k => $v) {
            $parts[$k] = $this->_quoted || $keywords->isKeyword($v) ? $platform->quoteIdentifier($v) : $v;
        }

        return implode('.', $parts);
    }
}
