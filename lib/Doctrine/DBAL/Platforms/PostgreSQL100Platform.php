<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Platforms;

use Doctrine\DBAL\Platforms\Keywords\PostgreSQL100Keywords;

/**
 * Provides the behavior, features and SQL dialect of the PostgreSQL 10.0 database platform.
 */
class PostgreSQL100Platform extends PostgreSQL94Platform
{
    /**
     * {@inheritdoc}
     */
    protected function getReservedKeywordsClass() : string
    {
        return PostgreSQL100Keywords::class;
    }

    public function getListSequencesSQL(?string $database) : string
    {
        if ($database !== null) {
            $catalogExpression = $this->quoteStringLiteral($database);
        } else {
            $catalogExpression = '(SELECT current_catalog)';
        }

        return 'SELECT sequence_name AS relname,
                       sequence_schema AS schemaname,
                       minimum_value AS min_value, 
                       increment AS increment_by
                FROM   information_schema.sequences
                WHERE  sequence_catalog = ' . $catalogExpression . "
                AND    sequence_schema NOT LIKE 'pg\_%'
                AND    sequence_schema != 'information_schema'";
    }
}
