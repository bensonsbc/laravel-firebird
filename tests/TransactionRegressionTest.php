<?php

namespace Benson\LaravelFirebird\Tests;

use Benson\LaravelFirebird\FirebirdConnection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Events\Dispatcher;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TransactionRegressionTest extends TestCase
{
    #[Test]
    public function manual_commit_restores_autocommit_before_callbacks_and_events()
    {
        $autocommit = true;
        $pdo = $this->transactionPdo($autocommit);
        $connection = new FirebirdConnection($pdo, '', '', ['name' => 'regression']);
        $connection->setTransactionManager(new DatabaseTransactionsManager);
        $events = new Dispatcher;
        $connection->setEventDispatcher($events);
        $observed = [];

        $events->listen(TransactionCommitted::class, function () use (&$observed, &$autocommit) {
            $observed['event'] = $autocommit;
        });

        $connection->beginTransaction();
        $connection->afterCommit(function () use (&$observed, &$autocommit) {
            $observed['callback'] = $autocommit;
        });
        $connection->commit();

        $this->assertSame(['callback' => true, 'event' => true], $observed);
        $this->assertSame(0, $connection->transactionLevel());
    }

    #[Test]
    public function an_after_commit_callback_can_start_another_transaction()
    {
        $autocommit = true;
        $pdo = $this->transactionPdo($autocommit, 2);
        $connection = new FirebirdConnection($pdo, '', '', ['name' => 'regression']);
        $connection->setTransactionManager(new DatabaseTransactionsManager);

        $connection->beginTransaction();
        $connection->afterCommit(fn () => $connection->beginTransaction());
        $connection->commit();

        $this->assertSame(1, $connection->transactionLevel());
        $this->assertFalse($autocommit);
    }

    #[Test]
    public function a_nested_commit_keeps_autocommit_off_until_the_outer_commit()
    {
        $autocommit = true;
        $pdo = $this->transactionPdo($autocommit);
        $pdo->expects($this->once())->method('exec')->with('SAVEPOINT trans2')->willReturn(0);
        $connection = new FirebirdConnection($pdo);

        $connection->beginTransaction();
        $connection->beginTransaction();
        $connection->commit();
        $this->assertFalse($autocommit);
        $this->assertSame(1, $connection->transactionLevel());

        $connection->commit();
        $this->assertTrue($autocommit);
    }

    private function transactionPdo(bool &$autocommit, int $begins = 1): PDO
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn(false);
        $pdo->expects($this->exactly($begins))->method('beginTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('commit')->willReturn(true);
        $pdo->method('setAttribute')->willReturnCallback(function ($attribute, $value) use (&$autocommit) {
            $this->assertSame(PDO::ATTR_AUTOCOMMIT, $attribute);
            $autocommit = $value;

            return true;
        });

        return $pdo;
    }
}
