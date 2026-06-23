<?php

namespace Benson\LaravelFirebird\Schema\Grammars;

use Benson\LaravelFirebird\Concerns\NormalizesObjectNames;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Support\Fluent;

class FirebirdGrammar extends Grammar
{
    use NormalizesObjectNames;

    /**
     * The possible column modifiers.
     *
     * @var array
     */
    protected $modifiers = ['Charset', 'Collate', 'Increment', 'Default', 'Nullable'];

    /**
     * The commands to be executed outside of create or alter command.
     *
     * @var array
     */
    protected $fluentCommands = ['Comment'];

    /**
     * The columns available as serials.
     *
     * @var array
     */
    protected $serials = ['bigInteger', 'integer', 'mediumInteger', 'smallInteger', 'tinyInteger'];

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

        if ($this->connection->getConfig('uppercase_identifiers', false) === true) {
            $value = strtoupper($value);
        }

        if ($this->connection->getConfig('quote_identifiers', true) === false) {
            return $value;
        }

        return '"'.str_replace('"', '""', $value).'"';
    }

    /**
     * Compile the query to determine if the given table exists.
     *
     * @param  string|null  $schema
     * @param  string  $table
     * @return string
     */
    public function compileTableExists($schema, $table)
    {
        return sprintf(
            'select exists (select 1 from rdb$relations where rdb$relation_name = %s and rdb$relation_type = 0 and '
            .'(rdb$system_flag is null or rdb$system_flag = 0)) from rdb$database',
            $this->quoteString($this->normalizeObjectName($table)),
        );
    }

    /**
     * Compile the query to determine the tables.
     *
     * @param  string|string[]|null  $schema
     * @return string
     */
    public function compileTables($schema)
    {
        return 'select trim(trailing from rdb$relation_name) as '.$this->wrapMetadataAlias('name').' '
            .'from rdb$relations '
            .'where rdb$relation_type = 0 '
            .'and (rdb$system_flag is null or rdb$system_flag = 0) '
            .'order by rdb$relation_name';
    }

    /**
     * Compile the query to determine the views.
     *
     * @param  string|string[]|null  $schema
     * @return string
     */
    public function compileViews($schema)
    {
        return 'select trim(trailing from rdb$relation_name) as '.$this->wrapMetadataAlias('name').', '
            .'cast(null as varchar(31)) as '.$this->wrapMetadataAlias('schema').', '
            .'cast(rdb$view_source as varchar(8191)) as '.$this->wrapMetadataAlias('definition').' '
            .'from rdb$relations '
            .'where rdb$relation_type = 1 '
            .'and (rdb$system_flag is null or rdb$system_flag = 0) '
            .'order by rdb$relation_name';
    }

    /**
     * Compile the query to determine the columns.
     *
     * @param  string|null  $schema
     * @param  string  $table
     * @return string
     */
    public function compileColumns($schema, $table)
    {
        // rdb$identity_type only exists from Firebird 3, so older servers
        // select a null placeholder instead.
        $identityType = $this->connection->isServerVersionAtLeast('3.0')
            ? 'rf.rdb$identity_type'
            : 'cast(null as smallint)';

        return sprintf(
            'select trim(trailing from rf.rdb$field_name) as %s, '
            .'f.rdb$field_type as %s, '
            .'f.rdb$field_sub_type as %s, '
            .'f.rdb$field_precision as %s, '
            .'f.rdb$field_scale as %s, '
            .'coalesce(f.rdb$character_length, f.rdb$field_length) as %s, '
            .'rf.rdb$null_flag as %s, '
            .'coalesce(rf.rdb$default_source, f.rdb$default_source) as %s, '
            .$identityType.' as %s, '
            .'rf.rdb$description as %s '
            .'from rdb$relation_fields rf '
            .'join rdb$fields f on f.rdb$field_name = rf.rdb$field_source '
            .'where rf.rdb$relation_name = %s '
            .'order by rf.rdb$field_position',
            $this->wrapMetadataAlias('name'),
            $this->wrapMetadataAlias('field_type'),
            $this->wrapMetadataAlias('field_sub_type'),
            $this->wrapMetadataAlias('field_precision'),
            $this->wrapMetadataAlias('field_scale'),
            $this->wrapMetadataAlias('field_length'),
            $this->wrapMetadataAlias('null_flag'),
            $this->wrapMetadataAlias('default_source'),
            $this->wrapMetadataAlias('identity_type'),
            $this->wrapMetadataAlias('comment'),
            $this->quoteString($this->normalizeObjectName($table)),
        );
    }

    /**
     * Compile the query to determine the indexes.
     *
     * @param  string|null  $schema
     * @param  string  $table
     * @return string
     */
    public function compileIndexes($schema, $table)
    {
        return sprintf(
            'select trim(ix.rdb$index_name) as %s, '
            .'list(trim(seg.rdb$field_name), \',\') as %s, '
            .'case when ix.rdb$unique_flag = 1 then 1 else 0 end as %s, '
            .'case when rc.rdb$constraint_type = \'PRIMARY KEY\' then 1 else 0 end as %s '
            .'from rdb$indices ix '
            .'join rdb$index_segments seg on seg.rdb$index_name = ix.rdb$index_name '
            .'left join rdb$relation_constraints rc on rc.rdb$index_name = ix.rdb$index_name '
            .'where ix.rdb$relation_name = %s '
            .'group by ix.rdb$index_name, ix.rdb$unique_flag, rc.rdb$constraint_type '
            .'order by ix.rdb$index_name',
            $this->wrapMetadataAlias('name'),
            $this->wrapMetadataAlias('columns'),
            $this->wrapMetadataAlias('is_unique'),
            $this->wrapMetadataAlias('is_primary'),
            $this->quoteString($this->normalizeObjectName($table)),
        );
    }

    /**
     * Compile the query to determine the foreign keys.
     *
     * @param  string|null  $schema
     * @param  string  $table
     * @return string
     */
    public function compileForeignKeys($schema, $table)
    {
        return sprintf(
            'select trim(rc.rdb$constraint_name) as %s, '
            .'(select list(trim(seg.rdb$field_name), \',\') from rdb$index_segments seg where seg.rdb$index_name = rc.rdb$index_name) as %s, '
            .'trim(pk.rdb$relation_name) as %s, '
            .'(select list(trim(seg.rdb$field_name), \',\') from rdb$index_segments seg where seg.rdb$index_name = pk.rdb$index_name) as %s, '
            .'trim(ref.rdb$update_rule) as %s, '
            .'trim(ref.rdb$delete_rule) as %s '
            .'from rdb$relation_constraints rc '
            .'join rdb$ref_constraints ref on ref.rdb$constraint_name = rc.rdb$constraint_name '
            .'join rdb$relation_constraints pk on pk.rdb$constraint_name = ref.rdb$const_name_uq '
            .'where rc.rdb$constraint_type = \'FOREIGN KEY\' '
            .'and rc.rdb$relation_name = %s '
            .'order by rc.rdb$constraint_name',
            $this->wrapMetadataAlias('name'),
            $this->wrapMetadataAlias('columns'),
            $this->wrapMetadataAlias('foreign_table'),
            $this->wrapMetadataAlias('foreign_columns'),
            $this->wrapMetadataAlias('on_update'),
            $this->wrapMetadataAlias('on_delete'),
            $this->quoteString($this->normalizeObjectName($table)),
        );
    }

    /**
     * Compile the query to determine the list of columns.
     *
     * @param  string  $table
     * @return string
     */
    public function compileColumnListing($table)
    {
        return sprintf(
            'select trim(rdb$field_name) as %s from rdb$relation_fields where rdb$relation_name = %s',
            $this->wrapMetadataAlias('column_name'),
            $this->quoteString($this->normalizeObjectName($table)),
        );
    }

    /**
     * Compile a create table command.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return string
     */
    public function compileCreate(Blueprint $blueprint, Fluent $command)
    {
        if ($blueprint->temporary) {
            throw new \LogicException('This database driver does not support temporary tables.');
        }

        $columns = implode(', ', $this->getColumns($blueprint));

        $sql = 'create table '.$this->wrapTable($blueprint)." ($columns)";

        $autoIncrementStatements = $this->compileAutoIncrementObjects($blueprint);

        return $autoIncrementStatements === [] ? $sql : array_merge([$sql], $autoIncrementStatements);
    }

    /**
     * Compile a drop table command.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return string
     */
    public function compileDrop(Blueprint $blueprint, Fluent $command)
    {
        return 'drop table '.$this->wrapTable($blueprint);
    }

    /**
     * Compile a drop table (if exists) command.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return string
     */
    public function compileDropIfExists(Blueprint $blueprint, Fluent $command)
    {
        return sprintf(
            'execute block as begin if (exists(select 1 from rdb$relations where rdb$relation_name = %s and rdb$relation_type = 0 and '
            .'(rdb$system_flag is null or rdb$system_flag = 0))) then execute statement \'drop table %s\'; end',
            $this->quoteString($this->normalizeObjectName($blueprint->getTable())),
            $this->wrapTable($blueprint)
        );
    }

    /**
     * Wrap a metadata alias when the configured dialect supports it.
     *
     * @param  string  $alias
     * @return string
     */
    protected function wrapMetadataAlias($alias)
    {
        return (string) $this->connection->getConfig('dialect') === '1'
            ? $alias
            : '"'.$alias.'"';
    }

    /**
     * Compile generator and trigger statements for auto-increment columns.
     *
     * Identity-capable servers handle generation in the column definition,
     * so the legacy generator and trigger objects are only created when the
     * server cannot use identity columns.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @return list<string>
     */
    protected function compileAutoIncrementObjects(Blueprint $blueprint)
    {
        if ($this->connection->supportsIdentityColumns()) {
            return [];
        }

        $statements = [];

        foreach ($blueprint->getColumns() as $column) {
            if (! $this->isAutoIncrementColumn($column)) {
                continue;
            }

            $generator = $this->autoIncrementGeneratorName($blueprint, $column);
            $trigger = $this->autoIncrementTriggerName($blueprint, $column);

            $statements[] = sprintf(
                'execute block as begin if (not exists(select 1 from rdb$generators where rdb$generator_name = %s)) then execute statement %s; end',
                $this->quoteString($this->normalizeObjectName($generator)),
                $this->quoteString('create generator '.$this->wrap($generator)),
            );

            $statements[] = 'set generator '.$this->wrap($generator).' to 0';

            $statements[] = sprintf(
                'CREATE TRIGGER %s FOR %s ACTIVE BEFORE INSERT POSITION 0 AS BEGIN IF (%s IS NULL) THEN %s = GEN_ID(%s, 1); END',
                $this->wrap($trigger),
                $this->wrapTable($blueprint),
                'NEW.'.$this->wrap($column->name),
                'NEW.'.$this->wrap($column->name),
                $this->wrap($generator),
            );
        }

        return $statements;
    }

    /**
     * Get the generator name for an auto-increment column.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function autoIncrementGeneratorName(Blueprint $blueprint, Fluent $column)
    {
        return $this->constrainIdentifier($blueprint->getTable().'_'.$column->name.'_gen');
    }

    /**
     * Get the trigger name for an auto-increment column.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function autoIncrementTriggerName(Blueprint $blueprint, Fluent $column)
    {
        return $this->constrainIdentifier($blueprint->getTable().'_'.$column->name.'_bi');
    }

    /**
     * Constrain an object name to the server's identifier length limit.
     *
     * Long names keep a hash suffix so two truncated names cannot collide
     * and silently reference the same database object.
     *
     * @param  string  $name
     * @return string
     */
    protected function constrainIdentifier($name)
    {
        $limit = $this->connection->getMaxIdentifierLength();

        if (strlen($name) <= $limit) {
            return $name;
        }

        return substr($name, 0, $limit - 9).'_'.substr(md5($name), 0, 8);
    }

    /**
     * Compile a column addition command.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return string
     */
    public function compileAdd(Blueprint $blueprint, Fluent $command)
    {
        return 'ALTER TABLE '.$this->wrapTable($blueprint).' ADD '.$this->getColumn($blueprint, $command->column);
    }

    /**
     * Compile a column drop command.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return list<string>
     */
    public function compileDropColumn(Blueprint $blueprint, Fluent $command)
    {
        return array_map(
            fn ($column) => 'ALTER TABLE '.$this->wrapTable($blueprint).' DROP '.$this->wrap($column),
            $command->columns
        );
    }

    /**
     * Compile a rename column command.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return string
     */
    public function compileRenameColumn(Blueprint $blueprint, Fluent $command)
    {
        return sprintf(
            'ALTER TABLE %s ALTER %s TO %s',
            $this->wrapTable($blueprint),
            $this->wrap($command->from),
            $this->wrap($command->to),
        );
    }

    /**
     * Compile a change column command.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return string
     */
    public function compileChange(Blueprint $blueprint, Fluent $command)
    {
        $column = $command->column;
        $table = $this->wrapTable($blueprint);
        $name = $this->wrap($column->name);

        $statements = [
            sprintf('ALTER TABLE %s ALTER %s TYPE %s', $table, $name, $this->getType($column)),
        ];

        if (! is_null($column->default)) {
            $statements[] = sprintf(
                'ALTER TABLE %s ALTER %s SET DEFAULT %s',
                $table,
                $name,
                $this->getDefaultValue($column->default),
            );
        }

        $statements[] = $this->compileChangeNullability($blueprint, $column);

        return $statements;
    }

    /**
     * Compile the nullability change for a column.
     *
     * Servers older than Firebird 3 have no ALTER COLUMN SET/DROP NOT NULL,
     * so the null flag is toggled directly on the system table there.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function compileChangeNullability(Blueprint $blueprint, Fluent $column)
    {
        if ($this->connection->supportsAlterColumnNullability()) {
            return sprintf(
                'ALTER TABLE %s ALTER %s %s NOT NULL',
                $this->wrapTable($blueprint),
                $this->wrap($column->name),
                $column->nullable ? 'DROP' : 'SET',
            );
        }

        return sprintf(
            'update rdb$relation_fields set rdb$null_flag = %s where rdb$relation_name = %s and rdb$field_name = %s',
            $column->nullable ? 'null' : '1',
            $this->quoteString($this->normalizeObjectName($blueprint->getTable())),
            $this->quoteString($this->normalizeObjectName($column->name)),
        );
    }

    /**
     * Compile a rename table command.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return string
     */
    public function compileRename(Blueprint $blueprint, Fluent $command)
    {
        throw new \LogicException('This database driver does not support renaming tables.');
    }

    /**
     * Compile a primary key command.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return string
     */
    public function compilePrimary(Blueprint $blueprint, Fluent $command)
    {
        $columns = $this->columnize($command->columns);

        $constraint = $command->index ? 'CONSTRAINT '.$this->wrap($this->constrainIdentifier($command->index)).' ' : '';

        return 'ALTER TABLE '.$this->wrapTable($blueprint)." ADD {$constraint}PRIMARY KEY ({$columns})";
    }

    /**
     * Compile a unique key command.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return string
     */
    public function compileUnique(Blueprint $blueprint, Fluent $command)
    {
        $table = $this->wrapTable($blueprint);

        $index = $this->wrap($this->constrainIdentifier($command->index));

        $columns = $this->columnize($command->columns);

        return "ALTER TABLE {$table} ADD CONSTRAINT {$index} UNIQUE ({$columns})";
    }

    /**
     * Compile a plain index key command.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return string
     */
    public function compileIndex(Blueprint $blueprint, Fluent $command)
    {
        $columns = $this->columnize($command->columns);

        $index = $this->wrap($this->constrainIdentifier($command->index));

        $table = $this->wrapTable($blueprint);

        return "CREATE INDEX {$index} ON {$table} ($columns)";
    }

    /**
     * Compile a unique index command (without a unique constraint).
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return string
     */
    public function compileUniqueIndex(Blueprint $blueprint, Fluent $command)
    {
        $columns = $this->columnize($command->columns);

        $index = $this->wrap($this->constrainIdentifier($command->index));

        $table = $this->wrapTable($blueprint);

        return "CREATE UNIQUE INDEX {$index} ON {$table} ($columns)";
    }

    /**
     * Compile a foreign key command.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return string
     */
    public function compileForeign(Blueprint $blueprint, Fluent $command)
    {
        $table = $this->wrapTable($blueprint);

        $on = $this->wrapTable($command->on);

        // We need to prepare several of the elements of the foreign key definition
        // before we can create the SQL, such as wrapping the tables and convert
        // an array of columns to comma-delimited strings for the SQL queries.
        $columns = $this->columnize($command->columns);

        $onColumns = $this->columnize((array) $command->references);

        $fkName = $this->wrap($this->constrainIdentifier($command->index));

        $sql = "ALTER TABLE {$table} ADD CONSTRAINT {$fkName} ";

        $sql .= "FOREIGN KEY ({$columns}) REFERENCES {$on} ({$onColumns})";

        // Once we have the basic foreign key creation statement constructed we can
        // build out the syntax for what should happen on an update or delete of
        // the affected columns, which will get something like "cascade", etc.
        if (! is_null($command->onDelete)) {
            $sql .= " ON DELETE {$command->onDelete}";
        }

        if (! is_null($command->onUpdate)) {
            $sql .= " ON UPDATE {$command->onUpdate}";
        }

        return $sql;
    }

    /**
     * Compile a drop foreign key command.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return string
     */
    public function compileDropForeign(Blueprint $blueprint, Fluent $command)
    {
        $table = $this->wrapTable($blueprint);

        return "ALTER TABLE {$table} DROP CONSTRAINT {$this->wrap($this->constrainIdentifier($command->index))}";
    }

    /**
     * Compile a drop primary key command.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return string
     */
    public function compileDropPrimary(Blueprint $blueprint, Fluent $command)
    {
        return 'ALTER TABLE '.$this->wrapTable($blueprint).' DROP CONSTRAINT '.$this->wrap($this->constrainIdentifier($command->index));
    }

    /**
     * Compile a drop unique key command.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return string
     */
    public function compileDropUnique(Blueprint $blueprint, Fluent $command)
    {
        return 'ALTER TABLE '.$this->wrapTable($blueprint).' DROP CONSTRAINT '.$this->wrap($this->constrainIdentifier($command->index));
    }

    /**
     * Compile a drop index command.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return string
     */
    public function compileDropIndex(Blueprint $blueprint, Fluent $command)
    {
        return 'DROP INDEX '.$this->wrap($this->constrainIdentifier($command->index));
    }

    /**
     * Compile a column comment command.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return string|null
     */
    public function compileComment(Blueprint $blueprint, Fluent $command)
    {
        if (! is_null($comment = $command->column->comment) || $command->column->change) {
            return sprintf(
                'comment on column %s.%s is %s',
                $this->wrapTable($blueprint),
                $this->wrap($command->column->name),
                is_null($comment) ? 'NULL' : $this->quoteCommentString($comment)
            );
        }
    }

    /**
     * Compile a table comment command.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return string
     */
    public function compileTableComment(Blueprint $blueprint, Fluent $command)
    {
        return sprintf(
            'comment on table %s is %s',
            $this->wrapTable($blueprint),
            $this->quoteCommentString($command->comment)
        );
    }

    /**
     * Quote a comment string, escaping embedded quotes.
     *
     * @param  string  $value
     * @return string
     */
    protected function quoteCommentString($value)
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    /**
     * Get the SQL for a character set column modifier.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $column
     * @return string|null
     */
    protected function modifyCharset(Blueprint $blueprint, Fluent $column)
    {
        if (! is_null($column->charset)) {
            return ' CHARACTER SET '.$column->charset;
        }
    }

    /**
     * Get the SQL for a collation column modifier.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $column
     * @return string|null
     */
    protected function modifyCollate(Blueprint $blueprint, Fluent $column)
    {
        if (! is_null($column->collation)) {
            return ' COLLATE '.$column->collation;
        }
    }

    /**
     * Get the SQL for an auto-increment column modifier.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $column
     * @return string|null
     */
    protected function modifyIncrement(Blueprint $blueprint, Fluent $column)
    {
        if (! $this->isAutoIncrementColumn($column)) {
            return;
        }

        // The primary key of an auto-increment column is emitted inline and
        // never reaches compilePrimary(), so the `primary` naming template is
        // applied here as a named inline CONSTRAINT.
        $constraint = $this->inlinePrimaryKeyConstraint($blueprint, $column);

        return $this->connection->supportsIdentityColumns()
            ? ' GENERATED BY DEFAULT AS IDENTITY'.$constraint.' PRIMARY KEY'
            : $constraint.' PRIMARY KEY';
    }

    /**
     * Build the inline CONSTRAINT clause for an auto-increment primary key.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function inlinePrimaryKeyConstraint(Blueprint $blueprint, Fluent $column)
    {
        $templates = $this->connection->getConfig('index_names') ?: [];

        if (! is_array($templates) || ! isset($templates['primary'])
            || ! method_exists($blueprint, 'resolveIndexName')) {
            return '';
        }

        $name = $blueprint->resolveIndexName('primary', [$column->name]);

        return ' CONSTRAINT '.$this->wrap($this->constrainIdentifier($name));
    }

    /**
     * Get the SQL for a nullable column modifier.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $column
     * @return string|null
     */
    protected function modifyNullable(Blueprint $blueprint, Fluent $column)
    {
        // PRIMARY KEY already implies NOT NULL and the identity clause does
        // not accept a NOT NULL constraint before the key constraint.
        if ($this->isAutoIncrementColumn($column)) {
            return '';
        }

        return $column->nullable ? '' : ' NOT NULL';
    }

    /**
     * Determine whether a column is a serial auto-increment column.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return bool
     */
    protected function isAutoIncrementColumn(Fluent $column)
    {
        return in_array($column->type, $this->serials) && $column->autoIncrement;
    }

    /**
     * Get the SQL for a default column modifier.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $column
     * @return string|null
     */
    protected function modifyDefault(Blueprint $blueprint, Fluent $column)
    {
        if (! is_null($column->default)) {
            return ' DEFAULT '.$this->getDefaultValue($column->default);
        }
    }

    /**
     * Create the column definition for a char type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeChar(Fluent $column)
    {
        return "CHAR({$column->length})";
    }

    /**
     * Create the column definition for a string type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeString(Fluent $column)
    {
        return "VARCHAR({$column->length})";
    }

    /**
     * Create the column definition for a text type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeText(Fluent $column)
    {
        return 'BLOB SUB_TYPE TEXT';
    }

    /**
     * Create the column definition for a medium text type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeMediumText(Fluent $column)
    {
        return 'BLOB SUB_TYPE TEXT';
    }

    /**
     * Create the column definition for a long text type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeLongText(Fluent $column)
    {
        return 'BLOB SUB_TYPE TEXT';
    }

    /**
     * Create the column definition for an integer type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeInteger(Fluent $column)
    {
        return 'INTEGER';
    }

    /**
     * Create the column definition for a big integer type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeBigInteger(Fluent $column)
    {
        if ((string) $this->connection->getConfig('dialect') === '1') {
            return 'NUMERIC(18, 0)';
        }

        return 'BIGINT';
    }

    /**
     * Create the column definition for a medium integer type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeMediumInteger(Fluent $column)
    {
        return 'INTEGER';
    }

    /**
     * Create the column definition for a tiny integer type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeTinyInteger(Fluent $column)
    {
        return 'SMALLINT';
    }

    /**
     * Create the column definition for a small integer type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeSmallInteger(Fluent $column)
    {
        return 'SMALLINT';
    }

    /**
     * Create the column definition for a float type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeFloat(Fluent $column)
    {
        return 'FLOAT';
    }

    /**
     * Create the column definition for a double type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeDouble(Fluent $column)
    {
        return 'DOUBLE PRECISION';
    }

    /**
     * Create the column definition for a decimal type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeDecimal(Fluent $column)
    {
        return "DECIMAL({$column->total}, {$column->places})";
    }

    /**
     * Create the column definition for a boolean type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeBoolean(Fluent $column)
    {
        return 'CHAR(1)';
    }

    /**
     * Create the column definition for an enumeration type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeEnum(Fluent $column)
    {
        $allowed = array_map(fn ($value) => $this->quoteString($value), $column->allowed);

        return 'VARCHAR(255) CHECK ('.$this->wrap($column->name).' IN ('.implode(', ', $allowed).'))';
    }

    /**
     * Create the column definition for a json type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeJson(Fluent $column)
    {
        return 'BLOB SUB_TYPE TEXT';
    }

    /**
     * Create the column definition for a jsonb type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeJsonb(Fluent $column)
    {
        return 'BLOB SUB_TYPE TEXT';
    }

    /**
     * Create the column definition for a date type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeDate(Fluent $column)
    {
        return 'DATE';
    }

    /**
     * Create the column definition for a date-time type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeDateTime(Fluent $column)
    {
        return 'TIMESTAMP';
    }

    /**
     * Create the column definition for a date-time (with time zone) type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeDateTimeTz(Fluent $column)
    {
        return $this->connection->supportsTimeZoneTypes()
            ? 'TIMESTAMP WITH TIME ZONE'
            : $this->typeDateTime($column);
    }

    /**
     * Create the column definition for a time type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeTime(Fluent $column)
    {
        if ((string) $this->connection->getConfig('dialect') === '1') {
            return 'VARCHAR(15)';
        }

        return 'TIME';
    }

    /**
     * Create the column definition for a time (with time zone) type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeTimeTz(Fluent $column)
    {
        return $this->connection->supportsTimeZoneTypes()
            ? 'TIME WITH TIME ZONE'
            : $this->typeTime($column);
    }

    /**
     * Create the column definition for a timestamp type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeTimestamp(Fluent $column)
    {
        if ($column->useCurrent) {
            return 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP';
        }

        return 'TIMESTAMP';
    }

    /**
     * Create the column definition for a timestamp (with time zone) type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeTimestampTz(Fluent $column)
    {
        if (! $this->connection->supportsTimeZoneTypes()) {
            return $this->typeTimestamp($column);
        }

        if ($column->useCurrent) {
            return 'TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP';
        }

        return 'TIMESTAMP WITH TIME ZONE';
    }

    /**
     * Create the column definition for a binary type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeBinary(Fluent $column)
    {
        return 'BLOB SUB_TYPE BINARY';
    }

    /**
     * Create the column definition for a uuid type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeUuid(Fluent $column)
    {
        return 'CHAR(36)';
    }

    /**
     * Create the column definition for an IP address type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeIpAddress(Fluent $column)
    {
        return 'VARCHAR(45)';
    }

    /**
     * Create the column definition for a MAC address type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeMacAddress(Fluent $column)
    {
        return 'VARCHAR(17)';
    }
}
