<?php

namespace Benson\LaravelFirebird\Concerns;

trait DiscoversAutoIncrementGenerators
{
    use NormalizesObjectNames;

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
}
