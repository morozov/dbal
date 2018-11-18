<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Platforms;

use Doctrine\DBAL\Types\Type;

/**
 * Provides the behavior, features and SQL dialect of the PostgreSQL 9.4 database platform.
 */
class PostgreSQL94Platform extends PostgreSqlPlatform
{
    /**
     * {@inheritdoc}
     *
     * @return string
     */
    public function getJsonTypeDeclarationSQL(array $field) : string
    {
        if (! empty($field['jsonb'])) {
            return 'JSONB';
        }

        return 'JSON';
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    protected function getReservedKeywordsClass() : string
    {
        return Keywords\PostgreSQL94Keywords::class;
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    protected function initializeDoctrineTypeMappings() : void
    {
        parent::initializeDoctrineTypeMappings();

        $this->doctrineTypeMapping['jsonb'] = Type::JSON;
    }
}
