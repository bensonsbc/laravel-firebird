<?php

namespace Benson\LaravelFirebird\Tests;

use Benson\LaravelFirebird\FirebirdConnection;
use Benson\LaravelFirebird\Query\Processors\FirebirdProcessor;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class ProcessorTest extends TestCase
{
    #[Test]
    public function it_processes_columns_with_identity_and_default_metadata()
    {
        $processor = new FirebirdProcessor;

        $columns = $processor->processColumns([
            [
                'name' => 'ID',
                'field_type' => 8,
                'field_sub_type' => 0,
                'field_precision' => null,
                'field_scale' => 0,
                'field_length' => 4,
                'null_flag' => 1,
                'default_source' => null,
                'identity_type' => 1,
                'comment' => null,
            ],
            [
                'name' => 'QUANTITY',
                'field_type' => 8,
                'field_sub_type' => 0,
                'field_precision' => null,
                'field_scale' => 0,
                'field_length' => 4,
                'null_flag' => null,
                'default_source' => 'DEFAULT 0',
                'identity_type' => null,
                'comment' => 'Items in stock',
            ],
        ]);

        $this->assertTrue($columns[0]['auto_increment']);
        $this->assertNull($columns[0]['default']);

        $this->assertFalse($columns[1]['auto_increment']);
        $this->assertSame('0', $columns[1]['default']);
        $this->assertTrue($columns[1]['nullable']);
        $this->assertSame('Items in stock', $columns[1]['comment']);
    }

    #[Test]
    public function it_gets_insert_id_from_a_quoted_returning_column()
    {
        $connection = new class extends FirebirdConnection
        {
            public function __construct()
            {
                parent::__construct(
                    fn () => throw new RuntimeException('The processor tests must not connect to Firebird.'),
                    '',
                    '',
                    []
                );
            }

            public function selectFromWriteConnection($query, $bindings = [])
            {
                return [
                    [
                        'IGNORED_COLUMN' => 999,
                        'CLIENTEID' => '123',
                    ],
                ];
            }
        };

        $id = $connection->getPostProcessor()->processInsertGetId(
            $connection->query(),
            'insert into CLIENTE (NOME) values (?) returning "CLIENTEID"',
            ['Cliente Teste'],
            '"CLIENTEID"'
        );

        $this->assertSame(123, $id);
    }

    #[Test]
    public function it_processes_metadata_rows_with_uppercase_keys()
    {
        // Dialect 1 cannot quote the metadata aliases, so with the natural
        // column case Firebird returns them in uppercase.
        $processor = new FirebirdProcessor;

        $columns = $processor->processColumns([
            (object) [
                'NAME' => 'ID',
                'FIELD_TYPE' => 8,
                'FIELD_SUB_TYPE' => 0,
                'FIELD_PRECISION' => null,
                'FIELD_SCALE' => 0,
                'FIELD_LENGTH' => 4,
                'NULL_FLAG' => 1,
                'DEFAULT_SOURCE' => null,
                'IDENTITY_TYPE' => null,
                'COMMENT' => null,
            ],
        ]);

        $this->assertSame('id', $columns[0]['name']);
        $this->assertFalse($columns[0]['nullable']);

        $indexes = $processor->processIndexes([
            (object) ['NAME' => 'PK_USERS', 'COLUMNS' => 'ID', 'IS_UNIQUE' => 1, 'IS_PRIMARY' => 1],
        ]);

        $this->assertSame('pk_users', $indexes[0]['name']);
        $this->assertSame(['id'], $indexes[0]['columns']);
        $this->assertTrue($indexes[0]['unique']);
        $this->assertTrue($indexes[0]['primary']);

        $foreignKeys = $processor->processForeignKeys([
            (object) [
                'NAME' => 'FK_ORDERS_USERS',
                'COLUMNS' => 'USER_ID',
                'FOREIGN_TABLE' => 'USERS',
                'FOREIGN_COLUMNS' => 'ID',
                'ON_UPDATE' => 'RESTRICT',
                'ON_DELETE' => 'CASCADE',
            ],
        ]);

        $this->assertSame('fk_orders_users', $foreignKeys[0]['name']);
        $this->assertSame('users', $foreignKeys[0]['foreign_table']);
        $this->assertSame('cascade', $foreignKeys[0]['on_delete']);

        $tables = $processor->processTables([(object) ['NAME' => 'USERS']]);

        $this->assertSame('users', $tables[0]['name']);

        $views = $processor->processViews([(object) ['NAME' => 'ACTIVE_USERS', 'SCHEMA' => null, 'DEFINITION' => 'select 1']]);

        $this->assertSame('active_users', $views[0]['name']);
    }
}
