<?php

namespace Benson\LaravelFirebird\Query;

use DateTimeInterface;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

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
     * Run a "truncate" statement on the table.
     *
     * @return void
     */
    public function truncate()
    {
        $this->applyBeforeQueryCallbacks();

        $generators = $this->autoIncrementGeneratorsForTable($this->from);

        foreach ($this->grammar->compileTruncate($this) as $sql => $bindings) {
            $this->connection->statement($sql, $bindings);
        }

        foreach ($generators as $generator) {
            $this->connection->statement(
                'set generator '.$this->grammar->wrap($generator).' to 0'
            );
        }
    }

    /**
     * Add a "where time" statement to the query.
     *
     * Dialect 1 clients cannot reference Firebird's TIME datatype, so compare
     * the timestamp's extracted second-of-day value instead.
     *
     * @param  \Illuminate\Contracts\Database\Query\Expression|string  $column
     * @param  \DateTimeInterface|string|null  $operator
     * @param  \DateTimeInterface|string|null  $value
     * @param  string  $boolean
     * @return $this
     */
    public function whereTime($column, $operator, $value = null, $boolean = 'and')
    {
        if ((string) $this->connection->getConfig('dialect') !== '1') {
            return parent::whereTime($column, $operator, $value, $boolean);
        }

        [$value, $operator] = $this->prepareValueAndOperator(
            $value, $operator, func_num_args() === 2
        );

        if ($this->invalidOperator($operator)) {
            [$value, $operator] = [$operator, '='];
        }

        $value = $this->flattenValue($value);

        if ($value instanceof DateTimeInterface) {
            $value = $value->format('H:i:s');
        }

        return $this->addDateBasedWhere('TimeSeconds', $column, $operator, $this->timeToSeconds($value), $boolean);
    }

    /**
     * Add a "where date" statement to the query.
     *
     * Dialect 1 clients are more reliable when dates are compared through
     * extracted parts instead of the DATE datatype.
     *
     * @param  \Illuminate\Contracts\Database\Query\Expression|string  $column
     * @param  \DateTimeInterface|string|null  $operator
     * @param  \DateTimeInterface|string|null  $value
     * @param  string  $boolean
     * @return $this
     */
    public function whereDate($column, $operator, $value = null, $boolean = 'and')
    {
        if ((string) $this->connection->getConfig('dialect') !== '1') {
            return parent::whereDate($column, $operator, $value, $boolean);
        }

        [$value, $operator] = $this->prepareValueAndOperator(
            $value, $operator, func_num_args() === 2
        );

        if ($this->invalidOperator($operator)) {
            [$value, $operator] = [$operator, '='];
        }

        $value = $this->flattenValue($value);

        if ($value instanceof DateTimeInterface) {
            $value = $value->format('Y-m-d');
        }

        return $this->addDateBasedWhere('DateNumber', $column, $operator, $this->dateToNumber($value), $boolean);
    }

    /**
     * Convert a YYYY-MM-DD value to an integer date key.
     *
     * @param  mixed  $value
     * @return int
     */
    protected function dateToNumber($value)
    {
        return (int) preg_replace('/\D/', '', (string) $value);
    }

    /**
     * Convert an HH:MM:SS value to seconds since midnight.
     *
     * @param  mixed  $value
     * @return int
     */
    protected function timeToSeconds($value)
    {
        [$hour, $minute, $second] = array_pad(explode(':', (string) $value), 3, 0);

        return ((int) $hour * 3600) + ((int) $minute * 60) + (int) floor((float) $second);
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

    /**
     * Discover generators used by insert triggers on a table.
     *
     * @param  string  $table
     * @return list<string>
     */
    protected function autoIncrementGeneratorsForTable($table)
    {
        $generators = $this->connection->select(
            'select distinct trim(d.rdb$depended_on_name) as name '
            .'from rdb$triggers t '
            .'join rdb$dependencies d on d.rdb$dependent_name = t.rdb$trigger_name '
            .'join rdb$generators g on g.rdb$generator_name = d.rdb$depended_on_name '
            .'where trim(t.rdb$relation_name) = ? '
            .'and (t.rdb$system_flag is null or t.rdb$system_flag = 0)',
            [$this->normalizeObjectName($table)]
        );

        return array_values(array_filter(array_map(function ($generator) {
            $generator = (array) $generator;

            return $generator['name'] ?? $generator['NAME'] ?? null;
        }, $generators)));
    }

    /**
     * Normalize a database object lookup for legacy uppercase schemas.
     *
     * @param  string  $name
     * @return string
     */
    protected function normalizeObjectName($name)
    {
        return $this->connection->getConfig('uppercase_identifiers', false) === true
            ? Str::upper($name)
            : $name;
    }
}
