<?php

namespace Benson\LaravelFirebird\Query;

use Illuminate\Database\Query\Builder as BaseBuilder;

class Builder extends BaseBuilder
{
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
