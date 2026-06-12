<?php

namespace Benson\LaravelFirebird\Query;

use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Support\Arr;

class Builder extends BaseBuilder
{
    /**
     * Insert new records into the database.
     *
     * Firebird does not accept Laravel's default multi-row VALUES syntax. Run
     * batch inserts as single-row statements inside one transaction instead.
     *
     * @return bool
     */
    public function insert(array $values)
    {
        if (empty($values)) {
            return true;
        }

        if (! is_array(Arr::first($values))) {
            return parent::insert($values);
        }

        foreach ($values as $key => $value) {
            ksort($value);

            $values[$key] = $value;
        }

        $this->applyBeforeQueryCallbacks();

        return $this->connection->transaction(function () use ($values) {
            foreach ($values as $record) {
                $sql = $this->grammar->compileInsert($this, $record);

                $this->connection->insert($sql, $this->cleanBindings(array_values($record)));
            }

            return true;
        });
    }

    /**
     * Insert new records into the database while ignoring duplicates.
     *
     * @return int<0, max>
     */
    public function insertOrIgnore(array $values)
    {
        if (empty($values)) {
            return 0;
        }

        if (! is_array(Arr::first($values))) {
            return parent::insertOrIgnore($values);
        }

        foreach ($values as $key => $value) {
            ksort($value);

            $values[$key] = $value;
        }

        $this->applyBeforeQueryCallbacks();

        return $this->connection->transaction(function () use ($values) {
            $affected = 0;

            foreach ($values as $record) {
                $sql = $this->grammar->compileInsertOrIgnore($this, $record);

                $affected += $this->connection->affectingStatement(
                    $sql,
                    $this->cleanBindings(array_values($record))
                );
            }

            return $affected;
        });
    }

    /**
     * Determine if any rows exist for the current query.
     *
     * @return bool
     */
    public function exists()
    {
        $this->applyBeforeQueryCallbacks();

        $results = $this->connection->select(
            $this->grammar->compileExists($this), $this->getBindings(), ! $this->useWritePdo
        );

        if (! isset($results[0])) {
            return false;
        }

        $row = (array) $results[0];

        foreach (['EXISTS_RESULT', 'exists'] as $key) {
            if (array_key_exists($key, $row)) {
                return (bool) $row[$key];
            }
        }

        foreach ($row as $key => $value) {
            if (strcasecmp($key, 'EXISTS_RESULT') === 0 || strcasecmp($key, 'exists') === 0) {
                return (bool) $value;
            }
        }

        return false;
    }

    /**
     * Set the stored procedure which the query is targeting.
     *
     * @param  string  $procedure
     * @param  array  $bindings
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function procedure(string $procedure, array $bindings = [])
    {
        $expression = $this->grammar->compileProcedure($this, $procedure, $bindings);

        $this->fromRaw($expression, $this->cleanBindings($bindings));

        return $this;
    }

    /**
     * Alias to set the stored procedure which the query is targeting.
     *
     * @param  string  $procedure
     * @param  array  $bindings
     * @return \Illuminate\Database\Query\Builder|static
     *
     * @deprecated This method is deprecated and will be removed in a future
     * release. Use the `procedure` method instead.
     */
    public function fromProcedure(string $procedure, array $bindings = [])
    {
        return $this->procedure($procedure, $bindings);
    }
}
