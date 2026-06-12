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
    public function it_gets_views()
    {
        $view = DB::getQueryGrammar()->wrapTable('foo_user_view');

        try {
            DB::statement(sprintf(
                'create view %s as select %s, %s from %s',
                $view,
                DB::getQueryGrammar()->wrap('id'),
                DB::getQueryGrammar()->wrap('name'),
                DB::getQueryGrammar()->wrapTable('users'),
            ));

            $this->assertTrue(Schema::hasView('foo_user_view'));

            $views = Schema::getViews();

            $this->assertTrue(collect($views)->contains(function ($view) {
                return $view['name'] === 'foo_user_view'
                    && $view['schema'] === null
                    && str_contains(strtolower($view['definition']), 'select');
            }));
        } finally {
            try {
                DB::statement('drop view '.$view);
            } catch (QueryException) {
                //
            }

            DB::disconnect();
        }
    }

    #[Test]
    public function it_can_drop_all_views()
    {
        $firstView = DB::getQueryGrammar()->wrapTable('foo_first_view');
        $secondView = DB::getQueryGrammar()->wrapTable('foo_second_view');

        try {
            DB::statement(sprintf(
                'create view %s as select %s from %s',
                $firstView,
                DB::getQueryGrammar()->wrap('id'),
                DB::getQueryGrammar()->wrapTable('users'),
            ));
            DB::statement(sprintf(
                'create view %s as select %s from %s',
                $secondView,
                DB::getQueryGrammar()->wrap('id'),
                DB::getQueryGrammar()->wrapTable('orders'),
            ));

            $this->assertTrue(Schema::hasView('foo_first_view'));
            $this->assertTrue(Schema::hasView('foo_second_view'));

            Schema::dropAllViews();

            $this->assertFalse(Schema::hasView('foo_first_view'));
            $this->assertFalse(Schema::hasView('foo_second_view'));
        } finally {
            foreach ([$firstView, $secondView] as $view) {
                try {
                    DB::statement('drop view '.$view);
                } catch (QueryException) {
                    //
                }
            }

            DB::disconnect();
        }
    }

    #[Test]
    public function it_can_toggle_foreign_key_constraints_as_a_noop()
    {
        $this->assertTrue(Schema::disableForeignKeyConstraints());
        $this->assertTrue(Schema::enableForeignKeyConstraints());

        $result = Schema::withoutForeignKeyConstraints(function () {
            return 'callback-result';
        });

        $this->assertSame('callback-result', $result);
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
    public function it_gets_column_types()
    {
        Schema::dropIfExists('foo_column_types');

        try {
            Schema::create('foo_column_types', function (Blueprint $table) {
                $table->integer('id');
                $table->string('name', 80)->nullable();
                $table->decimal('amount', 18, 2);
                $table->timestamp('seen_at');
                $table->text('notes');
            });

            $this->assertSame('integer', Schema::getColumnType('foo_column_types', 'id'));
            $this->assertSame('varchar', Schema::getColumnType('foo_column_types', 'name'));
            $this->assertSame(
                (string) env('DB_DIALECT') === '1' ? 'double' : 'decimal',
                Schema::getColumnType('foo_column_types', 'amount')
            );
            $this->assertSame('timestamp', Schema::getColumnType('foo_column_types', 'seen_at'));
            $this->assertSame('blob', Schema::getColumnType('foo_column_types', 'notes'));

            $this->assertSame('varchar(80)', Schema::getColumnType('foo_column_types', 'name', true));
            $this->assertSame(
                (string) env('DB_DIALECT') === '1' ? 'double' : 'decimal(18, 2)',
                Schema::getColumnType('foo_column_types', 'amount', true)
            );
        } finally {
            Schema::dropIfExists('foo_column_types');
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
    public function it_can_create_auto_incrementing_columns()
    {
        Schema::dropIfExists('foo_increment');

        try {
            Schema::create('foo_increment', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name');
            });

            $id = DB::table('foo_increment')->insertGetId([
                'name' => 'Generated id',
            ], 'id');

            $this->assertSame(1, (int) $id);
            $this->assertDatabaseHas('foo_increment', [
                'id' => 1,
                'name' => 'Generated id',
            ]);
            $this->assertTrue($this->hasPrimaryIndex('foo_increment', ['id']));
        } finally {
            Schema::dropIfExists('foo_increment');
        }
    }

    #[Test]
    public function it_reports_auto_increment_columns_in_introspection()
    {
        Schema::dropIfExists('foo_identity');

        try {
            Schema::create('foo_identity', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name');
            });

            $columns = collect(Schema::getColumns('foo_identity'));

            if (DB::connection()->supportsIdentityColumns()) {
                $this->assertTrue($columns->firstWhere('name', 'id')['auto_increment']);
            } else {
                $this->assertFalse($columns->firstWhere('name', 'id')['auto_increment']);
            }

            $this->assertFalse($columns->firstWhere('name', 'name')['auto_increment']);
        } finally {
            Schema::dropIfExists('foo_identity');
        }
    }

    #[Test]
    public function it_stores_table_and_column_comments()
    {
        Schema::dropIfExists('foo_comments');

        try {
            Schema::create('foo_comments', function (Blueprint $table) {
                $table->comment('Comments table');
                $table->integer('id');
                $table->string('name')->comment('Full name');
            });

            $columns = collect(Schema::getColumns('foo_comments'));

            $this->assertSame('Full name', $columns->firstWhere('name', 'name')['comment']);
            $this->assertNull($columns->firstWhere('name', 'id')['comment']);
        } finally {
            Schema::dropIfExists('foo_comments');
        }
    }

    #[Test]
    public function it_can_truncate_tables_and_restart_auto_increment_generators()
    {
        Schema::dropIfExists('foo_truncate');

        try {
            Schema::create('foo_truncate', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name');
            });

            DB::table('foo_truncate')->insert([
                ['name' => 'First row'],
                ['name' => 'Second row'],
            ]);

            DB::table('foo_truncate')->truncate();

            $id = DB::table('foo_truncate')->insertGetId([
                'name' => 'After truncate',
            ], 'id');

            $this->assertSame(1, DB::table('foo_truncate')->count());
            $this->assertSame(1, (int) $id);
            $this->assertDatabaseHas('foo_truncate', [
                'id' => 1,
                'name' => 'After truncate',
            ]);
        } finally {
            Schema::dropIfExists('foo_truncate');
        }
    }

    #[Test]
    public function it_can_truncate_tables_and_restart_custom_auto_increment_generators()
    {
        $table = DB::getQueryGrammar()->wrapTable('foo_custom_truncate');
        $id = DB::getQueryGrammar()->wrap('id');
        $name = DB::getQueryGrammar()->wrap('name');
        $generator = DB::getQueryGrammar()->wrap('foo_custom_generator');
        $trigger = DB::getQueryGrammar()->wrap('foo_custom_truncate_bi');

        try {
            DB::statement('drop table '.$table);
        } catch (QueryException) {
            //
        }

        try {
            DB::statement('drop generator '.$generator);
        } catch (QueryException) {
            //
        }

        try {
            DB::statement(sprintf(
                'create table %s (%s integer not null primary key, %s varchar(255) not null)',
                $table,
                $id,
                $name,
            ));
            DB::statement('create generator '.$generator);
            DB::statement(sprintf(
                'create trigger %s for %s active before insert position 0 as begin if (NEW.%s is null) then NEW.%s = gen_id(%s, 1); end',
                $trigger,
                $table,
                $id,
                $id,
                $generator,
            ));

            DB::table('foo_custom_truncate')->insert([
                ['name' => 'First custom row'],
                ['name' => 'Second custom row'],
            ]);

            DB::table('foo_custom_truncate')->truncate();

            $newId = DB::table('foo_custom_truncate')->insertGetId([
                'name' => 'After custom truncate',
            ], 'id');

            $this->assertSame(1, DB::table('foo_custom_truncate')->count());
            $this->assertSame(1, (int) $newId);
        } finally {
            try {
                DB::statement('drop table '.$table);
            } catch (QueryException) {
                //
            }

            try {
                DB::statement('drop generator '.$generator);
            } catch (QueryException) {
                //
            }
        }
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

    private function hasPrimaryIndex(string $table, array $columns): bool
    {
        return collect(Schema::getIndexes($table))->contains(function ($index) use ($columns) {
            return $index['primary'] === true && $index['columns'] === $columns;
        });
    }
}
