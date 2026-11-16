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
    public function it_rejects_dsn_options_that_would_inject_extra_parameters()
    {
        foreach (['database', 'host', 'port', 'charset', 'role', 'dialect'] as $option) {
            try {
                $this->dsn(['database' => 'database.fdb', $option => "x;role=RDB\$ADMIN"]);
                $this->fail("The [{$option}] option accepted a ';'.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString("[{$option}]", $exception->getMessage());
            }
        }

        $this->expectException(InvalidArgumentException::class);

        $this->dsn(['database' => "database.fdb\nrole=RDB\$ADMIN"]);
    }

    #[Test]
    public function it_forces_lowercase_result_columns()
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

        $connector->connect(['database' => 'database.fdb']);

        $this->assertSame(PDO::CASE_LOWER, $connection->attributes[PDO::ATTR_CASE]);
    }

    private function dsn(array $config): string
    {
        $method = new ReflectionMethod(FirebirdConnector::class, 'getDsn');
        $method->setAccessible(true);

        return $method->invoke(new FirebirdConnector(), $config);
    }
}
