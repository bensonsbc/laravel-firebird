<?php

namespace Benson\LaravelFirebird\Tests;

use Illuminate\Database\QueryException;
use PDOException;
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

    #[Test]
    public function it_runs_after_commit_callbacks_that_write_to_the_database()
    {
        DB::transaction(function () {
            DB::table('TX_USERS')->insert($this->userAttributes(['ID' => 601, 'EMAIL' => 'after-commit-1@example.com']));
            DB::afterCommit(fn () => DB::table('TX_USERS')->insert($this->userAttributes(['ID' => 602, 'EMAIL' => 'after-commit-2@example.com'])));
        });

        $this->assertSame(0, DB::connection()->transactionLevel());
        $this->assertSame([601, 602], $this->idsGravados());
    }

    #[Test]
    public function it_runs_after_commit_callbacks_of_a_nested_transaction_after_the_outer_commit()
    {
        DB::transaction(function () {
            DB::transaction(function () {
                DB::afterCommit(fn () => DB::table('TX_USERS')->insert($this->userAttributes(['ID' => 612, 'EMAIL' => 'nested-after-commit@example.com'])));
            });
            $this->assertSame([], $this->idsGravados()); // ainda não: espera o commit da transação de fora
            DB::table('TX_USERS')->insert($this->userAttributes(['ID' => 611, 'EMAIL' => 'outer@example.com']));
        });

        $this->assertSame([611, 612], $this->idsGravados());
    }

    #[Test]
    public function it_discards_after_commit_callbacks_when_the_transaction_rolls_back()
    {
        try {
            DB::transaction(function () {
                DB::afterCommit(fn () => DB::table('TX_USERS')->insert($this->userAttributes(['ID' => 622, 'EMAIL' => 'discarded@example.com'])));
                throw new RuntimeException('Force rollback.');
            });
        } catch (RuntimeException) {
        }
        DB::table('TX_USERS')->insert($this->userAttributes(['ID' => 623, 'EMAIL' => 'standalone@example.com']));

        $this->assertSame([623], $this->idsGravados());
    }

    #[Test]
    public function it_rolls_back_only_the_nested_transaction()
    {
        DB::transaction(function () {
            DB::table('TX_USERS')->insert($this->userAttributes(['ID' => 631, 'EMAIL' => 'kept@example.com']));
            try {
                DB::transaction(function () {
                    DB::table('TX_USERS')->insert($this->userAttributes(['ID' => 632, 'EMAIL' => 'savepoint@example.com']));
                    throw new RuntimeException('Force savepoint rollback.');
                });
            } catch (RuntimeException) {
            }
        });

        $this->assertSame([631], $this->idsGravados());
    }

    #[Test]
    public function it_rolls_back_the_nested_work_when_the_outer_transaction_fails()
    {
        try {
            DB::transaction(function () {
                DB::transaction(fn () => DB::table('TX_USERS')->insert($this->userAttributes(['ID' => 641, 'EMAIL' => 'nested-committed@example.com'])));
                throw new RuntimeException('Force outer rollback.');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame([], $this->idsGravados());
        $this->assertSame(0, DB::connection()->transactionLevel());
    }

    #[Test]
    public function it_keeps_rolling_back_after_a_commit_and_standalone_statements_persist()
    {
        DB::transaction(fn () => DB::table('TX_USERS')->insert($this->userAttributes(['ID' => 651, 'EMAIL' => 'first@example.com'])));
        DB::table('TX_USERS')->insert($this->userAttributes(['ID' => 652, 'EMAIL' => 'autocommit@example.com']));
        try {
            DB::transaction(function () {
                DB::table('TX_USERS')->insert($this->userAttributes(['ID' => 653, 'EMAIL' => 'must-roll-back@example.com']));
                throw new RuntimeException('Force rollback.');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame([651, 652], $this->idsGravados());
    }

    #[Test]
    public function it_retries_the_transaction_after_a_concurrency_conflict()
    {
        $tentativas = 0;
        DB::transaction(function () use (&$tentativas) {
            $tentativas++;
            DB::table('TX_USERS')->insert($this->userAttributes(['ID' => 660 + $tentativas, 'EMAIL' => "attempt-{$tentativas}@example.com"]));
            if ($tentativas === 1) {
                // o conflito que o Firebird devolve (-913, SQLSTATE 40001): o Laravel repete a transação
                throw new class('update conflicts with concurrent update') extends PDOException
                {
                    protected $code = '40001';
                };
            }
        }, 3);

        $this->assertSame(2, $tentativas);
        $this->assertSame([662], $this->idsGravados()); // a 1ª tentativa foi desfeita
    }

    /** IDs gravados de verdade, vistos por OUTRA conexão (a mesma conexão enxerga o que ainda não foi gravado). */
    private function idsGravados(): array
    {
        config()->set('database.connections.firebird_outra', config('database.connections.firebird'));
        DB::purge('firebird_outra');

        return array_map('intval', DB::connection('firebird_outra')->table('TX_USERS')->orderBy('ID')->pluck('ID')->all());
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
            DB::purge('firebird_outra');
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
