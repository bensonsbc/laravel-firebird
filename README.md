# Benson Laravel Firebird

Driver Firebird para Laravel baseado em `harrygulliford/laravel-firebird`.

Nome oficial do pacote/driver:

```text
benson/laravel-firebird
```

O objetivo do projeto e manter uma integracao Firebird mais previsivel para aplicacoes Laravel, com foco inicial em Query Builder, Eloquent basico, paginacao, `exists` e `insertGetId()` usando `INSERT ... RETURNING`.

## Configuracao

Exemplo de conexao Laravel:

```php
'firebird' => [
    'driver' => 'firebird',
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '3050'),
    'database' => env('DB_DATABASE'),
    'username' => env('DB_USERNAME', 'sysdba'),
    'password' => env('DB_PASSWORD', 'masterkey'),
    'charset' => env('DB_CHARSET', 'UTF8'),

    'quote_identifiers' => false,
    'uppercase_identifiers' => true,
    'pagination_mode' => 'first_skip',
],
```

Com `quote_identifiers` desativado e `uppercase_identifiers` ativado, uma consulta como:

```php
DB::table('cliente')->where('clienteid', 1)->toSql();
```

gera SQL compativel com bases Firebird legadas:

```sql
select * from CLIENTE where CLIENTEID = ?
```

O valor padrao ainda e manter identificadores entre aspas, preservando o comportamento original do pacote.

## Paginacao

Por padrao, o driver usa a sintaxe tradicional do Firebird:

```sql
select first 10 skip 20 * from CLIENTE
```

Para usar a sintaxe moderna, configure:

```php
'pagination_mode' => 'offset_fetch',
```

Isso gera SQL como:

```sql
select * from CLIENTE offset 20 rows fetch first 10 rows only
```
