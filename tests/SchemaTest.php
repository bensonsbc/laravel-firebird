<?php

namespace Benson\LaravelFirebird\Tests;

use Benson\LaravelFirebird\Tests\Support\MigrateDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

class SchemaTest extends TestCase
{
    use MigrateDatabase;

    #[Test]
    public function it_has_table()
    {
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertFalse(Schema::hasTable('foo'));
    }

    #[Test]
    public function it_lists_tables()
    {
        $tables = Schema::getTableListing();

        $this->assertCount(2, $tables);
        $this->assertContains('users', $tables);
        $this->assertContains('orders', $tables);
    }

    #[Test]
    public function it_gets_tables()
    {
        $tables = Schema::getTables();

        $this->assertIsArray($tables);
        $this->assertCount(2, $tables);

        foreach ($tables as $table) {
            $this->assertArrayHasKey('name', $table);
            $this->assertArrayHasKey('schema', $table);
            $this->assertArrayHasKey('size', $table);
            $this->assertArrayHasKey('comment', $table);
            $this->assertArrayHasKey('collation', $table);
            $this->assertArrayHasKey('engine', $table);

            $this->assertIsString($table['name']);
        }

        $this->assertContains('users', array_column($tables, 'name'));
        $this->assertContains('orders', array_column($tables, 'name'));
    }

    #[Test]
    public function it_has_column()
    {
        $this->assertTrue(Schema::hasColumn('users', 'id'));
        $this->assertFalse(Schema::hasColumn('users', 'foo'));
    }

    #[Test]
    public function it_has_columns()
    {
        $this->assertTrue(Schema::hasColumns('users', ['id', 'country']));
        $this->assertFalse(Schema::hasColumns('users', ['id', 'foo']));
    }

    #[Test]
    public function it_lists_columns()
    {
        $columns = Schema::getColumnListing('users');

        $this->assertCount(10, $columns);

        $expectedColumns = [
            'id', 'name', 'email', 'city', 'state', 'post_code', 'country',
            'created_at', 'updated_at', 'deleted_at',
        ];

        foreach ($expectedColumns as $expectedColumn) {
            $this->assertContains($expectedColumn, $columns);
        }
    }

    #[Test]
    public function it_can_create_a_table()
    {
        Schema::dropIfExists('foo');

        $this->assertFalse(Schema::hasTable('foo'));

        Schema::create('foo', function (Blueprint $table) {
            $table->string('bar');
        });

        $this->assertTrue(Schema::hasTable('foo'));

        // Clean up...
        Schema::drop('foo');
    }

    #[Test]
    public function it_can_add_columns_to_a_table()
    {
        Schema::dropIfExists('foo_add_cols');

        Schema::create('foo_add_cols', function (Blueprint $table) {
            $table->integer('id');
        });

        Schema::table('foo_add_cols', function (Blueprint $table) {
            $table->string('name')->nullable();
            $table->integer('quantity')->default(0);
        });

        $this->assertTrue(Schema::hasColumns('foo_add_cols', ['id', 'name', 'quantity']));

        Schema::drop('foo_add_cols');
    }

    #[Test]
    public function it_can_drop_columns_from_a_table()
    {
        Schema::dropIfExists('foo_drop_cols');

        try {
            Schema::create('foo_drop_cols', function (Blueprint $table) {
                $table->integer('id');
                $table->string('name');
                $table->string('notes')->nullable();
            });

            $this->assertTrue(Schema::hasColumns('foo_drop_cols', ['id', 'name', 'notes']));

            Schema::table('foo_drop_cols', function (Blueprint $table) {
                $table->dropColumn(['name', 'notes']);
            });

            $this->assertTrue(Schema::hasColumn('foo_drop_cols', 'id'));
            $this->assertFalse(Schema::hasColumn('foo_drop_cols', 'name'));
            $this->assertFalse(Schema::hasColumn('foo_drop_cols', 'notes'));
        } finally {
            Schema::dropIfExists('foo_drop_cols');
        }
    }

    #[Test]
    public function it_can_rename_columns()
    {
        Schema::dropIfExists('foo_rename_col');

        try {
            Schema::create('foo_rename_col', function (Blueprint $table) {
                $table->integer('id');
                $table->string('old_name');
            });

            Schema::table('foo_rename_col', function (Blueprint $table) {
                $table->renameColumn('old_name', 'new_name');
            });

            $this->assertFalse(Schema::hasColumn('foo_rename_col', 'old_name'));
            $this->assertTrue(Schema::hasColumn('foo_rename_col', 'new_name'));

            DB::table('foo_rename_col')->insert([
                'id' => 1,
                'new_name' => 'Renamed',
            ]);

            $this->assertDatabaseHas('foo_rename_col', [
                'id' => 1,
                'new_name' => 'Renamed',
            ]);
        } finally {
            Schema::dropIfExists('foo_rename_col');
        }
    }

