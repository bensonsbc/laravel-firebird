<?php

namespace Benson\LaravelFirebird\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

class DataTypeTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('foo_types');

        parent::tearDown();
    }

    #[Test]
    public function it_round_trips_common_firebird_column_types()
    {
        Schema::dropIfExists('foo_types');

        Schema::create('foo_types', function (Blueprint $table) {
            $table->integer('id');
            $table->bigInteger('big_id');
            $table->smallInteger('small_count');
            $table->decimal('amount_2', 18, 2);
            $table->decimal('amount_6', 18, 6);
            $table->char('code', 3);
            $table->string('name', 50);
            $table->text('notes');
            $table->date('made_on');
            $table->time('made_at');
            $table->timestamp('seen_at');
            $table->boolean('active');
        });

        DB::table('foo_types')->insert([
            'id' => 1,
            'big_id' => 9000000001,
            'small_count' => 7,
            'amount_2' => '1234.56',
            'amount_6' => '1234.567891',
            'code' => 'ABC',
            'name' => 'Firebird Types',
            'notes' => 'Text stored in a Firebird blob subtype text column.',
            'made_on' => '2026-06-12',
            'made_at' => '13:45:30',
            'seen_at' => '2026-06-12 13:45:30',
            'active' => '1',
        ]);

        $row = DB::table('foo_types')->where('id', 1)->first();

        $this->assertSame(1, (int) $row->id);
        $this->assertSame(9000000001, (int) $row->big_id);
        $this->assertSame(7, (int) $row->small_count);
        $this->assertSame('1234.56', number_format((float) $row->amount_2, 2, '.', ''));
        $this->assertSame('1234.567891', number_format((float) $row->amount_6, 6, '.', ''));
        $this->assertSame('ABC', rtrim($row->code));
        $this->assertSame('Firebird Types', $row->name);
        $this->assertSame('Text stored in a Firebird blob subtype text column.', $row->notes);
        $this->assertStringStartsWith('2026-06-12', (string) $row->made_on);
        $this->assertStringContainsString('13:45:30', (string) $row->made_at);
        $this->assertStringContainsString('2026-06-12', (string) $row->seen_at);
        $this->assertStringContainsString('13:45:30', (string) $row->seen_at);
        $this->assertSame('1', rtrim((string) $row->active));
    }
}
