<?php

namespace Benson\LaravelFirebird\Tests\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

trait MigrateDatabase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! MigrationState::$migrated) {
            $this->dropTables();
            $this->dropGenerators();
            $this->createTables();

            $this->dropProcedures();
            $this->createProcedures();

            MigrationState::$migrated = true;
        }
    }

    protected function tearDown(): void
    {
        DB::table('orders')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    public function createTables(): void
    {
        DB::select(sprintf(
            'CREATE TABLE %s (%s INTEGER NOT NULL PRIMARY KEY, %s VARCHAR(255) NOT NULL, %s VARCHAR(255) NOT NULL, %s VARCHAR(255), %s VARCHAR(255), %s VARCHAR(255), %s VARCHAR(255), %s TIMESTAMP, %s TIMESTAMP, %s TIMESTAMP)',
            $this->wrapTable('users'),
            $this->wrapColumn('id'),
            $this->wrapColumn('name'),
            $this->wrapColumn('email'),
            $this->wrapColumn('city'),
            $this->wrapColumn('state'),
            $this->wrapColumn('post_code'),
            $this->wrapColumn('country'),
            $this->wrapColumn('created_at'),
            $this->wrapColumn('updated_at'),
            $this->wrapColumn('deleted_at'),
        ));
        DB::select('CREATE GENERATOR '.$this->wrapObject('users_id_gen'));
        DB::select(sprintf(
            'CREATE TRIGGER %s FOR %s ACTIVE BEFORE INSERT POSITION 0 AS BEGIN IF (%s IS NULL) THEN %s = GEN_ID(%s, 1); END',
            $this->wrapObject('users_bi'),
            $this->wrapTable('users'),
            $this->newColumn('id'),
            $this->newColumn('id'),
            $this->wrapObject('users_id_gen'),
        ));

        DB::select(sprintf(
            'CREATE TABLE %s (%s INTEGER NOT NULL PRIMARY KEY, %s INTEGER NOT NULL, %s VARCHAR(255) NOT NULL, %s INTEGER NOT NULL, %s INTEGER NOT NULL, %s TIMESTAMP, %s TIMESTAMP, %s TIMESTAMP)',
            $this->wrapTable('orders'),
            $this->wrapColumn('id'),
            $this->wrapColumn('user_id'),
            $this->wrapColumn('name'),
            $this->wrapColumn('price'),
            $this->wrapColumn('quantity'),
            $this->wrapColumn('created_at'),
            $this->wrapColumn('updated_at'),
            $this->wrapColumn('deleted_at'),
        ));
        DB::select('CREATE GENERATOR '.$this->wrapObject('orders_id_gen'));
        DB::select(sprintf(
            'CREATE TRIGGER %s FOR %s ACTIVE BEFORE INSERT POSITION 0 AS BEGIN IF (%s IS NULL) THEN %s = GEN_ID(%s, 1); END',
            $this->wrapObject('orders_bi'),
            $this->wrapTable('orders'),
            $this->newColumn('id'),
            $this->newColumn('id'),
            $this->wrapObject('orders_id_gen'),
        ));
        DB::select(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s)',
            $this->wrapTable('orders'),
            $this->wrapObject('orders_user_id_foreign'),
            $this->wrapColumn('user_id'),
            $this->wrapTable('users'),
            $this->wrapColumn('id'),
        ));
    }

    public function dropTables(): void
    {
        $tables = [
            'orders',
            'users',
            // Can be left behind if the test suite exits unexpectedly:
            'contacts',
            'foo',
            'foo_add_cols',
            'foo_change_col',
            'foo_change_mods',
            'foo_column_types',
            'foo_drop_all_child',
            'foo_drop_all_parent',
            'foo_drop_idx',
            'foo_drop_cols',
            'foo_enum',
            'foo_fk_child',
            'foo_fk_parent',
            'foo_increment',
            'foo_indexes',
            'foo_pk',
            'foo_renamed_table',
            'foo_rename_col',
            'foo_rename_table',
            'foo_unique',
        ];

        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function dropGenerators(): void
    {
        $generators = [
            'orders_id_gen',
            'users_id_gen',
        ];

        foreach ($generators as $generator) {
            try {
                DB::select('drop generator '.DB::getQueryGrammar()->wrap($generator));
            } catch (QueryException $e) {
                // Suppress the "not found" exception.
                if (! Str::contains($e->getMessage(), ['not found', 'not defined'])) {
                    throw $e;
                }
            }
        }
    }

    protected function wrapTable(string $table): string
    {
        return DB::getQueryGrammar()->wrapTable($table);
    }

    protected function wrapColumn(string $column): string
    {
        return DB::getQueryGrammar()->wrap($column);
    }

    protected function wrapObject(string $object): string
    {
        return DB::getQueryGrammar()->wrap($object);
    }

    protected function newColumn(string $column): string
    {
        return 'NEW.'.$this->wrapColumn($column);
    }

    public function createProcedures()
    {
        $procedure = 'math_multiply';
        $resultColumn = 'result';

        $sql = sprintf(
            'create procedure %s (a integer, b integer) returns (%s integer) as begin %s = a * b; suspend; end',
            DB::getQueryGrammar()->wrap($procedure),
            DB::getQueryGrammar()->wrap($resultColumn),
            DB::getQueryGrammar()->wrap($resultColumn),
        );

        DB::select($sql);
    }

    public function dropProcedures()
    {
        $procedures = [
            'math_multiply',
        ];

        foreach ($procedures as $procedure) {
            try {
                DB::select('drop procedure '.DB::getQueryGrammar()->wrap($procedure));
            } catch (QueryException $e) {
                // Suppress the "not found" exception.
                if (! Str::contains($e->getMessage(), 'not found')) {
                    throw $e;
                }
            }
        }
    }
}
