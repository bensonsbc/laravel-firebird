<?php

namespace Benson\LaravelFirebird\Schema;

use Illuminate\Database\Schema\Blueprint as BaseBlueprint;

class Blueprint extends BaseBlueprint
{
    /**
     * Create a unique index (without a unique constraint) on the table.
     *
     * Unlike unique(), which adds a UNIQUE constraint, this issues a plain
     * CREATE UNIQUE INDEX. The auto-generated name follows the `uniqueIndex`
     * template from `index_names` when configured.
     *
     * @param  string|array  $columns
     * @param  string|null  $name
     * @param  string|null  $algorithm
     * @return \Illuminate\Support\Fluent
     */
    public function uniqueIndex($columns, $name = null, $algorithm = null)
    {
        return $this->indexCommand('uniqueIndex', $columns, $name, $algorithm);
    }

    /**
     * Drop a unique index by name or by columns.
     *
     * @param  string|array  $index
     * @return \Illuminate\Support\Fluent
     */
    public function dropUniqueIndex($index)
    {
        return $this->dropIndexCommand('dropIndex', 'uniqueIndex', $index);
    }

    /**
     * Resolve the auto-generated name for an index/constraint type.
     *
     * Exposes the naming convention so the grammar can name inline primary
     * keys of auto-increment columns (which never reach compilePrimary).
     *
     * @param  string  $type
     * @param  array  $columns
     * @return string
     */
    public function resolveIndexName($type, array $columns)
    {
        return $this->createIndexName($type, $columns);
    }

    /**
     * Create a default index name for the table.
     *
     * When the connection defines `index_names` templates, the project's own
     * naming convention is applied to auto-generated primary, foreign, unique
     * and plain index names. Without a template the framework default is kept.
     *
     * @param  string  $type
     * @param  array  $columns
     * @return string
     */
    protected function createIndexName($type, array $columns)
    {
        $templates = $this->connection->getConfig('index_names') ?: [];

        if (! is_array($templates) || ! isset($templates[$type])) {
            return parent::createIndexName($type, $columns);
        }

        $name = strtr($templates[$type], [
            '{table}' => $this->resolveIndexNameTable(),
            '{columns}' => implode('_', $columns),
        ]);

        return str_replace(['-', '.'], '_', $name);
    }

    /**
     * Resolve the table portion used in index name templates.
     *
     * Mirrors the framework's prefix handling so prefixed schemas keep working.
     *
     * @return string
     */
    protected function resolveIndexNameTable()
    {
        if (! $this->connection->getConfig('prefix_indexes')) {
            return $this->table;
        }

        return str_contains($this->table, '.')
            ? substr_replace($this->table, '.'.$this->connection->getTablePrefix(), strrpos($this->table, '.'), 1)
            : $this->connection->getTablePrefix().$this->table;
    }
}
