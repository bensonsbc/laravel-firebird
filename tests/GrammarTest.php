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
    public function it_compiles_lock_for_update()
    {
        $connection = $this->makeConnection([
            'quote_identifiers' => false,
            'uppercase_identifiers' => true,
        ]);

        $sql = $connection->table('cliente')
            ->where('clienteid', 1)
            ->lockForUpdate()
            ->toSql();

        $this->assertSame('select * from CLIENTE where CLIENTEID = ? for update with lock', $sql);
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
    public function it_compiles_insert_or_ignore_using_with_unique_column_sets()
    {
        $connection = $this->makeConnection([
            'quote_identifiers' => false,
            'uppercase_identifiers' => true,
        ]);

        $grammar = $connection->getQueryGrammar();
        $query = $connection->table('users');

        $sql = $grammar->compileInsertOrIgnoreUsing(
            $query,
            ['id', 'email'],
            'select 1, \'a\' from RDB$DATABASE',
            [['id'], ['email']]
        );

        $this->assertSame(
            'insert into USERS (ID, EMAIL) select V.ID, V.EMAIL from (select 1, \'a\' from RDB$DATABASE) V '
            .'where not exists (select 1 from USERS T where T.ID = V.ID) '
            .'and not exists (select 1 from USERS T where T.EMAIL = V.EMAIL)',
            $sql
        );
    }

    #[Test]
    public function it_compiles_insert_or_ignore_using_without_unique_column_sets_as_a_plain_insert()
    {
        $connection = $this->makeConnection([
            'quote_identifiers' => false,
            'uppercase_identifiers' => true,
        ]);

        $grammar = $connection->getQueryGrammar();
        $query = $connection->table('users');

        $sql = $grammar->compileInsertOrIgnoreUsing($query, ['id'], 'select 1 from RDB$DATABASE');

        $this->assertSame('insert into USERS (ID) select 1 from RDB$DATABASE', $sql);
    }

    #[Test]
    public function it_compiles_exists_for_union_queries_with_an_outer_first()
    {
        $connection = $this->makeConnection([
            'quote_identifiers' => false,
            'uppercase_identifiers' => true,
        ]);

        $grammar = $connection->getQueryGrammar();
        $query = $connection->table('cliente')
            ->select('clienteid')
            ->union($connection->table('pedido')->select('clienteid'));

        $sql = $grammar->compileExists($query);

        $this->assertSame(
            'select first 1 1 as EXISTS_RESULT from ('
            .'select * from (select CLIENTEID from CLIENTE) union select * from (select CLIENTEID from PEDIDO)'
            .') FB_EXISTS',
            $sql
        );
    }

    #[Test]
    public function it_compiles_upsert_using_firebird_merge()
    {
        $connection = $this->makeConnection([
            'quote_identifiers' => false,
            'uppercase_identifiers' => true,
        ]);

        $grammar = $connection->getQueryGrammar();
        $query = $connection->table('cliente');

        $sql = $grammar->compileUpsert($query, [
            [
                'clienteid' => 1,
                'nome' => 'Anna',
            ],
            [
                'clienteid' => 2,
                'nome' => 'Bruno',
            ],
        ], ['clienteid'], ['nome']);

        $this->assertSame(
            'merge into CLIENTE T using (select cast(? as bigint) as CLIENTEID, cast(? as varchar(4)) as NOME from RDB$DATABASE union all select cast(? as bigint) as CLIENTEID, cast(? as varchar(5)) as NOME from RDB$DATABASE) S on S.CLIENTEID = T.CLIENTEID when matched then update set NOME = S.NOME when not matched then insert (CLIENTEID, NOME) values (S.CLIENTEID, S.NOME)',
            $sql
        );
    }

    #[Test]
    public function it_uses_integer_casts_for_dialect_one_merge_sources()
    {
        $connection = $this->makeConnection([
            'quote_identifiers' => false,
            'uppercase_identifiers' => true,
            'dialect' => '1',
        ]);

        $grammar = $connection->getQueryGrammar();
        $query = $connection->table('cliente');

        $sql = $grammar->compileUpsert($query, [
            [
                'clienteid' => 1,
                'nome' => 'Anna',
            ],
        ], ['clienteid'], ['nome']);

        $this->assertStringContainsString('cast(? as integer) as CLIENTEID', $sql);
        $this->assertStringNotContainsString('cast(? as bigint) as CLIENTEID', $sql);
    }

    #[Test]
    public function it_compiles_where_like_clauses_for_firebird()
    {
        $connection = $this->makeConnection([
            'quote_identifiers' => false,
            'uppercase_identifiers' => true,
        ]);

        $insensitive = $connection->table('cliente')->whereLike('nome', 'anna%')->toSql();
        $sensitive = $connection->table('cliente')->whereLike('nome', 'Anna%', true)->toSql();
        $notInsensitive = $connection->table('cliente')->whereNotLike('nome', 'anna%')->toSql();

        $this->assertSame('select * from CLIENTE where upper(NOME) like upper(?)', $insensitive);
        $this->assertSame('select * from CLIENTE where NOME like ?', $sensitive);
        $this->assertSame('select * from CLIENTE where upper(NOME) not like upper(?)', $notInsensitive);
    }

    #[Test]
    public function it_compiles_group_limits_with_window_functions_on_firebird_three_and_newer()
    {
        $connection = $this->makeConnection([
            'quote_identifiers' => false,
            'uppercase_identifiers' => true,
            'server_version' => '3.0.10',
        ]);

        $sql = $connection->table('pedido')->groupLimit(3, 'clienteid')->toSql();

        $this->assertSame(
            'select * from (select *, row_number() over (partition by CLIENTEID) as LARAVEL_ROW from PEDIDO) as LARAVEL_TABLE where LARAVEL_ROW <= 3 order by LARAVEL_ROW',
            $sql
        );
    }

    #[Test]
    public function it_rejects_group_limits_on_servers_without_window_functions()
    {
        $connection = $this->makeConnection(['server_version' => '2.5.9']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('group limits');

        $connection->table('pedido')->groupLimit(3, 'clienteid')->toSql();
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
