<?php

namespace Benson\LaravelFirebird\Query\Grammars;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\JoinLateralClause;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class FirebirdGrammar extends Grammar
{
    /**
     * Identifiers that are problematic enough to quote even in legacy mode.
     *
     * @var string[]
     */
    protected $reservedIdentifiers = [
        'KEY',
        'TIMESTAMP',
        'VALUE',
    ];

    /**
     * The components that make up a select clause.
     *
     * @var string[]
     */
    protected $selectComponents = [
        'aggregate',
        'columns',
        'from',
        'joins',
        'wheres',
        'groups',
        'havings',
        'orders',
        'offset',
        'limit',
        'lock',
    ];

    /**
     * All of the available clause operators.
     *
     * @var string[]
     *
     * @link https://www.firebirdsql.org/file/documentation/html/en/refdocs/fblangref50/firebird-50-language-reference.html#fblangref50-commons-predicates
     */
    protected $operators = [
        '=', '<', '>', '<=', '>=', '<>', '!=',
        '!<', '!>', '~<', '~>', '^<', '^>', '~=', '^=',
        'like', 'not like', 'between', 'not between',
        'containing', 'not containing', 'starting with', 'not starting with',
        'similar to', 'not similar to', 'is distinct from', 'is not distinct from',
    ];

    /**
     * Compile a select query into SQL.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return string
     */
    public function compileSelect(Builder $query)
    {
        if ($query->unions && ! empty($query->unionOrders)) {
            return $this->compileOrderedUnionSelect($query);
        }

        return parent::compileSelect($query);
    }

    /**
     * Wrap a single string in keyword identifiers.
     *
     * @param  string  $value
     * @return string
     */
    protected function wrapValue($value)
    {
        if ($value === '*') {
            return $value;
        }

        return $this->wrapIdentifier($value);
    }

    /**
     * Wrap a value that has an alias.
     *
     * @param  string  $value
     * @return string
     */
    protected function wrapAliasedValue($value)
    {
        $segments = preg_split('/\s+as\s+/i', $value);

        return $this->wrap($segments[0]).' as '.$this->wrapAlias($segments[1]);
    }

    /**
     * Wrap an aliased table.
     *
     * @param  string  $value
     * @param  string|null  $prefix
     * @return string
     */
    protected function wrapAliasedTable($value, $prefix = null)
    {
        $segments = preg_split('/\s+as\s+/i', $value);

        $prefix ??= $this->connection->getTablePrefix();

        return $this->wrapTable($segments[0], $prefix).' as '.$this->wrapIdentifier($prefix.$segments[1]);
    }

    /**
     * Wrap an alias without applying uppercase identifier normalization.
     *
     * @param  string  $value
     * @return string
     */
    protected function wrapAlias($value)
    {
        if ((string) $this->connection->getConfig('dialect') === '1') {
            return $this->normalizeIdentifier($value);
        }

        return '"'.str_replace('"', '""', $value).'"';
    }

    /**
     * Wrap a Firebird identifier according to the connection configuration.
     *
     * @param  string  $value
     * @return string
     */
    protected function wrapIdentifier($value)
    {
        if ($this->isAlreadyQuoted($value) || $this->looksLikeFunctionCall($value)) {
            return $value;
        }

        $value = $this->normalizeIdentifier($value);

        if (! $this->shouldQuoteIdentifiers() && ! $this->isReservedIdentifier($value)) {
            return $value;
        }

        return '"'.str_replace('"', '""', $value).'"';
    }

    /**
     * Normalize an identifier segment for legacy Firebird schemas.
     *
     * @param  string  $value
     * @return string
     */
    protected function normalizeIdentifier($value)
    {
        if (! $this->shouldUppercaseIdentifiers() || ! preg_match('/^[A-Za-z_][A-Za-z0-9_$]*$/', $value)) {
            return $value;
        }

        return Str::upper($value);
    }

    /**
     * Determine whether identifiers should be quoted.
     *
     * @return bool
     */
    protected function shouldQuoteIdentifiers()
    {
        return $this->connection->getConfig('quote_identifiers', true) !== false;
    }

    /**
     * Determine whether unquoted identifiers should be uppercased.
     *
     * @return bool
     */
    protected function shouldUppercaseIdentifiers()
    {
        return $this->connection->getConfig('uppercase_identifiers', false) === true;
    }

    /**
     * Determine whether a value is already explicitly quoted.
     *
     * @param  string  $value
     * @return bool
     */
    protected function isAlreadyQuoted($value)
    {
        return str_starts_with($value, '"') && str_ends_with($value, '"');
    }

