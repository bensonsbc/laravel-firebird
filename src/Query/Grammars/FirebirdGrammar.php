<?php

namespace Benson\LaravelFirebird\Query\Grammars;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\JoinLateralClause;
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

        if (isset($query->unionOffset)) {
            $sql .= ' '.$this->compileOffset($query, $query->unionOffset);
        }

        if (isset($query->unionLimit)) {
            $sql .= ' '.$this->compileLimit($query, $query->unionLimit);
        }

        return ltrim($sql);
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
            return 'cast(? as bigint)';
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
}
