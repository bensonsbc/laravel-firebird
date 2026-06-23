<?php

namespace Benson\LaravelFirebird\Schema;

use Benson\LaravelFirebird\Concerns\DiscoversAutoIncrementGenerators;
use Closure;
use Illuminate\Database\Schema\Builder as BaseBuilder;

class Builder extends BaseBuilder
{
    use DiscoversAutoIncrementGenerators;

    /**
     * Create a new command set with a Firebird blueprint.
     *
     * Uses the Firebird blueprint so projects can define their own constraint
     * and index naming convention through the `index_names` config.
     *
     * @param  string  $table
     * @param  \Closure|null  $callback
     * @return \Benson\LaravelFirebird\Schema\Blueprint
     */
    protected function createBlueprint($table, ?Closure $callback = null)
    {
        if (isset($this->resolver)) {
            return call_user_func($this->resolver, $this->connection, $table, $callback);
        }

        return new Blueprint($this->connection, $table, $callback);
    }

    /**
     * Drop all tables from the database.
     *
     * @return void
     */
    public function dropAllTables()
    {
        $this->dropAllViews();

        $this->connection->disconnect();

        // Use the catalog names with their real case. getTables() lowercases
        // them through the processor, which would re-quote a legacy uppercase
        // table as a non-existent lowercase identifier on DROP.
        $tables = $this->getRawRelationNames(0);

        if ($tables === []) {
            return;
        }

        $generators = [];

        foreach ($tables as $table) {
            $generators = array_merge($generators, $this->autoIncrementGeneratorsForTable($table));

            foreach ($this->getForeignKeys($table) as $foreignKey) {
                $this->connection->statement(sprintf(
                    'ALTER TABLE %s DROP CONSTRAINT %s',
                    $this->grammar->wrapTable($table),
                    $this->grammar->wrap($foreignKey['name']),
                ));
            }
        }

        foreach ($tables as $table) {
            $this->connection->statement('DROP TABLE '.$this->grammar->wrapTable($table));
        }

        foreach (array_unique($generators) as $generator) {
            $this->dropAutoIncrementGenerator($generator);
        }

        $this->connection->disconnect();
    }

    /**
     * Drop all views from the database.
     *
     * @return void
     */
    public function dropAllViews()
    {
        $this->connection->disconnect();

        $views = $this->getRawRelationNames(1);

        if ($views === []) {
            return;
        }

        foreach ($views as $view) {
            $this->connection->statement('DROP VIEW '.$this->grammar->wrapTable($view));
        }
    }

    /**
     * Enable foreign key constraints.
     *
     * Firebird does not expose a connection-level FK toggle.
     *
     * @return bool
     */
    public function enableForeignKeyConstraints()
    {
        return true;
    }

    /**
     * Disable foreign key constraints.
     *
     * Firebird does not expose a connection-level FK toggle.
     *
     * @return bool
     */
    public function disableForeignKeyConstraints()
    {
        return true;
    }

    /**
     * Get relation names from the catalog preserving their real case.
     *
     * Unlike getTables()/getViews(), the names are not lowercased, so they can
     * be re-quoted to reference tables created without quotes (which Firebird
     * stores in uppercase) as well as quoted lowercase tables.
     *
     * @param  int  $type  0 for tables, 1 for views
     * @return list<string>
     */
    protected function getRawRelationNames($type)
    {
        $relations = $this->connection->select(
            'select trim(trailing from rdb$relation_name) as name '
            .'from rdb$relations '
            .'where rdb$relation_type = ? '
            .'and (rdb$system_flag is null or rdb$system_flag = 0) '
            .'order by rdb$relation_name',
            [$type]
        );

        return array_values(array_filter(array_map(function ($relation) {
            $relation = (array) $relation;

            return $relation['name'] ?? $relation['NAME'] ?? null;
        }, $relations)));
    }

    /**
     * Drop an auto-increment generator discovered from trigger dependencies.
     *
     * @param  string  $generator
     * @return void
     */
    protected function dropAutoIncrementGenerator($generator)
    {
        $this->connection->statement(sprintf(
            'execute block as begin if (exists(select 1 from rdb$generators where trim(rdb$generator_name) = %s)) then execute statement %s; end',
            $this->quoteString($generator),
            $this->quoteString('drop generator '.$this->grammar->wrap($generator)),
        ));
    }

    /**
     * Quote a string literal for metadata checks.
     *
     * @param  string  $value
     * @return string
     */
    protected function quoteString($value)
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
