<?php

namespace Benson\LaravelFirebird\Tests;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

class UpsertTest extends TestCase
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
                'Firebird integration database is not available for upsert tests: '.$exception->getMessage()
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
    public function it_inserts_and_updates_rows_with_upsert()
    {
        DB::table('UPS_USERS')->insert($this->userAttributes([
            'ID' => 1,
            'EMAIL' => 'old@example.com',
            'NAME' => 'Old Name',
        ]));

        $affected = DB::table('UPS_USERS')->upsert([
            $this->userAttributes([
                'ID' => 1,
                'EMAIL' => 'updated@example.com',
                'NAME' => 'Updated Name',
            ]),
            $this->userAttributes([
                'ID' => 2,
                'EMAIL' => 'inserted@example.com',
                'NAME' => 'Inserted Name',
            ]),
        ], ['ID'], ['EMAIL', 'NAME', 'UPDATED_AT']);

        $this->assertGreaterThanOrEqual(1, $affected);
        $this->assertDatabaseHas('UPS_USERS', [
            'ID' => 1,
            'EMAIL' => 'updated@example.com',
            'NAME' => 'Updated Name',
        ]);
        $this->assertDatabaseHas('UPS_USERS', [
            'ID' => 2,
            'EMAIL' => 'inserted@example.com',
            'NAME' => 'Inserted Name',
        ]);
    }

    #[Test]
    public function it_inserts_rows_with_update_or_insert()
    {
        $updated = DB::table('UPS_USERS')->updateOrInsert([
            'ID' => 10,
        ], [
            'EMAIL' => 'update-or-insert-new@example.com',
            'NAME' => 'Update Insert New',
            'CREATED_AT' => now()->toDateTimeString(),
            'UPDATED_AT' => now()->toDateTimeString(),
        ]);

        $this->assertTrue($updated);
        $this->assertDatabaseHas('UPS_USERS', [
            'ID' => 10,
            'EMAIL' => 'update-or-insert-new@example.com',
            'NAME' => 'Update Insert New',
        ]);
    }

    #[Test]
    public function it_updates_rows_with_update_or_insert()
    {
        DB::table('UPS_USERS')->insert($this->userAttributes([
            'ID' => 11,
            'EMAIL' => 'before-update-or-insert@example.com',
            'NAME' => 'Before Update Insert',
        ]));

        $updated = DB::table('UPS_USERS')->updateOrInsert([
            'ID' => 11,
        ], [
            'EMAIL' => 'after-update-or-insert@example.com',
            'NAME' => 'After Update Insert',
            'UPDATED_AT' => now()->toDateTimeString(),
        ]);

        $this->assertTrue($updated);
        $this->assertDatabaseHas('UPS_USERS', [
            'ID' => 11,
            'EMAIL' => 'after-update-or-insert@example.com',
            'NAME' => 'After Update Insert',
        ]);
    }

    protected function recreateTable()
    {
        DB::select('recreate table UPS_USERS (ID integer not null primary key, NAME varchar(255) not null, EMAIL varchar(255) not null, CREATED_AT timestamp, UPDATED_AT timestamp)');
    }

    protected function dropTable()
    {
        DB::select('drop table UPS_USERS');
    }

    protected function userAttributes(array $overrides = [])
    {
        return array_merge([
            'ID' => 1,
            'NAME' => 'Upsert Test',
            'EMAIL' => 'upsert-test@example.com',
            'CREATED_AT' => now()->toDateTimeString(),
            'UPDATED_AT' => now()->toDateTimeString(),
        ], $overrides);
    }
}
