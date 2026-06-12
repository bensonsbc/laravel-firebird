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
}
