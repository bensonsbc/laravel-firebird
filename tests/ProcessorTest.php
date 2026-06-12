<?php

namespace Benson\LaravelFirebird\Tests;

use Benson\LaravelFirebird\FirebirdConnection;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class ProcessorTest extends TestCase
{
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
