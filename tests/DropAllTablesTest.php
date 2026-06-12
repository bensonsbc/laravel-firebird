<?php

namespace Benson\LaravelFirebird\Tests;

use Benson\LaravelFirebird\Tests\Support\MigrateDatabase;
use Benson\LaravelFirebird\Tests\Support\MigrationState;
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
        try {
            Schema::dropIfExists('foo_drop_all_child');
            Schema::dropIfExists('foo_drop_all_parent');

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

            $this->assertTrue(Schema::hasTable('foo_drop_all_parent'));
            $this->assertTrue(Schema::hasTable('foo_drop_all_child'));
            $this->assertTrue(Schema::hasTable('users'));
            $this->assertTrue(Schema::hasTable('orders'));

            Schema::dropAllTables();

            $this->assertFalse(Schema::hasTable('foo_drop_all_parent'));
            $this->assertFalse(Schema::hasTable('foo_drop_all_child'));
            $this->assertFalse(Schema::hasTable('users'));
            $this->assertFalse(Schema::hasTable('orders'));
        } finally {
            DB::disconnect();

            $this->dropTables();
            $this->dropGenerators();
            $this->createTables();
            $this->dropProcedures();
            $this->createProcedures();

            MigrationState::$migrated = true;
        }
    }
}
