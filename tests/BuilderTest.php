<?php

namespace Benson\LaravelFirebird\Tests;

use Benson\LaravelFirebird\FirebirdConnection;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class BuilderTest extends TestCase
{
    #[Test]
    public function it_reads_exists_result_from_the_firebird_alias()
    {
        $connection = new class extends FirebirdConnection
        {
            public function __construct()
            {
                parent::__construct(
                    fn () => throw new RuntimeException('The builder tests must not connect to Firebird.'),
                    '',
                    '',
                    []
                );
            }

            public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
            {
                return [
                    [
                        'EXISTS_RESULT' => 1,
                    ],
                ];
            }
        };

        $this->assertTrue($connection->table('CLIENTE')->where('CLIENTEID', 1)->exists());
    }
}
