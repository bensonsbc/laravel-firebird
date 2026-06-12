<?php

namespace Benson\LaravelFirebird\Schema;

use Benson\LaravelFirebird\Concerns\DiscoversAutoIncrementGenerators;
use Illuminate\Database\Schema\Builder as BaseBuilder;

class Builder extends BaseBuilder
{
    use DiscoversAutoIncrementGenerators;

    /**
     * Drop all tables from the database.
     *
     * @return void
     */
    public function dropAllTables()
    {
        $this->dropAllViews();

        $this->connection->disconnect();

        $tables = array_column($this->getTables(), 'name');

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

        $views = array_column($this->getViews(), 'name');

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
