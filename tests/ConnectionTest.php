<?php

namespace Benson\LaravelFirebird\Tests;

use Benson\LaravelFirebird\Query\Builder as FirebirdQueryBuilder;
use Benson\LaravelFirebird\Query\Grammars\FirebirdGrammar as FirebirdQueryGrammar;
use Benson\LaravelFirebird\Query\Processors\FirebirdProcessor as FirebirdQueryProcessor;
use Benson\LaravelFirebird\Schema\Builder as FirebirdSchemaBuilder;
use Benson\LaravelFirebird\Schema\Grammars\FirebirdGrammar as FirebirdSchemaGrammar;
use Benson\LaravelFirebird\FirebirdConnection;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class ConnectionTest extends TestCase
{
    #[Test]
    public function it_gets_server_version()
    {
        $connection = DB::connection();

        $expectedVersion = $connection->selectOne('select rdb$get_context(\'SYSTEM\', \'ENGINE_VERSION\') as version from rdb$database');
        $expectedVersion = $expectedVersion->version;

        $version = $connection->getServerVersion();

        $this->assertEquals($expectedVersion, $version);
        $this->assertTrue(version_compare($expectedVersion, $version, '=='));

        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version);
    }

    #[Test]
    public function it_uses_the_configured_server_version_for_feature_detection()
    {
        $legacy = $this->makeOfflineConnection(['server_version' => '2.5.9']);

        $this->assertSame('2.5.9', $legacy->getServerVersion());
        $this->assertFalse($legacy->supportsIdentityColumns());
        $this->assertFalse($legacy->supportsTimeZoneTypes());
        $this->assertFalse($legacy->supportsAlterColumnNullability());
        $this->assertSame(31, $legacy->getMaxIdentifierLength());

        $modern = $this->makeOfflineConnection(['server_version' => '5.0.3']);

        $this->assertTrue($modern->supportsIdentityColumns());
        $this->assertTrue($modern->supportsTimeZoneTypes());
        $this->assertTrue($modern->supportsAlterColumnNullability());
        $this->assertSame(63, $modern->getMaxIdentifierLength());

        $firebirdThree = $this->makeOfflineConnection(['server_version' => '3.0.10']);

        $this->assertTrue($firebirdThree->supportsIdentityColumns());
        $this->assertFalse($firebirdThree->supportsTimeZoneTypes());
        $this->assertSame(31, $firebirdThree->getMaxIdentifierLength());
    }

    #[Test]
    public function it_keeps_legacy_generators_for_dialect_one_connections()
    {
        $connection = $this->makeOfflineConnection([
            'server_version' => '5.0.3',
            'dialect' => '1',
        ]);

        $this->assertFalse($connection->supportsIdentityColumns());
    }

    protected function makeOfflineConnection(array $config = [])
    {
        return new FirebirdConnection(
            fn () => throw new RuntimeException('The offline connection tests must not connect to Firebird.'),
            '',
            '',
            $config
        );
    }

    #[Test]
    public function it_gets_default_query_grammar()
    {
        $connection = DB::connection();

        $grammar = $connection->getQueryGrammar();

        $this->assertInstanceOf(FirebirdQueryGrammar::class, $grammar);
    }

    #[Test]
    public function it_gets_default_post_processor()
    {
        $connection = DB::connection();

        $processor = $connection->getPostProcessor();

        $this->assertInstanceOf(FirebirdQueryProcessor::class, $processor);
    }

    #[Test]
    public function it_gets_schema_builder()
    {
        $connection = DB::connection();

        $schemaBuilder = $connection->getSchemaBuilder();

        $this->assertInstanceOf(FirebirdSchemaBuilder::class, $schemaBuilder);
    }

    #[Test]
    public function it_gets_schema_grammar()
    {
        $connection = DB::connection();

        $connection->useDefaultSchemaGrammar();

        $grammar = $connection->getSchemaGrammar();

        $this->assertInstanceOf(FirebirdSchemaGrammar::class, $grammar);
    }

    #[Test]
    public function it_gets_query_builder()
    {
        $connection = DB::connection();

        $queryBuilder = $connection->query();

        $this->assertInstanceOf(FirebirdQueryBuilder::class, $queryBuilder);
    }

    #[Test]
    public function it_closes_an_implicit_pdo_transaction_before_starting_an_explicit_one()
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('inTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('commit')->willReturn(true);
        $pdo->expects($this->once())->method('beginTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('setAttribute')->with(PDO::ATTR_AUTOCOMMIT, false)->willReturn(true);

        $connection = new class($pdo) extends FirebirdConnection
        {
            public function runBeginTransactionStatement()
            {
                $this->executeBeginTransactionStatement();
            }
        };

        $connection->runBeginTransactionStatement();
    }

    #[Test]
    public function it_restores_autocommit_after_the_outer_transaction_is_committed()
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn(false);
        $pdo->expects($this->once())->method('beginTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('commit')->willReturn(true);
        $pdo->expects($this->exactly(2))
            ->method('setAttribute')
            ->willReturnMap([
                [PDO::ATTR_AUTOCOMMIT, false, true],
                [PDO::ATTR_AUTOCOMMIT, true, true],
            ]);

        $connection = new FirebirdConnection($pdo);

        $connection->beginTransaction();
        $connection->commit();
    }

    #[Test]
    public function it_restores_autocommit_after_the_outer_transaction_is_rolled_back()
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn(false, true);
        $pdo->expects($this->once())->method('beginTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('rollBack')->willReturn(true);
        $pdo->expects($this->exactly(2))
            ->method('setAttribute')
            ->willReturnMap([
                [PDO::ATTR_AUTOCOMMIT, false, true],
                [PDO::ATTR_AUTOCOMMIT, true, true],
            ]);

        $connection = new FirebirdConnection($pdo);

        $connection->beginTransaction();
        $connection->rollBack();
    }

    #[Test]
    public function it_restores_autocommit_after_a_successful_transaction_callback()
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn(false);
        $pdo->expects($this->once())->method('beginTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('commit')->willReturn(true);
        $pdo->expects($this->exactly(2))
            ->method('setAttribute')
            ->willReturnMap([
                [PDO::ATTR_AUTOCOMMIT, false, true],
                [PDO::ATTR_AUTOCOMMIT, true, true],
            ]);

        $connection = new FirebirdConnection($pdo);

        $result = $connection->transaction(fn () => 'committed');

        $this->assertSame('committed', $result);
        $this->assertSame(0, $connection->transactionLevel());
    }

    #[Test]
    public function it_restores_autocommit_and_clears_the_transaction_level_when_rollback_fails()
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn(false, true);
        $pdo->expects($this->once())->method('beginTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('rollBack')->willThrowException(new RuntimeException('Rollback failed.'));
        $pdo->expects($this->exactly(2))
            ->method('setAttribute')
            ->willReturnMap([
                [PDO::ATTR_AUTOCOMMIT, false, true],
                [PDO::ATTR_AUTOCOMMIT, true, true],
            ]);

        $connection = new FirebirdConnection($pdo);

        $connection->beginTransaction();

        try {
            $connection->rollBack();
            $this->fail('Rollback failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Rollback failed.', $exception->getMessage());
        }

        $this->assertSame(0, $connection->transactionLevel());
    }
}
