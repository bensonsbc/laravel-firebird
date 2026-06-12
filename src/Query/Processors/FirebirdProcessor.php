<?php

namespace Benson\LaravelFirebird\Query\Processors;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\Processor;

class FirebirdProcessor extends Processor
{
    /**
     * Process the results of a tables query.
     *
     * @param  list<array<string, mixed>>  $results
     * @return list<array{name: string, schema: string|null, schema_qualified_name: string, size: int|null, comment: string|null, collation: string|null, engine: string|null}>
     */
    public function processTables($results)
    {
        return array_map(function ($table) {
            $table = parent::processTables([$table])[0];
            $table['name'] = strtolower($table['name']);
            $table['schema_qualified_name'] = strtolower($table['schema_qualified_name']);

            return $table;
        }, $results);
    }

    /**
     * Process the results of a columns query.
     *
     * @param  list<array<string, mixed>>  $results
     * @return list<array{name: string, type: string, type_name: string, collation: string|null, nullable: bool, default: mixed, auto_increment: bool, comment: string|null, generation: array{type: string|null, expression: string|null}|null}>
     */
    public function processColumns($results)
    {
        return array_map(function ($column) {
            $column = (array) $column;

            if (isset($column['name'])) {
                $column['name'] = strtolower($column['name']);
            }

            return $column;
        }, $results);
    }

    /**
     * Process the results of an indexes query.
     *
     * @param  list<array<string, mixed>>  $results
     * @return list<array{name: string, columns: list<string>, type: string|null, unique: bool, primary: bool}>
     */
    public function processIndexes($results)
    {
        return array_map(function ($index) {
            $index = (array) $index;

            return [
                'name' => strtolower($index['name']),
                'columns' => array_map('strtolower', explode(',', $index['columns'])),
                'type' => null,
                'unique' => (bool) $index['is_unique'],
                'primary' => (bool) $index['is_primary'],
            ];
        }, $results);
    }

    /**
     * Process an "insert get ID" query.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  string  $sql
     * @param  array  $values
     * @param  string|null  $sequence
     * @return int
     */
    public function processInsertGetId(Builder $query, $sql, $values, $sequence = null)
    {
        // The pdo_firebird driver does not support `lastInsertId()`. Perform
        // the insert operation in a way that returns the id.

        $result = $query->getConnection()->selectFromWriteConnection($sql, $values)[0];

        $id = $this->getReturnedId($result, $sequence ?: 'id');

        return is_numeric($id) ? (int) $id : $id;
    }

    /**
     * Get the returned ID value using a case-insensitive column lookup.
     *
     * @param  object|array  $result
     * @param  string  $sequence
     * @return mixed
     */
    protected function getReturnedId($result, $sequence)
    {
        $row = (array) $result;
        $sequence = $this->normalizeSequenceName($sequence);

        foreach ([$sequence, strtolower($sequence), strtoupper($sequence)] as $key) {
            if (array_key_exists($key, $row)) {
                return $row[$key];
            }
        }

        foreach ($row as $key => $value) {
            if (strcasecmp($key, $sequence) === 0) {
                return $value;
            }
        }

        return reset($row);
    }

    /**
     * Normalize the requested sequence column for resultset lookups.
     *
     * @param  string  $sequence
     * @return string
     */
    protected function normalizeSequenceName($sequence)
    {
        $sequence = trim($sequence, '"');

        if (str_contains($sequence, '.')) {
            $segments = explode('.', $sequence);
            $sequence = end($segments);
        }

        return trim($sequence, '"');
    }
}
