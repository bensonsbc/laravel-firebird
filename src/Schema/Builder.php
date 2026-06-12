<?php

namespace Benson\LaravelFirebird\Schema;

use Illuminate\Database\Schema\Builder as BaseBuilder;

class Builder extends BaseBuilder
{
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

        foreach ($tables as $table) {
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

        foreach ($tables as $table) {
            $this->dropConventionalAutoIncrementGenerator($table);
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
     * Drop the generator convention created by Firebird schema auto-increments.
     *
     * @param  string  $table
     * @return void
     */
    protected function dropConventionalAutoIncrementGenerator($table)
    {
        $generator = $this->normalizeObjectName(substr($table.'_id_gen', 0, 31));

        $this->connection->statement(sprintf(
            'execute block as begin if (exists(select 1 from rdb$generators where trim(rdb$generator_name) = %s)) then execute statement %s; end',
            $this->quoteString($generator),
            $this->quoteString('drop generator '.$this->grammar->wrap($generator)),
        ));
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
            ? strtoupper($name)
            : $name;
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
