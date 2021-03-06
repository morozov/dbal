<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Exception\NamespaceAlreadyExists;
use Doctrine\DBAL\Schema\Exception\SequenceAlreadyExists;
use Doctrine\DBAL\Schema\Exception\SequenceDoesNotExist;
use Doctrine\DBAL\Schema\Exception\TableAlreadyExists;
use Doctrine\DBAL\Schema\Exception\TableDoesNotExist;
use Doctrine\DBAL\Schema\Visitor\CreateSchemaSqlCollector;
use Doctrine\DBAL\Schema\Visitor\DropSchemaSqlCollector;
use Doctrine\DBAL\Schema\Visitor\NamespaceVisitor;
use Doctrine\DBAL\Schema\Visitor\Visitor;

use function array_keys;
use function strpos;
use function strtolower;

/**
 * Object representation of a database schema.
 *
 * Different vendors have very inconsistent naming with regard to the concept
 * of a "schema". Doctrine understands a schema as the entity that conceptually
 * wraps a set of database objects such as tables, sequences, indexes and
 * foreign keys that belong to each other into a namespace. A Doctrine Schema
 * has nothing to do with the "SCHEMA" defined as in PostgreSQL, it is more
 * related to the concept of "DATABASE" that exists in MySQL and PostgreSQL.
 *
 * Every asset in the doctrine schema has a name. A name consists of either a
 * namespace.local name pair or just a local unqualified name.
 *
 * The abstraction layer that covers a PostgreSQL schema is the namespace of an
 * database object (asset). A schema can have a name, which will be used as
 * default namespace for the unqualified database objects that are created in
 * the schema.
 *
 * In the case of MySQL where cross-database queries are allowed this leads to
 * databases being "misinterpreted" as namespaces. This is intentional, however
 * the CREATE/DROP SQL visitors will just filter this queries and do not
 * execute them. Only the queries for the currently connected database are
 * executed.
 */
class Schema extends AbstractAsset
{
    /**
     * The namespaces in this schema.
     *
     * @var array<string, string>
     */
    private $namespaces = [];

    /** @var array<string, Table> */
    protected $_tables = [];

    /** @var array<string, Sequence> */
    protected $_sequences = [];

    /** @var SchemaConfig */
    protected $_schemaConfig;

    /**
     * @param array<Table>    $tables
     * @param array<Sequence> $sequences
     * @param array<string>   $namespaces
     *
     * @throws SchemaException
     */
    public function __construct(
        array $tables = [],
        array $sequences = [],
        ?SchemaConfig $schemaConfig = null,
        array $namespaces = []
    ) {
        if ($schemaConfig === null) {
            $schemaConfig = new SchemaConfig();
        }

        $this->_schemaConfig = $schemaConfig;
        $this->_setName($schemaConfig->getName() ?? 'public');

        foreach ($namespaces as $namespace) {
            $this->createNamespace($namespace);
        }

        foreach ($tables as $table) {
            $this->_addTable($table);
        }

        foreach ($sequences as $sequence) {
            $this->_addSequence($sequence);
        }
    }

    public function hasExplicitForeignKeyIndexes(): bool
    {
        return $this->_schemaConfig->hasExplicitForeignKeyIndexes();
    }

    /**
     * @throws SchemaException
     */
    protected function _addTable(Table $table): void
    {
        $namespaceName = $table->getNamespaceName();
        $tableName     = $table->getFullQualifiedName($this->getName());

        if (isset($this->_tables[$tableName])) {
            throw TableAlreadyExists::new($tableName);
        }

        if (
            $namespaceName !== null
            && ! $table->isInDefaultNamespace($this->getName())
            && ! $this->hasNamespace($namespaceName)
        ) {
            $this->createNamespace($namespaceName);
        }

        $this->_tables[$tableName] = $table;
        $table->setSchemaConfig($this->_schemaConfig);
    }

    /**
     * @throws SchemaException
     */
    protected function _addSequence(Sequence $sequence): void
    {
        $namespaceName = $sequence->getNamespaceName();
        $seqName       = $sequence->getFullQualifiedName($this->getName());

        if (isset($this->_sequences[$seqName])) {
            throw SequenceAlreadyExists::new($seqName);
        }

        if (
            $namespaceName !== null
            && ! $sequence->isInDefaultNamespace($this->getName())
            && ! $this->hasNamespace($namespaceName)
        ) {
            $this->createNamespace($namespaceName);
        }

        $this->_sequences[$seqName] = $sequence;
    }

    /**
     * Returns the namespaces of this schema.
     *
     * @return array<string, string> A list of namespace names.
     */
    public function getNamespaces(): array
    {
        return $this->namespaces;
    }

    /**
     * Gets all tables of this schema.
     *
     * @return array<string, Table>
     */
    public function getTables(): array
    {
        return $this->_tables;
    }

    /**
     * @throws SchemaException
     */
    public function getTable(string $name): Table
    {
        $name = $this->getFullQualifiedAssetName($name);
        if (! isset($this->_tables[$name])) {
            throw TableDoesNotExist::new($name);
        }

        return $this->_tables[$name];
    }

    private function getFullQualifiedAssetName(string $name): string
    {
        $name = $this->getUnquotedAssetName($name);

        if (strpos($name, '.') === false) {
            $name = $this->getName() . '.' . $name;
        }

        return strtolower($name);
    }

