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
    public function it_drops_legacy_uppercase_tables_created_without_quotes()
    {
        try {
            // Created without quotes, so Firebird stores the name in uppercase.
            // getTables() lowercases it; dropping must still use the real name.
            DB::statement('RECREATE TABLE FOO_LEGACY_UPPER (ID INTEGER NOT NULL PRIMARY KEY)');

            $this->assertTrue(Schema::hasTable('foo_legacy_upper'));

            Schema::dropAllTables();

            $this->assertFalse(Schema::hasTable('foo_legacy_upper'));
            $this->assertFalse(Schema::hasTable('users'));
            $this->assertFalse(Schema::hasTable('orders'));
        } finally {
            DB::disconnect();

            try {
                DB::statement('DROP TABLE FOO_LEGACY_UPPER');
            } catch (QueryException) {
                //
            }

            $this->dropTables();
            $this->dropGenerators();
            $this->createTables();
            $this->dropProcedures();
            $this->createProcedures();

            MigrationState::$migrated = true;
        }
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