    #[Test]
    public function it_can_change_column_types()
    {
        Schema::dropIfExists('foo_change_col');

        try {
            Schema::create('foo_change_col', function (Blueprint $table) {
                $table->integer('id');
                $table->string('name', 10);
            });

            Schema::table('foo_change_col', function (Blueprint $table) {
                $table->string('name', 50)->change();
            });

            DB::table('foo_change_col')->insert([
                'id' => 1,
                'name' => 'A longer changed value',
            ]);

            $this->assertDatabaseHas('foo_change_col', [
                'id' => 1,
                'name' => 'A longer changed value',
            ]);
        } finally {
            Schema::dropIfExists('foo_change_col');
        }
    }

    #[Test]
    public function it_can_change_column_nullable_and_default_modifiers()
    {
        Schema::dropIfExists('foo_change_mods');

        try {
            Schema::create('foo_change_mods', function (Blueprint $table) {
                $table->integer('id');
                $table->string('name');
                $table->integer('quantity')->default(1);
            });

            Schema::table('foo_change_mods', function (Blueprint $table) {
                $table->string('name')->nullable()->change();
                $table->integer('quantity')->default(5)->change();
            });

            DB::statement(sprintf(
                'insert into %s (%s, %s) values (1, null)',
                DB::getQueryGrammar()->wrapTable('foo_change_mods'),
                DB::getQueryGrammar()->wrap('id'),
                DB::getQueryGrammar()->wrap('name'),
            ));

            $this->assertDatabaseHas('foo_change_mods', [
                'id' => 1,
                'name' => null,
                'quantity' => 5,
            ]);
        } finally {
            Schema::dropIfExists('foo_change_mods');
        }
    }

    #[Test]
    public function it_throws_an_exception_for_renaming_tables()
    {
        Schema::dropIfExists('foo_rename_table');

        try {
            Schema::create('foo_rename_table', function (Blueprint $table) {
                $table->integer('id');
                $table->string('name');
            });

            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('This database driver does not support renaming tables.');

            Schema::rename('foo_rename_table', 'foo_renamed_table');
        } finally {
            Schema::dropIfExists('foo_rename_table');
        }
    }

    #[Test]
    public function it_can_create_indexes()
    {
        Schema::dropIfExists('foo_indexes');

        Schema::create('foo_indexes', function (Blueprint $table) {
            $table->integer('id');
            $table->string('email');
            $table->index('email', 'foo_indexes_email_idx');
        });

        $indexes = Schema::getIndexes('foo_indexes');

        $this->assertTrue(collect($indexes)->contains(
            fn ($index) => strtolower($index['name']) === 'foo_indexes_email_idx'
        ));

        Schema::drop('foo_indexes');
    }

    #[Test]
    public function it_can_drop_indexes()
    {
        Schema::dropIfExists('foo_drop_idx');

        Schema::create('foo_drop_idx', function (Blueprint $table) {
            $table->integer('id');
            $table->string('email');
            $table->index('email', 'foo_drop_idx_email');
        });

        $this->assertTrue($this->hasIndex('foo_drop_idx', 'foo_drop_idx_email'));

        Schema::table('foo_drop_idx', function (Blueprint $table) {
            $table->dropIndex('foo_drop_idx_email');
        });

        $this->assertFalse($this->hasIndex('foo_drop_idx', 'foo_drop_idx_email'));

        Schema::drop('foo_drop_idx');
    }

    #[Test]
    public function it_can_create_and_drop_unique_constraints()
    {
        Schema::dropIfExists('foo_unique');

        Schema::create('foo_unique', function (Blueprint $table) {
            $table->integer('id');
            $table->string('email');
            $table->unique('email', 'foo_unique_email');
        });

        $this->assertTrue($this->hasIndex('foo_unique', 'foo_unique_email', unique: true));

        Schema::table('foo_unique', function (Blueprint $table) {
            $table->dropUnique('foo_unique_email');
        });

        $this->assertFalse($this->hasIndex('foo_unique', 'foo_unique_email'));

        Schema::drop('foo_unique');
    }

