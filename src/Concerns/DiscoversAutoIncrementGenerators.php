<?php

namespace Benson\LaravelFirebird\Concerns;

trait DiscoversAutoIncrementGenerators
{
    use NormalizesObjectNames;

    /**
     * Discover generators used by insert triggers on a table.
     *
     * Only generators that belong to the table alone are returned: a generator
     * that any other object depends on (a trigger of another table, a stored
     * procedure, a view...) is shared, and truncating or dropping this table
     * must not rewind or drop it.
     *
     * @param  string  $table
     * @return list<string>
     */
    protected function autoIncrementGeneratorsForTable($table)
    {
        $table = $this->normalizeObjectName($table);

        $generators = $this->connection->select(
            'select distinct trim(d.rdb$depended_on_name) as name '
            .'from rdb$triggers t '
            .'join rdb$dependencies d on d.rdb$dependent_name = t.rdb$trigger_name '
            .'join rdb$generators g on g.rdb$generator_name = d.rdb$depended_on_name '
            .'where trim(t.rdb$relation_name) = ? '
            .'and (t.rdb$system_flag is null or t.rdb$system_flag = 0) '
            .'and not exists ('
            .'select 1 from rdb$dependencies d2 '
            .'left join rdb$triggers t2 on t2.rdb$trigger_name = d2.rdb$dependent_name '
            .'where d2.rdb$depended_on_name = d.rdb$depended_on_name '
            .'and d2.rdb$depended_on_type = d.rdb$depended_on_type '
            .'and (t2.rdb$relation_name is null or trim(t2.rdb$relation_name) <> ?)'
            .')',
            [$table, $table]
        );

        return array_values(array_filter(array_map(function ($generator) {
            $generator = (array) $generator;

            return $generator['name'] ?? $generator['NAME'] ?? null;
        }, $generators)));
    }

    /**
     * Discover identity columns on a table (Firebird 3+).
     *
     * @param  string  $table
     * @return list<string>
     */
    protected function identityColumnsForTable($table)
    {
        if (! $this->connection->isServerVersionAtLeast('3.0')) {
            return [];
        }

        $columns = $this->connection->select(
            'select trim(rdb$field_name) as name '
            .'from rdb$relation_fields '
            .'where trim(rdb$relation_name) = ? '
            .'and rdb$identity_type is not null',
            [$this->normalizeObjectName($table)]
        );

        return array_values(array_filter(array_map(function ($column) {
            $column = (array) $column;

            return $column['name'] ?? $column['NAME'] ?? null;
        }, $columns)));
    }
}