    /**
     * Determine whether a value is a SQL function call.
     *
     * @param  string  $value
     * @return bool
     */
    protected function looksLikeFunctionCall($value)
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_$]*\s*\(.*\)$/', $value) === 1;
    }

    /**
     * Determine whether an identifier should be protected as reserved.
     *
     * @param  string  $value
     * @return bool
     */
    protected function isReservedIdentifier($value)
    {
        return in_array(Str::upper($value), $this->reservedIdentifiers, true);
    }

    /**
     * Compile the "limit" portions of the query.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  int  $limit
     * @return string
     */
    protected function compileLimit(Builder $query, $limit)
    {
        if ($this->usesFirstSkipPagination()) {
            return '';
        }

        return 'fetch first '.(int) $limit.' rows only';
    }

    /**
     * Compile the "offset" portions of the query.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  int  $offset
     * @return string
     */
    protected function compileOffset(Builder $query, $offset)
    {
        if ($this->usesFirstSkipPagination()) {
            return '';
        }

        return 'offset '.(int) $offset.' rows';
    }

    /**
     * Compile the select clause.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $columns
     * @return string|void
     */
    protected function compileColumns(Builder $query, $columns)
    {
        if (! is_null($query->aggregate)) {
            return;
        }

        $select = 'select ';

        if ($this->usesFirstSkipPagination()) {
            $select .= $this->compileFirstSkip($query);
        }

        if ($query->distinct) {
            $select .= 'distinct ';
        }

        return $select.$this->columnize($columns);
    }

    /**
     * Compile Firebird FIRST/SKIP pagination.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return string
     */
    protected function compileFirstSkip(Builder $query)
    {
        $sql = '';

        if (isset($query->limit)) {
            $sql .= 'first '.(int) $query->limit.' ';
        }

        if (isset($query->offset)) {
            $sql .= 'skip '.(int) $query->offset.' ';
        }

        return $sql;
    }

    /**
     * Determine whether FIRST/SKIP pagination should be used.
     *
     * @return bool
     */
    protected function usesFirstSkipPagination()
    {
        return ($this->connection->getConfig('pagination_mode') ?: 'first_skip') === 'first_skip';
    }

    /**
     * Compile the random statement into SQL.
     *
     * @param  string  $seed
     * @return string
     */
    public function compileRandom($seed)
    {
        return 'rand()';
    }

    /**
     * Compile the lock into SQL.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  bool|string  $value
     * @return string
     */
    protected function compileLock(Builder $query, $value)
    {
        if (is_string($value)) {
            return $value;
        }

        return $value === true ? 'for update with lock' : '';
    }

    /**
     * Wrap a union subquery in parentheses.
     *
     * @param  string  $sql
     * @return string
     */
    protected function wrapUnion($sql)
    {
        return 'select * from ('.$sql.')';
    }

    /**
     * Compile the "union" queries attached to the main query.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return string
     */
    protected function compileUnions(Builder $query)
    {
        // This method is the same as the parent implementation, except that the
        // order of offset and limit for union queries is reversed: offset must
        // precede limit. This is due to Firebird's SQL syntax for union queries.

        $sql = '';

        foreach ($query->unions as $union) {
            $sql .= $this->compileUnion($union);
        }

        if (! empty($query->unionOrders)) {
            $sql .= ' '.$this->compileOrders($query, $query->unionOrders);
        }

        if ($this->usesFirstSkipPagination()) {
            return ltrim($sql.' '.$this->compileUnionRows($query));
        }

        if (isset($query->unionOffset)) {
            $sql .= ' '.$this->compileOffset($query, $query->unionOffset);
        }

        if (isset($query->unionLimit)) {
            $sql .= ' '.$this->compileLimit($query, $query->unionLimit);
        }

        return ltrim($sql);
    }

    /**
     * Compile Firebird ROWS pagination for union queries.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return string
     */
    protected function compileUnionRows(Builder $query)
    {
        $hasLimit = isset($query->unionLimit);
        $hasOffset = isset($query->unionOffset);

        if (! $hasLimit && ! $hasOffset) {
            return '';
        }

        $offset = $hasOffset ? (int) $query->unionOffset : 0;

        if ($hasLimit) {
            $limit = (int) $query->unionLimit;

            if ($offset === 0) {
                return 'rows '.$limit;
            }

            return 'rows '.($offset + 1).' to '.($offset + $limit);
        }

        return 'rows '.($offset + 1).' to 2147483647';
    }

    /**
     * Compile a union query whose ordering must be applied from an outer query.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return string
     */
    protected function compileOrderedUnionSelect(Builder $query)
    {
        $unionQuery = clone $query;
        $unionQuery->unionOrders = null;
        $unionQuery->unionLimit = null;
        $unionQuery->unionOffset = null;

        $sql = 'select * from ('.parent::compileSelect($unionQuery).') FB_UNION';
        $sql .= ' '.$this->compileOrders($query, $query->unionOrders);

        if ($this->usesFirstSkipPagination()) {
            return trim($sql.' '.$this->compileUnionRows($query));
        }

        if (isset($query->unionOffset)) {
            $sql .= ' '.$this->compileOffset($query, $query->unionOffset);
        }

        if (isset($query->unionLimit)) {
            $sql .= ' '.$this->compileLimit($query, $query->unionLimit);
        }

        return trim($sql);
    }

    /**
     * Compile an exists statement into SQL.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return string
     */
    public function compileExists(Builder $query)
    {
        $existsQuery = clone $query;

        $existsQuery->columns = [new Expression('1 as EXISTS_RESULT')];
        $existsQuery->limit = null;
        $existsQuery->offset = null;
        $existsQuery->orders = null;

        return preg_replace('/^select\s+/i', 'select first 1 ', $this->compileSelect($existsQuery), 1);
    }

    /**
     * Compile a date based where clause.
     *
     * @param  string  $type
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function dateBasedWhere($type, Builder $query, $where)
    {
        $condition = ($type === 'date' || $type === 'time')
            ? sprintf('cast(%s as %s)', $this->wrap($where['column']), $type)
            : sprintf('extract(%s from %s)', $type, $this->wrap($where['column']));

        return $condition.' '.$where['operator'].' '.$this->parameter($where['value']);
    }

    /**
     * Compile a dialect 1 compatible "where time" clause.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereTimeSeconds(Builder $query, $where)
    {
        $column = $this->wrap($where['column']);
        $condition = sprintf(
            '((extract(hour from %s) * 3600) + (extract(minute from %s) * 60) + cast(extract(second from %s) as integer))',
            $column,
            $column,
            $column
        );

        return $condition.' '.$where['operator'].' '.$this->parameter($where['value']);
    }

    /**
     * Compile a dialect 1 compatible "where date" clause.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereDateNumber(Builder $query, $where)
    {
        $column = $this->wrap($where['column']);
        $condition = sprintf(
            '((extract(year from %s) * 10000) + (extract(month from %s) * 100) + extract(day from %s))',
            $column,
            $column,
            $column
        );

        return $condition.' '.$where['operator'].' '.$this->parameter($where['value']);
    }

    /**
     * Compile the select clause for a stored procedure.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  string  $procedure
     * @param  array  $values
     * @return string
     */
    public function compileProcedure(Builder $query, $procedure, array $values = [])
    {
        return $this->wrap($procedure).' ('.$this->parameterize($values).')';
    }

    /**
     * Compile an aggregated select clause.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $aggregate
     * @return string
     */
    protected function compileAggregate(Builder $query, $aggregate)
    {
        if ((string) $this->connection->getConfig('dialect') === '1' && $aggregate['function'] === 'avg') {
            $column = $this->columnize($aggregate['columns']);

            return 'select floor(avg('.$column.' * 1.0000)) as aggregate';
        }

        // Wrap `aggregate` in double quotes to ensure the resultset returns the
        // column name as a lowercase string. This resolves compatibility with
        // the framework's paginator.
        return Str::replaceLast(
            'as aggregate', 'as "aggregate"', parent::compileAggregate($query, $aggregate)
        );
    }

    /**
     * Compile a "lateral join" clause.
     *
     * @param  \Illuminate\Database\Query\JoinLateralClause  $join
     * @param  string  $expression
     * @return string
     */
    public function compileJoinLateral(JoinLateralClause $join, string $expression): string
    {
        return trim("{$join->type} join lateral {$expression} on true");
    }

    /**
     * Compile a truncate table statement into SQL.
     *
     * Firebird servers older than 2.5 do not support TRUNCATE TABLE. Use DELETE
     * and reset the generator convention created by the schema grammar.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return array
     */
    public function compileTruncate(Builder $query)
    {
        return [
            'delete from '.$this->wrapTable($query->from) => [],
            $this->compileAutoIncrementGeneratorReset($query->from) => [],
        ];
    }

    /**
     * Compile a conditional reset for the conventional auto-increment generator.
     *
     * @param  string  $table
     * @return string
     */
    protected function compileAutoIncrementGeneratorReset($table)
    {
        $generator = $this->normalizeObjectName(substr($table.'_id_gen', 0, 31));

        return sprintf(
            'execute block as begin if (exists(select 1 from rdb$generators where trim(rdb$generator_name) = %s)) then execute statement %s; end',
            $this->quoteString($generator),
            $this->quoteString('set generator '.$this->wrap($generator).' to 0')
        );
    }

    /**
     * Normalize a database object lookup for legacy uppercase schemas.
     *
     * @param  string  $name
     * @return string
     */
    protected function normalizeObjectName($name)
    {
        return $this->shouldUppercaseIdentifiers() ? Str::upper($name) : $name;
    }

    /**
     * Compile an update statement with joins into SQL.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  string  $table
     * @param  string  $columns
     * @param  string  $where
     * @return string
     */
    protected function compileUpdateWithJoins(Builder $query, $table, $columns, $where)
    {
        return sprintf(
            'update %s set %s where RDB$DB_KEY in (%s)',
            $table,
            $columns,
            $this->compileJoinedDbKeySubquery($query)
        );
    }

    /**
     * Prepare the bindings for an update statement.
     *
     * @param  array  $bindings
     * @param  array  $values
     * @return array
     */
    public function prepareBindingsForUpdate(array $bindings, array $values)
    {
        $cleanBindings = Arr::except($bindings, ['select', 'join']);

        $values = Arr::flatten(array_map(fn ($value) => value($value), $values));

        return array_values(
            array_merge($values, $bindings['join'], Arr::flatten($cleanBindings))
        );
    }

    /**
     * Compile a delete statement with joins into SQL.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  string  $table
     * @param  string  $where
     * @return string
     */
    protected function compileDeleteWithJoins(Builder $query, $table, $where)
    {
        return sprintf(
            'delete from %s where RDB$DB_KEY in (%s)',
            $table,
            $this->compileJoinedDbKeySubquery($query)
        );
    }

    /**
     * Compile the subquery that locates joined mutation target records.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return string
     */
    protected function compileJoinedDbKeySubquery(Builder $query)
    {
        $table = $this->wrapTable($query->from);
        $joins = $this->compileJoins($query, $query->joins);
        $where = $this->compileWheres($query);

        return trim(sprintf(
            'select %s from %s %s %s',
            $this->wrapTable($this->joinedMutationTargetQualifier($query)).'.RDB$DB_KEY',
            $table,
            $joins,
            $where
        ));
    }

    /**
     * Resolve the table or alias used to qualify RDB$DB_KEY in joined mutations.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return string
     */
    protected function joinedMutationTargetQualifier(Builder $query)
    {
        if (is_string($query->from) && preg_match('/\s+as\s+(.+)$/i', $query->from, $matches)) {
            return $matches[1];
        }

        return $query->from;
    }

    /**
     * Compile an insert and get ID statement into SQL.
     *
     * @param  Builder  $query
     * @param  array  $values
     * @param  string|null  $sequence
     * @return string
     */
    public function compileInsertGetId(Builder $query, $values, $sequence)
    {
        // The pdo_firebird driver does not support `lastInsertId()`. Perform
        // the insert operation in a way that returns the id.
        return $this->compileInsert($query, $values).' returning '.$this->wrap($sequence ?: 'id');
    }

    /**
     * Compile an insert ignore statement into SQL.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $values
     * @return string
     */
    public function compileInsertOrIgnore(Builder $query, array $values)
    {
        if (! is_array(reset($values))) {
            $values = [$values];
        }

        if (count($values) !== 1) {
            return $this->compileInsert($query, $values);
        }

        $row = $values[0];
        $columns = array_keys($row);
        $table = $this->wrapTable($query->from);
        $matchingColumns = $this->resolveInsertOrIgnoreMatchingColumns($columns);

        $selectColumns = implode(', ', array_map(
            fn ($column) => sprintf(
                '%s as %s',
                $this->compileTypedInsertOrIgnoreParameter($column, $row[$column]),
                $this->wrap($column)
            ),
            $columns
        ));

        $whereNotExists = implode(' and ', array_map(
            fn ($column) => $this->wrap('T.'.$column).' = '.$this->wrap('V.'.$column),
            $matchingColumns
        ));

        return sprintf(
            'insert into %s (%s) select %s from (select %s from RDB$DATABASE) V where not exists (select 1 from %s T where %s)',
            $table,
            $this->columnize($columns),
            implode(', ', array_map(fn ($column) => $this->wrap('V.'.$column), $columns)),
            $selectColumns,
            $table,
            $whereNotExists
        );
    }

    /**
     * Compile an insert ignore statement using a subquery into SQL.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $columns
     * @param  string  $sql
     * @return string
     */
    public function compileInsertOrIgnoreUsing(Builder $query, array $columns, string $sql)
    {
        $table = $this->wrapTable($query->from);

        if (empty($columns) || $columns === ['*']) {
            return "insert into {$table} {$sql}";
        }

        $matchingColumns = $this->resolveInsertOrIgnoreMatchingColumns($columns);

        $whereNotExists = implode(' and ', array_map(
            fn ($column) => $this->wrap('T.'.$column).' = '.$this->wrap('V.'.$column),
            $matchingColumns
        ));

        return sprintf(
            'insert into %s (%s) select %s from (%s) V where not exists (select 1 from %s T where %s)',
            $table,
            $this->columnize($columns),
            implode(', ', array_map(fn ($column) => $this->wrap('V.'.$column), $columns)),
            $sql,
            $table,
            $whereNotExists
        );
    }

    /**
     * Compile an "upsert" statement into SQL.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $values
     * @param  array  $uniqueBy
     * @param  array  $update
     * @return string
     */
    public function compileUpsert(Builder $query, array $values, array $uniqueBy, array $update)
    {
        $columns = array_keys(array_first($values));
        $columnList = $this->columnize($columns);
        $table = $this->wrapTable($query->from);
        $source = $this->compileUpsertSource($values, $columns);

        $on = (new Collection($uniqueBy))
            ->map(fn ($column) => $this->wrap('S.'.$column).' = '.$this->wrap('T.'.$column))
            ->implode(' and ');

        $sql = "merge into {$table} T using ({$source}) S on {$on}";

        if ($update) {
            $assignments = (new Collection($update))
                ->map(fn ($value, $key) => is_int($key)
                    ? $this->wrap($value).' = '.$this->wrap('S.'.$value)
                    : $this->wrap($key).' = '.$this->parameter($value)
                )
                ->implode(', ');

            $sql .= " when matched then update set {$assignments}";
        }

        $insertValues = implode(', ', array_map(
            fn ($column) => $this->wrap('S.'.$column),
            $columns
        ));

        return "{$sql} when not matched then insert ({$columnList}) values ({$insertValues})";
    }

    /**
     * Compile Firebird's derived source table for a merge statement.
     *
     * @param  array  $values
     * @param  array  $columns
     * @return string
     */
    protected function compileUpsertSource(array $values, array $columns)
    {
        return (new Collection($values))
            ->map(fn ($record) => 'select '.$this->compileUpsertSourceColumns($record, $columns).' from RDB$DATABASE')
            ->implode(' union all ');
    }

    /**
     * Compile one row of the merge source table.
     *
     * @param  array  $record
     * @param  array  $columns
     * @return string
     */
    protected function compileUpsertSourceColumns(array $record, array $columns)
    {
        return implode(', ', array_map(
            fn ($column) => $this->compileTypedInsertOrIgnoreParameter($column, $record[$column]).' as '.$this->wrap($column),
            $columns
        ));
    }

    /**
     * Resolve columns that should determine whether a row already exists.
     *
     * @param  array  $columns
     * @return array
     */
    protected function resolveInsertOrIgnoreMatchingColumns(array $columns)
    {
        $normalized = array_map(fn ($column) => Str::lower($column), $columns);

        foreach (['key', 'id'] as $preferredColumn) {
            $index = array_search($preferredColumn, $normalized, true);

            if ($index !== false) {
                return [$columns[$index]];
            }
        }

        return $columns;
    }

    /**
     * Compile a typed parameter for Firebird's derived insert source.
     *
     * @param  string  $column
     * @param  mixed  $value
     * @return string
     */
    protected function compileTypedInsertOrIgnoreParameter($column, $value)
    {
        $normalizedColumn = Str::lower($column);

        if (in_array($normalizedColumn, ['key', 'owner'], true)) {
            return 'cast(? as varchar(255))';
        }

        if ($normalizedColumn === 'expiration') {
            return 'cast(? as integer)';
        }

        if ($value === null) {
            return 'cast(? as varchar(255))';
        }

        if (is_int($value)) {
            return 'cast(? as '.$this->integerParameterType().')';
        }

        if (is_float($value)) {
            return 'cast(? as double precision)';
        }

        if (is_bool($value)) {
            return 'cast(? as smallint)';
        }

        $length = max(1, strlen((string) $value));
        $length = min($length, 8191);

        return sprintf('cast(? as varchar(%d))', $length);
    }

    /**
     * Resolve a Firebird integer type that is valid for the configured dialect.
     *
     * @return string
     */
    protected function integerParameterType()
    {
        return (string) $this->connection->getConfig('dialect') === '1'
            ? 'integer'
            : 'bigint';
    }
}