    #[Test]
    public function it_can_create_and_drop_named_primary_keys()
    {
        Schema::dropIfExists('foo_pk');

        Schema::create('foo_pk', function (Blueprint $table) {
            $table->integer('id');
            $table->primary('id', 'foo_pk_id');
        });

        $this->assertTrue($this->hasIndex('foo_pk', 'foo_pk_id', primary: true));

        Schema::table('foo_pk', function (Blueprint $table) {
            $table->dropPrimary('foo_pk_id');
        });

        $this->assertFalse($this->hasIndex('foo_pk', 'foo_pk_id'));

        Schema::drop('foo_pk');
    }

    #[Test]
    public function it_can_create_list_and_drop_foreign_keys()
    {
        Schema::dropIfExists('foo_fk_child');
        Schema::dropIfExists('foo_fk_parent');

        Schema::create('foo_fk_parent', function (Blueprint $table) {
            $table->integer('id');
            $table->primary('id', 'foo_fk_parent_pk');
        });

        Schema::create('foo_fk_child', function (Blueprint $table) {
            $table->integer('id');
            $table->integer('parent_id');
            $table->foreign('parent_id', 'foo_fk_child_parent')
                ->references('id')
                ->on('foo_fk_parent')
                ->cascadeOnDelete();
        });

        $foreignKeys = Schema::getForeignKeys('foo_fk_child');

        $this->assertTrue(collect($foreignKeys)->contains(function ($foreignKey) {
            return strtolower($foreignKey['name']) === 'foo_fk_child_parent'
                && $foreignKey['columns'] === ['parent_id']
                && $foreignKey['foreign_table'] === 'foo_fk_parent'
                && $foreignKey['foreign_columns'] === ['id']
                && $foreignKey['on_delete'] === 'cascade';
        }));

        Schema::table('foo_fk_child', function (Blueprint $table) {
            $table->dropForeign('foo_fk_child_parent');
        });

        $this->assertFalse(collect(Schema::getForeignKeys('foo_fk_child'))->contains(
            fn ($foreignKey) => strtolower($foreignKey['name']) === 'foo_fk_child_parent'
        ));

        Schema::drop('foo_fk_child');
        Schema::drop('foo_fk_parent');
    }

    #[Test]
    public function it_can_create_enum_columns_with_check_constraints()
    {
        Schema::dropIfExists('foo_enum');

        try {
            Schema::create('foo_enum', function (Blueprint $table) {
                $table->integer('id');
                $table->enum('status', ['new', 'done']);
            });

            DB::table('foo_enum')->insert([
                'id' => 1,
                'status' => 'new',
            ]);

            $this->assertDatabaseHas('foo_enum', [
                'id' => 1,
                'status' => 'new',
            ]);

            try {
                DB::table('foo_enum')->insert([
                    'id' => 2,
                    'status' => 'invalid',
                ]);

                $this->fail('The enum check constraint should reject invalid values.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('check', strtolower($exception->getMessage()));
            }
        } finally {
            Schema::dropIfExists('foo_enum');
        }
    }

    #[Test]
    public function it_throws_an_exception_for_creating_temporary_tables()
    {
        Schema::dropIfExists('foo');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('This database driver does not support temporary tables.');

        $this->assertFalse(Schema::hasTable('foo'));

        Schema::create('foo', function (Blueprint $table) {
            $table->temporary();

            $table->string('bar');
        });

        $this->assertFalse(Schema::hasTable('foo'));
    }

    #[Test]
    public function it_can_drop_table()
    {
        DB::select(sprintf(
            'RECREATE TABLE %s (%s INTEGER NOT NULL)',
            DB::getQueryGrammar()->wrapTable('foo'),
            DB::getQueryGrammar()->wrap('id'),
        ));

        $this->assertTrue(Schema::hasTable('foo'));

        Schema::drop('foo');

        $this->assertFalse(Schema::hasTable('foo'));
    }

    #[Test]
    public function it_can_drop_table_if_exists()
    {
        DB::select(sprintf(
            'RECREATE TABLE %s (%s INTEGER NOT NULL)',
            DB::getQueryGrammar()->wrapTable('foo'),
            DB::getQueryGrammar()->wrap('id'),
        ));

        $this->assertTrue(Schema::hasTable('foo'));

        Schema::dropIfExists('foo');

        $this->assertFalse(Schema::hasTable('foo'));

        // Run again to check exists = false.

        Schema::dropIfExists('foo');

        $this->assertFalse(Schema::hasTable('foo'));
    }

    private function hasIndex(string $table, string $name, ?bool $unique = null, ?bool $primary = null): bool
    {
        return collect(Schema::getIndexes($table))->contains(function ($index) use ($name, $unique, $primary) {
            return strtolower($index['name']) === strtolower($name)
                && ($unique === null || $index['unique'] === $unique)
                && ($primary === null || $index['primary'] === $primary);
        });
    }
}
