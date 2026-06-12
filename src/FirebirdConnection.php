<?php

namespace Benson\LaravelFirebird;

use Benson\LaravelFirebird\Query\Builder as FirebirdQueryBuilder;
use Benson\LaravelFirebird\Query\Grammars\FirebirdGrammar as FirebirdQueryGrammar;
use Benson\LaravelFirebird\Query\Processors\FirebirdProcessor as FirebirdQueryProcessor;
use Benson\LaravelFirebird\Schema\Builder as FirebirdSchemaBuilder;
use Benson\LaravelFirebird\Schema\Grammars\FirebirdGrammar as FirebirdSchemaGrammar;
use Closure;
use Exception;
use Illuminate\Database\Connection as DatabaseConnection;
use Illuminate\Support\Str;
use PDO;
use Throwable;

class FirebirdConnection extends DatabaseConnection
{
    /**
     * {@inheritDoc}
     */
    public function getDriverTitle()
    {
        return 'Firebird';
    }

    /**
     * Get the server version for the connection.
     *
     * @return string
     */
    public function getServerVersion(): string
    {
        $version = $this->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);

        return Str::match('/\(remote server\), version "\w+-V(\d+\.\d+\.\d+)/', $version)
            ?: Str::match('/\w+-V(\d+\.\d+\.\d+)/', $version)
            ?: $version;
    }

    /**
     * Determine if the given database exception was caused by a unique constraint violation.
     *
     * @param  \Exception  $exception
     * @return bool
     */
    protected function isUniqueConstraintError(Exception $exception)
    {
        return (bool) preg_match(
            '/violation of PRIMARY or UNIQUE KEY constraint|attempt to store duplicate value/i',
            $exception->getMessage()
        );
    }

    /**
     * Extract the index that caused a unique constraint violation.
     *
     * @param  \Exception  $exception
     * @return array{index: string|null, columns: list<string>}
     */
    protected function parseUniqueConstraintViolation(Exception $exception): array
    {
        if (preg_match('/(?:constraint|unique index) "([^"]+)"/i', $exception->getMessage(), $matches)) {
            return ['index' => $matches[1], 'columns' => []];
        }

        return ['index' => null, 'columns' => []];
    }

    /**
     * Get the default query grammar instance.
     *
     * @return \Illuminate\Database\Query\Grammars\Grammar
     */
    protected function getDefaultQueryGrammar()
    {
        return new FirebirdQueryGrammar($this);
    }

    /**
     * Get the default post processor instance.
     *
     * @return \Illuminate\Database\Query\Processors\Processor
     */
    protected function getDefaultPostProcessor()
    {
        return new FirebirdQueryProcessor;
    }

    /**
     * Get a schema builder instance for the connection.
     *
     * @return \Illuminate\Database\Schema\Builder
     */
    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new FirebirdSchemaBuilder($this);
    }

    /**
     * Get the default schema grammar instance.
     *
     * @return \Illuminate\Database\Schema\Grammars\Grammar|null
     */
    protected function getDefaultSchemaGrammar()
    {
        return new FirebirdSchemaGrammar($this);
    }

    /**
     * Execute the statement to start a transaction.
     *
     * Firebird may open implicit transactions for reads or DDL. Close that
     * implicit transaction before Laravel starts an explicit transaction.
     *
     * @return void
     */
    protected function executeBeginTransactionStatement()
    {
        $pdo = $this->getPdo();

        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->commit();
        }

        $pdo->setAttribute(PDO::ATTR_AUTOCOMMIT, false);

        parent::executeBeginTransactionStatement();
    }

    /**
     * Commit the active database transaction.
     *
     * @return void
     */
    public function commit()
    {
        $isOuterTransaction = $this->transactionLevel() === 1;

        try {
            parent::commit();
        } finally {
            if ($isOuterTransaction) {
                $this->getPdo()->setAttribute(PDO::ATTR_AUTOCOMMIT, true);
            }
        }
    }

    /**
     * Perform a rollback within the database.
     *
     * @param  int  $toLevel
     * @return void
     */
    protected function performRollBack($toLevel)
    {
        try {
            parent::performRollBack($toLevel);
        } finally {
            if ($toLevel === 0) {
                $this->getPdo()->setAttribute(PDO::ATTR_AUTOCOMMIT, true);
                $this->transactions = 0;
            }
        }
    }

    /**
     * Handle an exception from a rollback.
     *
     * @param  \Throwable  $e
     * @return void
     *
     * @throws \Throwable
     */
    protected function handleRollBackException(Throwable $e)
    {
        if ($this->transactionLevel() > 0) {
            $this->transactions = 0;
            $this->getPdo()->setAttribute(PDO::ATTR_AUTOCOMMIT, true);
        }

        throw $e;
    }

    /**
     * Execute a Closure within a transaction.
     *
     * @param  \Closure  $callback
     * @param  int  $attempts
     * @return mixed
     *
     * @throws \Throwable
     */
    public function transaction(Closure $callback, $attempts = 1)
    {
        try {
            return parent::transaction($callback, $attempts);
        } finally {
            if ($this->transactionLevel() === 0) {
                $this->getPdo()->setAttribute(PDO::ATTR_AUTOCOMMIT, true);
            }
        }
    }

    /**
     * Get a new query builder instance.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function query()
    {
        return new FirebirdQueryBuilder(
            $this, $this->getQueryGrammar(), $this->getPostProcessor()
        );
    }

    /**
     * Execute a stored procedure.
     *
     * @param  string  $procedure
     * @param  array  $bindings
     * @return \Illuminate\Support\Collection
     */
    public function executeProcedure(string $procedure, array $bindings = [])
    {
        return $this->query()->procedure($procedure, $bindings)->get();
    }
}
