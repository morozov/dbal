<?php

declare(strict_types=1);

namespace Doctrine\DBAL;

class ConnectionException extends DBALException
{
    public static function commitFailedRollbackOnly() : self
    {
        return new self('Transaction commit failed because the transaction has been marked for rollback only.');
    }

    public static function noActiveTransaction() : self
    {
        return new self('There is no active transaction.');
    }

    public static function savepointsNotSupported() : self
    {
        return new self('Savepoints are not supported by this driver.');
    }

    public static function mayNotAlterNestedTransactionWithSavepointsInTransaction() : self
    {
        return new self('May not alter the nested transaction with savepoints behavior while a transaction is open.');
    }
}
