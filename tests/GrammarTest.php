<?php

namespace Benson\LaravelFirebird\Tests;

use Benson\LaravelFirebird\FirebirdConnection;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class GrammarTest extends TestCase
{
    #[Test]
    public function it_quotes_identifiers_by_default()
    {
        $connection = $this->makeConnection();

        $sql = $connection->table('cliente')
            ->select('clienteid', 'nome')
            ->where('clienteid', 1)
            ->toSql();

        $this->assertSame('select "clienteid", "nome" from "cliente" where "clienteid" = ?', $sql);
    }

    #[Test]
    public function it_can_compile_unquoted_uppercase_identifiers()
    {
        $connection = $this->makeConnection([
            'quote_identifiers' => false,
            'uppercase_identifiers' => true,
        ]);

        $sql = $connection->table('cliente')
            ->select('clienteid', 'nome')
            ->where('clienteid', 1)
            ->orderBy('clienteid')
            ->toSql();

        $this->assertSame('select CLIENTEID, NOME from CLIENTE where CLIENTEID = ? order by CLIENTEID asc', $sql);
    }

    #[Test]
    public function it_compiles_unquoted_uppercase_table_aliases_consistently()
    {
        $connection = $this->makeConnection([
            'quote_identifiers' => false,
            'uppercase_identifiers' => true,
        ]);

        $sql = $connection->table('cliente as c')
            ->select('c.clienteid as clienteId')
            ->where('c.nome', 'Anna')
            ->toSql();

        $this->assertSame('select C.CLIENTEID as "clienteId" from CLIENTE as C where C.NOME = ?', $sql);
    }

    #[Test]
    public function it_compiles_insert_get_id_with_unquoted_uppercase_returning()
    {
        $connection = $this->makeConnection([
            'quote_identifiers' => false,
            'uppercase_identifiers' => true,
        ]);

        $grammar = $connection->getQueryGrammar();
        $query = $connection->table('cliente');

        $sql = $grammar->compileInsertGetId($query, ['nome' => 'Cliente Teste'], 'clienteid');

        $this->assertSame('insert into CLIENTE (NOME) values (?) returning CLIENTEID', $sql);
    }

    #[Test]
    public function it_compiles_exists_with_a_neutral_alias()
    {
        $connection = $this->makeConnection([
            'quote_identifiers' => false,
            'uppercase_identifiers' => true,
        ]);

        $grammar = $connection->getQueryGrammar();
        $query = $connection->table('cliente')->where('clienteid', 1);

        $sql = $grammar->compileExists($query);

        $this->assertSame('select first 1 1 as EXISTS_RESULT from CLIENTE where CLIENTEID = ?', $sql);
    }

    #[Test]
    public function it_uses_first_skip_pagination_by_default()
    {
        $connection = $this->makeConnection([
            'quote_identifiers' => false,
            'uppercase_identifiers' => true,
        ]);

        $limitSql = $connection->table('cliente')->limit(10)->toSql();
        $offsetSql = $connection->table('cliente')->offset(20)->toSql();
        $limitOffsetSql = $connection->table('cliente')->offset(20)->limit(10)->toSql();

        $this->assertSame('select first 10 * from CLIENTE', $limitSql);
        $this->assertSame('select skip 20 * from CLIENTE', $offsetSql);
        $this->assertSame('select first 10 skip 20 * from CLIENTE', $limitOffsetSql);
    }

    #[Test]
    public function it_can_use_offset_fetch_pagination()
    {
        $connection = $this->makeConnection([
            'quote_identifiers' => false,
            'uppercase_identifiers' => true,
            'pagination_mode' => 'offset_fetch',
        ]);

        $sql = $connection->table('cliente')->offset(20)->limit(10)->toSql();

        $this->assertSame('select * from CLIENTE offset 20 rows fetch first 10 rows only', $sql);
    }

    #[Test]
    public function it_places_first_skip_before_distinct()
    {
        $connection = $this->makeConnection([
            'quote_identifiers' => false,
            'uppercase_identifiers' => true,
        ]);

        $sql = $connection->table('pedido')
            ->distinct()
            ->select('clienteid')
            ->limit(10)
            ->toSql();

        $this->assertSame('select first 10 distinct CLIENTEID from PEDIDO', $sql);
    }

    #[Test]
    public function it_quotes_reserved_identifiers_even_when_identifier_quoting_is_disabled()
    {
        $connection = $this->makeConnection([
            'quote_identifiers' => false,
            'uppercase_identifiers' => true,
        ]);

        $sql = $connection->table('cache')
            ->select('key', 'value', 'timestamp')
            ->where('key', 'foo')
            ->toSql();

        $this->assertSame('select "KEY", "VALUE", "TIMESTAMP" from CACHE where "KEY" = ?', $sql);
    }

    #[Test]
    public function it_compiles_single_row_insert_or_ignore_for_firebird()
    {
        $connection = $this->makeConnection([
            'quote_identifiers' => false,
            'uppercase_identifiers' => true,
        ]);

        $grammar = $connection->getQueryGrammar();
        $query = $connection->table('cache');

        $sql = $grammar->compileInsertOrIgnore($query, [
            'key' => 'rate-limit',
            'value' => 'yes',
            'expiration' => 60,
        ]);

        $this->assertSame(
            'insert into CACHE ("KEY", "VALUE", EXPIRATION) select V."KEY", V."VALUE", V.EXPIRATION from (select cast(? as varchar(255)) as "KEY", cast(? as varchar(3)) as "VALUE", cast(? as integer) as EXPIRATION from RDB$DATABASE) V where not exists (select 1 from CACHE T where T."KEY" = V."KEY")',
            $sql
        );
    }

    protected function makeConnection(array $config = [])
    {
        return new FirebirdConnection(
            fn () => throw new RuntimeException('The grammar tests must not connect to Firebird.'),
            '',
            '',
            $config
        );
    }
}
