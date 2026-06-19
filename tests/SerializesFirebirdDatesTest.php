<?php

namespace Benson\LaravelFirebird\Tests;

use Benson\LaravelFirebird\Eloquent\Concerns\SerializesFirebirdDates;
use Benson\LaravelFirebird\Eloquent\Model as FirebirdModel;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * The trait formats values during attribute mutation, so these tests run on an
 * in-memory SQLite connection and never touch Firebird.
 */
class SerializesFirebirdDatesTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
    }

    protected function tearDown(): void
    {
        Model::unsetConnectionResolver();

        parent::tearDown();
    }

    #[Test]
    public function it_stores_date_casts_without_the_time_component()
    {
        $model = $this->newModel();

        $model->birthday = Carbon::parse('2026-06-18 13:45:00');

        $this->assertSame('2026-06-18', $model->getAttributes()['birthday']);
    }

    #[Test]
    public function it_keeps_the_time_for_datetime_casts()
    {
        $model = $this->newModel();

        $model->published_at = Carbon::parse('2026-06-18 13:45:00');

        $this->assertSame('2026-06-18 13:45:00', $model->getAttributes()['published_at']);
    }

    #[Test]
    public function it_accepts_plain_date_strings()
    {
        $model = $this->newModel();

        $model->birthday = '2026-06-18';

        $this->assertSame('2026-06-18', $model->getAttributes()['birthday']);
    }

    #[Test]
    public function it_leaves_null_dates_untouched()
    {
        $model = $this->newModel();

        $model->birthday = null;

        $this->assertNull($model->getAttributes()['birthday']);
    }

    #[Test]
    public function it_still_reads_dates_back_as_carbon_instances()
    {
        $model = $this->newModel();

        $model->birthday = '2026-06-18';

        $this->assertInstanceOf(Carbon::class, $model->birthday);
        $this->assertSame('2026-06-18', $model->birthday->toDateString());
    }

    #[Test]
    public function the_base_model_applies_the_trait()
    {
        $model = new class extends FirebirdModel
        {
            protected $table = 'events';

            public $timestamps = false;

            protected $casts = ['birthday' => 'date'];
        };

        $model->birthday = Carbon::parse('2026-06-18 13:45:00');

        $this->assertSame('2026-06-18', $model->getAttributes()['birthday']);
    }

    protected function newModel(): Model
    {
        return new class extends Model
        {
            use SerializesFirebirdDates;

            protected $table = 'events';

            public $timestamps = false;

            protected $casts = [
                'birthday' => 'date',
                'published_at' => 'datetime',
            ];
        };
    }
}
