<?php

namespace Benson\LaravelFirebird\Tests;

use Benson\LaravelFirebird\FirebirdConnector;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

class ConnectorTest extends TestCase
{
    #[Test]
    public function it_builds_a_local_database_dsn()
    {
        $dsn = $this->dsn([
            'database' => 'C:\Data\db\sofia.fdb',
        ]);

        $this->assertSame('firebird:dbname=C:\Data\db\sofia.fdb;charset=UTF8', $dsn);
    }

    #[Test]
    public function it_builds_a_remote_database_dsn_with_optional_segments()
    {
        $dsn = $this->dsn([
            'host' => '127.0.0.1',
            'port' => '3050',
            'database' => '/var/lib/firebird/data/database.fdb',
            'charset' => 'WIN1252',
            'role' => 'RDB$ADMIN',
            'dialect' => '3',
        ]);

        $this->assertSame(
            'firebird:dbname=127.0.0.1/3050:/var/lib/firebird/data/database.fdb;charset=WIN1252;role=RDB$ADMIN;dialect=3',
            $dsn
        );
    }

    #[Test]
    public function it_requires_a_database_path()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Firebird connection requires a database path.');

        $this->dsn([]);
    }

    #[Test]
    public function it_keeps_natural_result_column_case_by_default()
    {
        $this->assertSame(PDO::CASE_NATURAL, $this->columnCaseFor([]));
    }

    #[Test]
    public function it_applies_the_configured_result_column_case()
    {
        $this->assertSame(PDO::CASE_NATURAL, $this->columnCaseFor(['column_case' => 'natural']));
        $this->assertSame(PDO::CASE_LOWER, $this->columnCaseFor(['column_case' => 'lower']));
        $this->assertSame(PDO::CASE_UPPER, $this->columnCaseFor(['column_case' => 'upper']));
    }

    #[Test]
    public function it_falls_back_to_natural_case_for_unknown_values()
    {
        $this->assertSame(PDO::CASE_NATURAL, $this->columnCaseFor(['column_case' => 'unknown']));
    }

    private function columnCaseFor(array $config): int
    {
        $connection = new class
        {
            public array $attributes = [];

            public function setAttribute($attribute, $value)
            {
                $this->attributes[$attribute] = $value;
            }
        };

        $connector = new class($connection) extends FirebirdConnector
        {
            public function __construct(private object $connection)
            {
            }

            public function createConnection($dsn, array $config, array $options)
            {
                return $this->connection;
            }
        };

        $connector->connect(['database' => 'database.fdb'] + $config);

        return $connection->attributes[PDO::ATTR_CASE];
    }

    private function dsn(array $config): string
    {
        $method = new ReflectionMethod(FirebirdConnector::class, 'getDsn');
        $method->setAccessible(true);

        return $method->invoke(new FirebirdConnector(), $config);
    }
}
