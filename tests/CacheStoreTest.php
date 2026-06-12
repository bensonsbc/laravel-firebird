<?php

namespace Benson\LaravelFirebird\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

class CacheStoreTest extends TestCase
{
    protected function getEnvironmentSetup($app)
    {
        parent::getEnvironmentSetup($app);

        config()->set('cache.default', 'database');
        config()->set('cache.stores.database.table', 'foo_cache');
        config()->set('cache.stores.database.lock_table', 'foo_cache_locks');
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (env('DB_DIALECT') === '1') {
            config()->set('database.connections.firebird.quote_identifiers', false);
            config()->set('database.connections.firebird.uppercase_identifiers', true);
        }

        try {
            Schema::dropIfExists('foo_cache');

            Schema::create('foo_cache', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration');
            });
        } catch (Throwable $exception) {
            $this->markTestSkipped(
                'Firebird integration database is not available for cache tests: '.$exception->getMessage()
            );
        }
    }

    protected function tearDown(): void
    {
        try {
            Schema::dropIfExists('foo_cache');
        } catch (Throwable) {
            //
        }

        parent::tearDown();
    }

    #[Test]
    public function it_stores_and_retrieves_cache_values()
    {
        Cache::put('driver-test', 'cached value', 300);

        $this->assertSame('cached value', Cache::get('driver-test'));
        $this->assertTrue(Cache::has('driver-test'));
    }

    #[Test]
    public function it_adds_values_only_when_missing()
    {
        $this->assertTrue(Cache::add('add-test', 'first', 300));
        $this->assertFalse(Cache::add('add-test', 'second', 300));

        $this->assertSame('first', Cache::get('add-test'));
    }

    #[Test]
    public function it_increments_and_decrements_values()
    {
        Cache::put('counter', 10, 300);

        Cache::increment('counter');
        Cache::increment('counter', 4);
        Cache::decrement('counter');

        $this->assertSame(14, (int) Cache::get('counter'));
    }

    #[Test]
    public function it_forgets_and_flushes_values()
    {
        Cache::put('forget-me', 'value', 300);
        Cache::put('keep-me', 'value', 300);

        Cache::forget('forget-me');

        $this->assertNull(Cache::get('forget-me'));
        $this->assertSame('value', Cache::get('keep-me'));

        Cache::flush();

        $this->assertNull(Cache::get('keep-me'));
    }

    #[Test]
    public function it_remembers_values_from_a_callback()
    {
        $value = Cache::remember('remember-test', 300, fn () => 'computed');

        $this->assertSame('computed', $value);
        $this->assertSame('computed', Cache::remember('remember-test', 300, fn () => 'recomputed'));
    }
}
