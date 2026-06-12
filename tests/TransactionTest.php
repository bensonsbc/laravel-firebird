<?php

namespace Benson\LaravelFirebird\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class TransactionTest extends TestCase
{
    private bool $databaseAvailable = false;

    protected function setUp(): void
    {
        parent::setUp();

        if ((string) env('DB_DIALECT') === '1') {
            config()->set('database.connections.firebird.quote_identifiers', false);
            config()->set('database.connections.firebird.uppercase_identifiers', true);
        }

        try {
            $this->createTransactionTable();
            $this->databaseAvailable = true;
        } catch (QueryException $exception) {
            $this->markTestSkipped(
                'Firebird integration database is not available for transaction tests: '.$exception->getMessage()
            );
        }
    }

    #[Test]
    public function it_rolls_back_an_insert()
    {
        $connection = DB::connection();

        $connection->beginTransaction();

        try {
            DB::table('TX_USERS')->insert($this->userAttributes([
                'ID' => $id = 101,
                'EMAIL' => 'rollback-insert@example.com',
            ]));

            $this->assertDatabaseHas('TX_USERS', [
                'ID' => $id,
                'EMAIL' => 'rollback-insert@example.com',
            ]);
        } finally {
            $connection->rollBack();
        }

        $this->assertDatabaseMissing('TX_USERS', [
            'ID' => $id,
            'EMAIL' => 'rollback-insert@example.com',
        ]);
    }

    #[Test]
    public function it_rolls_back_an_update()
    {
        DB::table('TX_USERS')->insert($this->userAttributes([
            'ID' => $id = 201,
            'NAME' => 'Original Name',
            'EMAIL' => 'rollback-update@example.com',
        ]));

        $connection = DB::connection();
        $connection->beginTransaction();

        try {
            DB::table('TX_USERS')
                ->where('ID', $id)
                ->update(['NAME' => 'Changed Name']);

            $this->assertDatabaseHas('TX_USERS', [
                'ID' => $id,
                'NAME' => 'Changed Name',
            ]);
        } finally {
            $connection->rollBack();
        }

        $this->assertDatabaseHas('TX_USERS', [
            'ID' => $id,
            'NAME' => 'Original Name',
        ]);

        $this->assertDatabaseMissing('TX_USERS', [
            'ID' => $id,
            'NAME' => 'Changed Name',
        ]);
    }

    #[Test]
    public function it_rolls_back_a_delete()
    {
        DB::table('TX_USERS')->insert($this->userAttributes([
            'ID' => $id = 301,
            'EMAIL' => 'rollback-delete@example.com',
        ]));

        $connection = DB::connection();
        $connection->beginTransaction();

        try {
            DB::table('TX_USERS')
                ->where('ID', $id)
                ->delete();

            $this->assertDatabaseMissing('TX_USERS', [
                'ID' => $id,
            ]);
        } finally {
            $connection->rollBack();
        }

        $this->assertDatabaseHas('TX_USERS', [
            'ID' => $id,
            'EMAIL' => 'rollback-delete@example.com',
        ]);
    }

    #[Test]
    public function it_rolls_back_a_transaction_callback_when_an_exception_is_thrown()
    {
        try {
            DB::transaction(function () {
                DB::table('TX_USERS')->insert($this->userAttributes([
                    'ID' => 401,
                    'EMAIL' => 'callback-rollback@example.com',
                ]));

                throw new RuntimeException('Force rollback.');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Force rollback.', $exception->getMessage());
        }

        $this->assertDatabaseMissing('TX_USERS', [
            'EMAIL' => 'callback-rollback@example.com',
        ]);
    }

    #[Test]
    public function it_commits_a_transaction_callback()
    {
        DB::transaction(function () {
            DB::table('TX_USERS')->insert($this->userAttributes([
                'ID' => 451,
                'EMAIL' => 'callback-commit@example.com',
            ]));
        });

        $this->assertSame(0, DB::connection()->transactionLevel());

        $this->assertDatabaseHas('TX_USERS', [
            'ID' => 451,
            'EMAIL' => 'callback-commit@example.com',
        ]);
    }

    #[Test]
    public function it_commits_a_transaction()
    {
        $connection = DB::connection();

        $connection->beginTransaction();

        DB::table('TX_USERS')->insert($this->userAttributes([
            'ID' => $id = 501,
            'EMAIL' => 'commit@example.com',
        ]));

        $connection->commit();

        $this->assertDatabaseHas('TX_USERS', [
            'ID' => $id,
            'EMAIL' => 'commit@example.com',
        ]);
    }

    protected function tearDown(): void
    {
        if (! $this->databaseAvailable) {
            parent::tearDown();

            return;
        }

        try {
            while (DB::connection()->transactionLevel() > 0) {
                DB::connection()->rollBack();
            }
        } finally {
            $this->dropTransactionTable();

            parent::tearDown();
        }
    }

    private function createTransactionTable(): void
    {
        DB::select('recreate table TX_USERS (ID integer not null primary key, NAME varchar(255) not null, EMAIL varchar(255) not null, CITY varchar(255), STATE varchar(255), POST_CODE varchar(255), COUNTRY varchar(255), CREATED_AT timestamp, UPDATED_AT timestamp)');
    }

    private function dropTransactionTable(): void
    {
        try {
            DB::select('drop table TX_USERS');
        } catch (QueryException) {
            // The test may have been skipped before the table was created.
        }
    }

    private function userAttributes(array $overrides = []): array
    {
        return array_merge([
            'ID' => 1,
            'NAME' => 'Transaction Test User',
            'EMAIL' => 'transaction-test@example.com',
            'CITY' => 'Sao Paulo',
            'STATE' => 'SP',
            'POST_CODE' => '01000-000',
            'COUNTRY' => 'Brazil',
            'CREATED_AT' => now(),
            'UPDATED_AT' => now(),
        ], $overrides);
    }
}
