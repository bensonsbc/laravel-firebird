<?php

namespace Benson\LaravelFirebird\Query\Processors;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\Processor;

class FirebirdProcessor extends Processor
{
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
