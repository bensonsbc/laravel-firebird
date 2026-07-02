<?php

namespace Benson\LaravelFirebird\Query\Grammars;

use Benson\LaravelFirebird\Concerns\WrapsIdentifiers;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\JoinLateralClause;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class FirebirdGrammar extends Grammar
{
    use WrapsIdentifiers;

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

        return $this->quoteIdentifier($value);
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

        return $this->quoteIdentifier($value);
    }

    /**
     * Determine whether a value is a SQL function call.
     *
     * @param  string  $value
     * @return bool
     */
    protected function looksLikeFunctionCall($value)
    {
        // Quotes and statement separators are rejected so this passthrough
        // cannot smuggle arbitrary SQL through identifier wrapping.
        return preg_match('/^[A-Za-z_][A-Za-z0-9_$]*\s*\([^;\'"]*\)$/', $value) === 1;
    }

    /**
     * Compile a group limit clause.
     *
     * Group limits rely on window functions, which require Firebird 3.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return string
     */
    protected function compileGroupLimit(Builder $query)
    {
        if (! $this->connection->isServerVersionAtLeast('3.0')) {
            throw new RuntimeException('This database engine version does not support group limits on eager loaded relationships.');
        }

        if (is_null($query->columns) || $query->columns === ['*']) {
            $query->columns = [new Expression($this->wrapTable($this->groupLimitStarQualifier($query)).'.*')];
        }

        return parent::compileGroupLimit($query);
    }

    /**
     * Resolve the qualifier used for a Firebird group limit star projection.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return string
     */
    protected function groupLimitStarQualifier(Builder $query)
    {
        if (is_string($query->from) && preg_match('/\s+as\s+(.+)$/i', $query->from, $matches)) {
            return $matches[1];
        }

        return $query->from;
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
     * Firebird only has exclusive row locks (WITH LOCK); a silent no-op for
     * sharedLock() would drop the caller's concurrency guarantee, so it fails
     * loudly instead.
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

        if ($value === false) {
            throw new RuntimeException('Firebird does not support shared locks. Use lockForUpdate() instead.');
        }

        return 'for update with lock';
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
        // FIRST applies only to the first branch of a union, so union queries
        // must be wrapped in a derived table before limiting.
        if ($query->unions) {
            return sprintf(
                'select first 1 1 as EXISTS_RESULT from (%s) FB_EXISTS',
                $this->compileSelect($query)
            );
        }

        $existsQuery = clone $query;

        $existsQuery->columns = [new Expression('1 as EXISTS_RESULT')];
        $existsQuery->limit = null;
        $existsQuery->offset = null;
        $existsQuery->orders = null;

        return preg_replace('/^select\s+/i', 'select first 1 ', $this->compileSelect($existsQuery), 1);
    }

    /**
     * The maximum number of values in a single Firebird IN list.
     *
     * Servers before Firebird 5 reject IN lists with 1500 or more items, so
     * larger lists are split into multiple IN groups.
     *
     * @var int
     */
    protected $maxInListSize = 1499;

    /**
     * Compile a "where in" clause.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereIn(Builder $query, $where)
    {
        return $this->compileChunkedIn($query, $where, 'whereIn', ' or ')
            ?? parent::whereIn($query, $where);
    }

    /**
     * Compile a "where not in" clause.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereNotIn(Builder $query, $where)
    {
        return $this->compileChunkedIn($query, $where, 'whereNotIn', ' and ')
            ?? parent::whereNotIn($query, $where);
    }

    /**
     * Compile a "where in raw" clause.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereInRaw(Builder $query, $where)
    {
        return $this->compileChunkedIn($query, $where, 'whereInRaw', ' or ')
            ?? parent::whereInRaw($query, $where);
    }

    /**
     * Compile a "where not in raw" clause.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereNotInRaw(Builder $query, $where)
    {
        return $this->compileChunkedIn($query, $where, 'whereNotInRaw', ' and ')
            ?? parent::whereNotInRaw($query, $where);
    }

    /**
     * Split an oversized IN list into multiple grouped IN clauses.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @param  string  $method
     * @param  string  $glue
     * @return string|null
     */
    protected function compileChunkedIn(Builder $query, $where, $method, $glue)
    {
        if (! is_array($where['values']) || count($where['values']) <= $this->maxInListSize) {
            return null;
        }

        $segments = array_map(
            fn ($chunk) => parent::{$method}($query, array_merge($where, ['values' => $chunk])),
            array_chunk($where['values'], $this->maxInListSize)
        );

        return '('.implode($glue, $segments).')';
    }

    /**
     * Compile a "where like" clause.
     *
     * Firebird delegates case/accent sensitivity to the column or expression
     * collation. Do not emulate Laravel's case-insensitive flag with UPPER(),
     * because that changes index usage and ignores user-selected collations.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereLike(Builder $query, $where)
    {
        $where['operator'] = $where['not'] ? 'not like' : 'like';

        return $this->whereBasic($query, $where);
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
     * Lateral derived tables require Firebird 4.
     *
     * @param  \Illuminate\Database\Query\JoinLateralClause  $join
     * @param  string  $expression
     * @return string
     */
    public function compileJoinLateral(JoinLateralClause $join, string $expression): string
    {
        if (! $this->connection->isServerVersionAtLeast('4.0')) {
            throw new RuntimeException('This database engine version does not support lateral joins.');
        }

        return trim("{$join->type} join lateral {$expression} on true");
    }

    /**
     * Compile the query to get the number of open connections for a database.
     *
     * @return string
     */
    public function compileThreadCount()
    {
        return 'select count(*) from mon$attachments where mon$system_flag is null or mon$system_flag = 0';
    }

    /**
     * Compile a truncate table statement into SQL.
     *
     * Firebird does not support TRUNCATE TABLE, so emulate it with DELETE.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return array
     */
    public function compileTruncate(Builder $query)
    {
        return [
            'delete from '.$this->wrapTable($query->from) => [],
        ];
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
     * Compile an insert ignore statement using a subquery into SQL.
     *
     * Each unique column set fully covered by the insert becomes a NOT EXISTS
     * guard; without resolved unique sets there is nothing to ignore and a
     * plain insert is compiled.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $columns
     * @param  string  $sql
     * @param  list<list<string>>  $uniqueColumnSets
     * @return string
     */
    public function compileInsertOrIgnoreUsing(Builder $query, array $columns, string $sql, array $uniqueColumnSets = [])
    {
        $table = $this->wrapTable($query->from);

        if (empty($columns) || $columns === ['*']) {
            return "insert into {$table} {$sql}";
        }

        if ($uniqueColumnSets === []) {
            return sprintf('insert into %s (%s) %s', $table, $this->columnize($columns), $sql);
        }

        $whereNotExists = implode(' and ', array_map(function ($set) use ($table) {
            $conditions = implode(' and ', array_map(
                fn ($column) => $this->wrap('T.'.$column).' = '.$this->wrap('V.'.$column),
                $set
            ));

            return "not exists (select 1 from {$table} T where {$conditions})";
        }, $uniqueColumnSets));

        return sprintf(
            'insert into %s (%s) select %s from (%s) V where %s',
            $table,
            $this->columnize($columns),
            implode(', ', array_map(fn ($column) => $this->wrap('V.'.$column), $columns)),
            $sql,
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
        $columns = array_keys(Arr::first($values));
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
            fn ($column) => $this->compileTypedSourceParameter($record[$column]).' as '.$this->wrap($column),
            $columns
        ));
    }

    /**
     * Compile a typed parameter for Firebird's derived source tables, which
     * cannot infer parameter types on their own.
     *
     * @param  mixed  $value
     * @return string
     */
    protected function compileTypedSourceParameter($value)
    {
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
            return $this->connection->supportsBooleanType()
                ? 'cast(? as boolean)'
                : 'cast(? as smallint)';
        }

        // Byte length over-allocates for multi-byte strings, which is safe.
        // Values beyond UTF8's varchar limit (8191 chars) fall back to a blob.
        $length = max(1, strlen((string) $value));

        if ($length > 8191) {
            return 'cast(? as blob sub_type text)';
        }

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
