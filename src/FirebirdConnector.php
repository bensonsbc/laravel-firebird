<?php

namespace Benson\LaravelFirebird;

use Illuminate\Database\Connectors\Connector;
use Illuminate\Database\Connectors\ConnectorInterface;
use InvalidArgumentException;
use PDO;

class FirebirdConnector extends Connector implements ConnectorInterface
{
    /**
     * Establish a database connection.
     *
     * @param  array  $config
     * @return \PDO
     */
    public function connect(array $config)
    {
        $dsn = $this->getDsn($config);

        $options = $this->getOptions($config);

        $connection = $this->createConnection($dsn, $config, $options);

        $connection->setAttribute(PDO::ATTR_CASE, PDO::CASE_LOWER);

        return $connection;
    }

    /**
     * Create a DSN string from a configuration.
     *
     * @param  array  $config
     * @return string
     */
    protected function getDsn(array $config)
    {
        $database = (string) ($config['database'] ?? '');

        if ($database === '') {
            throw new InvalidArgumentException('Firebird connection requires a database path.');
        }

        $host = (string) ($config['host'] ?? '');
        $port = (string) ($config['port'] ?? '');
        $charset = (string) ($config['charset'] ?? 'UTF8');
        $role = (string) ($config['role'] ?? '');
        $dialect = (string) ($config['dialect'] ?? '');

        $databaseName = $database;

        if ($host !== '') {
            $databaseName = $host;

            if ($port !== '') {
                $databaseName .= "/{$port}";
            }

            $databaseName .= ":{$database}";
        }

        $segments = [
            "dbname={$databaseName}",
            "charset={$charset}",
        ];

        if ($role !== '') {
            $segments[] = "role={$role}";
        }

        if ($dialect !== '') {
            $segments[] = "dialect={$dialect}";
        }

        return 'firebird:'.implode(';', $segments);
    }
}
