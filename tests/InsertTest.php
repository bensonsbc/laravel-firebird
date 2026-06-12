<?php

namespace Benson\LaravelFirebird\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

class InsertTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (env('DB_DIALECT') === '1') {
            config()->set('database.connections.firebird.quote_identifiers', false);
            config()->set('database.connections.firebird.uppercase_identifiers', true);
        }

        try {
            $this->recreateTable();
        } catch (Throwable $exception) {
            $this->markTestSkipped(
                'Firebird integration database is not available for insert tests: '.$exception->getMessage()
            );
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->dropTable();
        } catch (Throwable) {
            //
        }

        parent::tearDown();
    }

    #[Test]
    public function it_inserts_multiple_rows()
    {
        $inserted = DB::table('MR_USERS')->insert([
            $this->userAttributes([
                'ID' => 1,
                'EMAIL' => 'anna@example.com',
            ]),
            $this->userAttributes([
                'ID' => 2,
                'EMAIL' => 'bruno@example.com',
            ]),
        ]);

        $this->assertTrue($inserted);
        $this->assertDatabaseHas('MR_USERS', [
            'ID' => 1,
            'EMAIL' => 'anna@example.com',
        ]);
        $this->assertDatabaseHas('MR_USERS', [
            'ID' => 2,
            'EMAIL' => 'bruno@example.com',
        ]);
    }

    #[Test]
    public function it_rolls_back_a_failed_multi_row_insert()
    {
        try {
            DB::table('MR_USERS')->insert([
                $this->userAttributes([
                    'ID' => 10,
                    'EMAIL' => 'first@example.com',
                ]),
                $this->userAttributes([
                    'ID' => 10,
                    'EMAIL' => 'duplicate@example.com',
                ]),
            ]);

            $this->fail('Duplicate key did not fail.');
        } catch (QueryException) {
            //
        }

        $this->assertDatabaseMissing('MR_USERS', [
            'ID' => 10,
            'EMAIL' => 'first@example.com',
        ]);
    }

    #[Test]
    public function it_ignores_duplicates_in_multi_row_insert_or_ignore()
    {
        DB::table('MR_USERS')->insert($this->userAttributes([
            'ID' => 20,
            'EMAIL' => 'existing@example.com',
        ]));

        $affected = DB::table('MR_USERS')->insertOrIgnore([
            $this->userAttributes([
                'ID' => 20,
                'EMAIL' => 'duplicate@example.com',
            ]),
            $this->userAttributes([
                'ID' => 21,
                'EMAIL' => 'new@example.com',
            ]),
        ]);

        $this->assertSame(1, $affected);
        $this->assertDatabaseHas('MR_USERS', [
            'ID' => 20,
            'EMAIL' => 'existing@example.com',
        ]);
        $this->assertDatabaseHas('MR_USERS', [
            'ID' => 21,
            'EMAIL' => 'new@example.com',
        ]);
        $this->assertDatabaseMissing('MR_USERS', [
            'ID' => 20,
            'EMAIL' => 'duplicate@example.com',
        ]);
    }

    protected function recreateTable()
    {
        DB::select('recreate table MR_USERS (ID integer not null primary key, NAME varchar(255) not null, EMAIL varchar(255) not null, CREATED_AT timestamp, UPDATED_AT timestamp)');
    }

    protected function dropTable()
    {
        DB::select('drop table MR_USERS');
    }

    protected function userAttributes(array $overrides = [])
    {
        return array_merge([
            'ID' => 1,
            'NAME' => 'Insert Test',
            'EMAIL' => 'insert-test@example.com',
            'CREATED_AT' => now()->toDateTimeString(),
            'UPDATED_AT' => now()->toDateTimeString(),
        ], $overrides);
    }
}
