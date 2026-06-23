<?php

namespace Benson\LaravelFirebird\Tests;

use Benson\LaravelFirebird\Tests\Support\MigrateDatabase;
use Benson\LaravelFirebird\Tests\Support\MigrationState;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;

class DropAllTablesTest extends TestCase
{
    use MigrateDatabase;

    #[Test]
    #[RunInSeparateProcess]
    public function it_can_drop_all_tables()
    {
        $customTable = DB::getQueryGrammar()->wrapTable('foo_drop_all_custom');
        $customId = DB::getQueryGrammar()->wrap('id');
        $customGenerator = DB::getQueryGrammar()->wrap('foo_drop_all_custom_gen');
        $customTrigger = DB::getQueryGrammar()->wrap('foo_drop_all_custom_bi');

        try {
            Schema::dropIfExists('foo_drop_all_child');
            Schema::dropIfExists('foo_drop_all_parent');
            Schema::dropIfExists('foo_drop_all_custom');

            try {
                DB::statement('drop generator '.$customGenerator);
            } catch (QueryException) {
                //
            }

            Schema::create('foo_drop_all_parent', function (Blueprint $table) {
                $table->increments('id');
            });
            Schema::create('foo_drop_all_child', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('parent_id');
                $table->foreign('parent_id', 'foo_drop_all_child_parent')
                    ->references('id')
                    ->on('foo_drop_all_parent');
            });
            DB::statement(sprintf(
                'create table %s (%s integer not null primary key)',
                $customTable,
                $customId,
            ));
            DB::statement('create generator '.$customGenerator);
            DB::statement(sprintf(
                'create trigger %s for %s active before insert position 0 as begin if (NEW.%s is null) then NEW.%s = gen_id(%s, 1); end',
                $customTrigger,
                $customTable,
                $customId,
                $customId,
                $customGenerator,
            ));

            $this->assertTrue(Schema::hasTable('foo_drop_all_parent'));
            $this->assertTrue(Schema::hasTable('foo_drop_all_child'));
            $this->assertTrue(Schema::hasTable('foo_drop_all_custom'));
            $this->assertTrue(Schema::hasTable('users'));
            $this->assertTrue(Schema::hasTable('orders'));
            $this->assertTrue($this->generatorExists('foo_drop_all_custom_gen'));

            Schema::dropAllTables();

            $this->assertFalse(Schema::hasTable('foo_drop_all_parent'));
            $this->assertFalse(Schema::hasTable('foo_drop_all_child'));
            $this->assertFalse(Schema::hasTable('foo_drop_all_custom'));
            $this->assertFalse(Schema::hasTable('users'));
            $this->assertFalse(Schema::hasTable('orders'));
            $this->assertFalse($this->generatorExists('foo_drop_all_custom_gen'));
        } finally {
            DB::disconnect();

            $this->dropTables();
            $this->dropGenerators();
            $this->createTables();
            $this->dropProcedures();
            $this->createProcedures();

            try {
                DB::statement('drop generator '.$customGenerator);
            } catch (QueryException) {
                //
            }

            MigrationState::$migrated = true;
        }
    }

    #[Test]
    #[RunInSeparateProcess]
    public function it_drops_tables_regardless_of_identifier_case()
    {
        // Identifier cases that must all be dropped:
        //  - UPPER: created without quotes (legacy), stored uppercase.
        //  - mixed: created quoted, stored with the exact mixed case.
        //  - lower: created quoted, stored lowercase.
        // getTables() lowercases every name, so dropping must use the real
        // catalog name; the mixed case is the one that only works that way.
        // Dialect 1 has no delimited identifiers, so only the uppercase case
        // exists there.
        $quotesIdentifiers = (string) DB::connection()->getConfig('dialect') !== '1';

        $tables = $quotesIdentifiers
            ? ['FOO_DROP_UPPER', 'Foo_Drop_Mixed', 'foo_drop_lower']
            : ['FOO_DROP_UPPER'];

        try {
            DB::statement('RECREATE TABLE FOO_DROP_UPPER (ID INTEGER NOT NULL PRIMARY KEY)');

            if ($quotesIdentifiers) {
                DB::statement('RECREATE TABLE "Foo_Drop_Mixed" (ID INTEGER NOT NULL PRIMARY KEY)');
                DB::statement('RECREATE TABLE "foo_drop_lower" (ID INTEGER NOT NULL PRIMARY KEY)');
            }

            // hasTable() would look up a lowercase literal, which never matches
            // upper/mixed tables, so check the catalog case-insensitively.
            foreach ($tables as $table) {
                $this->assertTrue($this->relationExists($table), "expected {$table} to exist");
            }

            Schema::dropAllTables();

            foreach ($tables as $table) {
                $this->assertFalse($this->relationExists($table), "expected {$table} to be dropped");
            }
            $this->assertFalse($this->relationExists('users'));
            $this->assertFalse($this->relationExists('orders'));
        } finally {
            DB::disconnect();

            foreach (['FOO_DROP_UPPER', '"Foo_Drop_Mixed"', '"foo_drop_lower"'] as $table) {
                try {
                    DB::statement('DROP TABLE '.$table);
                } catch (QueryException) {
                    //
                }
            }

            $this->dropTables();
            $this->dropGenerators();
            $this->createTables();
            $this->dropProcedures();
            $this->createProcedures();

            MigrationState::$migrated = true;
        }
    }

    protected function relationExists($name)
    {
        return (bool) DB::selectOne(
            'select 1 as found from rdb$relations '
            .'where upper(trim(rdb$relation_name)) = ? '
            .'and (rdb$system_flag is null or rdb$system_flag = 0)',
            [strtoupper($name)]
        );
    }

    protected function generatorExists($generator)
    {
        $name = config('database.connections.firebird.uppercase_identifiers')
            ? strtoupper($generator)
            : $generator;

        $result = DB::selectOne(
            'select exists(select 1 from rdb$generators where trim(rdb$generator_name) = ?) as generator_exists from rdb$database',
            [$name]
        );

        return (int) ($result->generator_exists ?? $result->GENERATOR_EXISTS) === 1;
    }
}
