<?php

namespace Benson\LaravelFirebird\Tests;

use Benson\LaravelFirebird\FirebirdConnection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ExistsRegressionTest extends TestCase
{
    #[Test]
    public function exists_discards_only_bindings_for_the_removed_projection_and_order()
    {
        $connection = $this->getMockBuilder(FirebirdConnection::class)
            ->setConstructorArgs([fn () => throw new RuntimeException('Offline test'), '', '', ['server_version' => '5.0.3']])
            ->onlyMethods(['select'])->getMock();
        $connection->expects($this->once())->method('select')->with(
            'select first 1 1 as EXISTS_RESULT from "users" where "id" = ?', [5], true
        )->willReturn([(object) ['EXISTS_RESULT' => 1]]);

        $query = $connection->table('users')->selectRaw('? as unused', [10])
            ->where('id', 5)->orderByRaw('id + ?', [20]);
        $originalSql = $query->toSql();

        $this->assertTrue($query->exists());
        $this->assertSame([10, 5, 20], $query->getBindings());
        $this->assertSame($originalSql, $query->toSql());
    }

    #[Test]
    public function exists_preserves_projection_and_order_bindings_inside_a_union()
    {
        $connection = $this->getMockBuilder(FirebirdConnection::class)
            ->setConstructorArgs([fn () => throw new RuntimeException('Offline test'), '', '', ['server_version' => '5.0.3']])
            ->onlyMethods(['select'])->getMock();
        $query = $connection->table('users')->selectRaw('? as value', [10])->where('id', 5)
            ->unionAll($connection->table('users')->selectRaw('? as value', [30])->where('id', 6))
            ->orderByRaw('value + ?', [40]);
        $sql = $connection->getQueryGrammar()->compileExists($query);
        $connection->expects($this->once())->method('select')->with($sql, [10, 5, 30, 6, 40], true)
            ->willReturn([(object) ['EXISTS_RESULT' => 1]]);

        $this->assertSame(5, substr_count($sql, '?'));
        $this->assertTrue($query->exists());
    }

}
