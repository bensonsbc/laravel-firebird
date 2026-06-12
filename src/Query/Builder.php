<?php

namespace Benson\LaravelFirebird\Query;

use Benson\LaravelFirebird\Concerns\DiscoversAutoIncrementGenerators;
use DateTimeInterface;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class Builder extends BaseBuilder
{
    use DiscoversAutoIncrementGenerators;

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
     * Rows that violate a unique constraint are skipped. Firebird rolls back
     * only the failed statement, so the remaining rows can still be inserted
     * inside the same transaction.
     *
     * @return int<0, max>
     */
    public function insertOrIgnore(array $values)
    {
        if (empty($values)) {
            return 0;
        }

        if (! is_array(Arr::first($values))) {
            $values = [$values];
        } else {
            foreach ($values as $key => $value) {
                ksort($value);

                $values[$key] = $value;
            }
        }

        $this->applyBeforeQueryCallbacks();

        return $this->connection->transaction(function () use ($values) {
            $affected = 0;

            foreach ($values as $record) {
                try {
                    $affected += $this->connection->affectingStatement(
                        $this->grammar->compileInsert($this, $record),
                        $this->cleanBindings(array_values($record))
                    );
                } catch (UniqueConstraintViolationException) {
                    //
                }
            }

            return $affected;
        });
    }

    /**
     * Insert new records into the table using a subquery while ignoring errors.
     *
     * @param  \Closure|\Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder<*>|string  $query
     * @return int
     */
    public function insertOrIgnoreUsing(array $columns, $query)
    {
        $this->applyBeforeQueryCallbacks();

        [$sql, $bindings] = $this->createSub($query);

        return $this->connection->affectingStatement(
            $this->grammar->compileInsertOrIgnoreUsing(
                $this, $columns, $sql, $this->uniqueConstraintColumnSets($columns)
            ),
            $this->cleanBindings($bindings)
        );
    }

    /**
     * Resolve the table's unique column sets that are covered by the insert.
     *
     * @param  array  $columns
     * @return list<list<string>>
     */
    protected function uniqueConstraintColumnSets(array $columns)
    {
        if (! is_string($this->from)) {
            return [];
        }

        $table = preg_split('/\s+as\s+/i', $this->from)[0];

        $normalized = [];

        foreach ($columns as $column) {
            $normalized[Str::lower($column)] = $column;
        }

        $sets = [];

        foreach ($this->connection->getSchemaBuilder()->getIndexes($table) as $index) {
            if (! ($index['unique'] ?? false)) {
                continue;
            }

            $set = [];

            foreach ($index['columns'] as $column) {
                if (! array_key_exists($column, $normalized)) {
                    continue 2;
                }

                $set[] = $normalized[$column];
            }

            $sets[] = $set;
        }

        return array_values(array_unique($sets, SORT_REGULAR));
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
        $identityColumns = $this->identityColumnsForTable($this->from);

        foreach ($this->grammar->compileTruncate($this) as $sql => $bindings) {
            $this->connection->statement($sql, $bindings);
        }

        foreach ($generators as $generator) {
            $this->connection->statement(
                'set generator '.$this->grammar->wrap($generator).' to 0'
            );
        }

        foreach ($identityColumns as $column) {
            $this->connection->statement(sprintf(
                'alter table %s alter %s restart',
                $this->grammar->wrapTable($this->from),
                $this->grammar->wrap($column)
            ));
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

}
