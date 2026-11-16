<?php

namespace Benson\LaravelFirebird\Tests;

use Benson\LaravelFirebird\FirebirdConnection;
use Illuminate\Database\Query\Expression;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class UpsertRegressionTest extends TestCase
{
    #[Test]
    public function upsert_embeds_source_expressions_without_adding_bindings()
    {
        $connection = $this->getMockBuilder(FirebirdConnection::class)
            ->setConstructorArgs([fn () => throw new RuntimeException('Offline test'), '', '', ['server_version' => '5.0.3']])
            ->onlyMethods(['affectingStatement'])->getMock();
        $connection->expects($this->once())->method('affectingStatement')->with(
            $this->callback(function ($sql) {
                $this->assertStringContainsString('CURRENT_TIMESTAMP as "updated_at"', $sql);
                $this->assertStringContainsString('cast(? as bigint) as "id"', $sql);
                $this->assertSame(2, substr_count($sql, '?'));

                return true;
            }),
            [1, 2]
        )->willReturn(2);

        $this->assertSame(2, $connection->table('users')->upsert([
            ['id' => 1, 'updated_at' => new Expression('CURRENT_TIMESTAMP')],
            ['id' => 2, 'updated_at' => new Expression('CURRENT_TIMESTAMP')],
        ], ['id'], ['updated_at']));
    }

}