    /**
     * Returns the unquoted representation of a given asset name.
     */
    private function getUnquotedAssetName(string $assetName): string
    {
        if ($this->isIdentifierQuoted($assetName)) {
            return $this->trimQuotes($assetName);
        }

        return $assetName;
    }

    /**
     * Does this schema have a namespace with the given name?
     */
    public function hasNamespace(string $name): bool
    {
        $name = strtolower($this->getUnquotedAssetName($name));

        return isset($this->namespaces[$name]);
    }

    /**
     * Does this schema have a table with the given name?
     */
    public function hasTable(string $name): bool
    {
        $name = $this->getFullQualifiedAssetName($name);

        return isset($this->_tables[$name]);
    }

    /**
     * Gets all table names, prefixed with a schema name, even the default one if present.
     *
     * @return array<int, string>
     */
    public function getTableNames(): array
    {
        return array_keys($this->_tables);
    }

    public function hasSequence(string $name): bool
    {
        $name = $this->getFullQualifiedAssetName($name);

        return isset($this->_sequences[$name]);
    }

    /**
     * @throws SchemaException
     */
    public function getSequence(string $name): Sequence
    {
        $name = $this->getFullQualifiedAssetName($name);
        if (! $this->hasSequence($name)) {
            throw SequenceDoesNotExist::new($name);
        }

        return $this->_sequences[$name];
    }

    /**
     * @return array<string, Sequence>
     */
    public function getSequences(): array
    {
        return $this->_sequences;
    }

    /**
     * Creates a new namespace.
     *
     * @return $this
     *
     * @throws SchemaException
     */
    public function createNamespace(string $name): self
    {
        $unquotedName = strtolower($this->getUnquotedAssetName($name));

        if (isset($this->namespaces[$unquotedName])) {
            throw NamespaceAlreadyExists::new($unquotedName);
        }

        $this->namespaces[$unquotedName] = $name;

        return $this;
    }

    /**
     * Creates a new table.
     *
     * @throws SchemaException
     */
    public function createTable(string $name): Table
    {
        $table = new Table($name);
        $this->_addTable($table);

        foreach ($this->_schemaConfig->getDefaultTableOptions() as $option => $value) {
            $table->addOption($option, $value);
        }

        return $table;
    }

    /**
     * Renames a table.
     *
     * @return $this
     *
     * @throws SchemaException
     */
    public function renameTable(string $oldName, string $newName): self
    {
        $table = $this->getTable($oldName);
        $table->_setName($newName);

        $this->dropTable($oldName);
        $this->_addTable($table);

        return $this;
    }

    /**
     * Drops a table from the schema.
     *
     * @return $this
     *
     * @throws SchemaException
     */
    public function dropTable(string $name): self
    {
        $name = $this->getFullQualifiedAssetName($name);
        $this->getTable($name);
        unset($this->_tables[$name]);

        return $this;
    }

    /**
     * Creates a new sequence.
     *
     * @throws SchemaException
     */
    public function createSequence(string $name, int $allocationSize = 1, int $initialValue = 1): Sequence
    {
        $seq = new Sequence($name, $allocationSize, $initialValue);
        $this->_addSequence($seq);

        return $seq;
    }

    /**
     * @return $this
     */
    public function dropSequence(string $name): self
    {
        $name = $this->getFullQualifiedAssetName($name);
        unset($this->_sequences[$name]);

        return $this;
    }

    /**
     * Returns an array of necessary SQL queries to create the schema on the given platform.
     *
     * @return array<int, string>
     */
    public function toSql(AbstractPlatform $platform): array
    {
        $sqlCollector = new CreateSchemaSqlCollector($platform);
        $this->visit($sqlCollector);

        return $sqlCollector->getQueries();
    }

    /**
     * Return an array of necessary SQL queries to drop the schema on the given platform.
     *
     * @return array<int, string>
     */
    public function toDropSql(AbstractPlatform $platform): array
    {
        $dropSqlCollector = new DropSchemaSqlCollector($platform);
        $this->visit($dropSqlCollector);

        return $dropSqlCollector->getQueries();
    }

    /**
     * @return array<int, string>
     *
     * @throws SchemaException
     */
    public function getMigrateToSql(Schema $toSchema, AbstractPlatform $platform): array
    {
        $schemaDiff = (new Comparator())->compareSchemas($this, $toSchema);

        return $schemaDiff->toSql($platform);
    }

    /**
     * @return array<int, string>
     *
     * @throws SchemaException
     */
    public function getMigrateFromSql(Schema $fromSchema, AbstractPlatform $platform): array
    {
        $schemaDiff = (new Comparator())->compareSchemas($fromSchema, $this);

        return $schemaDiff->toSql($platform);
    }

    public function visit(Visitor $visitor): void
    {
        $visitor->acceptSchema($this);

        if ($visitor instanceof NamespaceVisitor) {
            foreach ($this->namespaces as $namespace) {
                $visitor->acceptNamespace($namespace);
            }
        }

        foreach ($this->_tables as $table) {
            $table->visit($visitor);
        }

        foreach ($this->_sequences as $sequence) {
            $sequence->visit($visitor);
        }
    }

    /**
     * Cloning a Schema triggers a deep clone of all related assets.
     */
    public function __clone()
    {
        foreach ($this->_tables as $k => $table) {
            $this->_tables[$k] = clone $table;
        }

        foreach ($this->_sequences as $k => $sequence) {
            $this->_sequences[$k] = clone $sequence;
        }
    }
}
