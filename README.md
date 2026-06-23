# Benson Laravel Firebird

Firebird database driver for Laravel · Driver Firebird para Laravel.

**[🇧🇷 Português](#-português)** · **[🇬🇧 English](#-english)**

> Fork de / fork of **[`harrygulliford/laravel-firebird`](https://github.com/harrygulliford/laravel-firebird)** (que descende de / descending from **[`jacquestvanzuydam/laravel-firebird`](https://github.com/jacquestvanzuydam/laravel-firebird)**). Veja [Créditos](#créditos--credits).
>
> 💜 Apoie o projeto / support the project: **[github.com/sponsors/bensonsbc](https://github.com/sponsors/bensonsbc)**

---

## 🇧🇷 Português

Integração Firebird previsível para o Laravel: Query Builder, Eloquent, paginação, `insertGetId()` com `INSERT ... RETURNING`, `insertOrIgnore`, `upsert` via `MERGE`, update/delete com joins, truncate, identity columns, colunas computadas, tabelas temporárias e introspecção de schema — com suporte a Firebird 2.5 → 5.0 (dialetos 1 e 3).

### Instalação

```bash
composer require benson/laravel-firebird
```

Configure a conexão em `config/database.php`:

```php
'firebird' => [
    'driver' => 'firebird',
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '3050'),
    'database' => env('DB_DATABASE'),
    'username' => env('DB_USERNAME', 'sysdba'),
    'password' => env('DB_PASSWORD', 'masterkey'),
    'charset' => env('DB_CHARSET', 'UTF8'),

    'quote_identifiers' => true,
    'uppercase_identifiers' => false,
    'pagination_mode' => 'first_skip',
],
```

### Opções específicas do driver

| Opção | Padrão | Descrição |
| --- | --- | --- |
| `quote_identifiers` | `true` | Envolve identificadores em aspas duplas. Desative para bases legadas sem aspas. |
| `uppercase_identifiers` | `false` | Converte identificadores não quotados para maiúsculas (estilo legado). |
| `pagination_mode` | `first_skip` | `first_skip` = `SELECT FIRST n SKIP m`; `offset_fetch` = `OFFSET ... FETCH`. |
| `dialect` | — | Defina `1` para bancos em dialeto 1 (ajusta tipos, datas e desliga identity). |
| `role` | — | Role SQL enviada na conexão. |
| `server_version` | autodetectada | Fixa a versão do servidor (ex.: `'3.0.10'`), evitando a detecção via conexão. |
| `index_names` | — | Templates de nomenclatura de PK/FK/unique/index. |

### Destaques

- **Recursos por versão**: identity columns e `ALTER COLUMN` de nulidade no Firebird 3+; tipos `WITH TIME ZONE` e identificadores de 63 caracteres no Firebird 4+.
- **Nomenclatura configurável** de constraints/índices (`index_names`), inclusive para a PK inline de colunas identity.
- **Colunas computadas** (`virtualAs`/`storedAs` → `COMPUTED BY`) e **tabelas temporárias** (`temporary()` → GTT).
- **`uniqueIndex()`** (índice único sem constraint), **`COMMENT ON`**, **`insertOrIgnore`** por constraint real, reconexão automática e introspecção com `auto_increment`.
- **Datas**: trait `SerializesFirebirdDates` (e model base) grava cast `date` sem o componente de hora, evitando o erro de conversão do Firebird.

> 📖 **Documentação completa das funcionalidades: [docs/funcionalidades.md](docs/funcionalidades.md)** — inclui identificadores/case/quoting, paginação, datas, nomenclatura, computed columns, GTT, limitações e mais.

### Apoie o open source

Este driver existe porque a comunidade compartilha seu trabalho abertamente. Se ele te poupou tempo, considere retribuir: ⭐ dê uma estrela, 🐛 abra issues/PRs, ou 💜 **patrocine o desenvolvimento** em **[github.com/sponsors/bensonsbc](https://github.com/sponsors/bensonsbc)**. Qualquer apoio torna sustentável manter o ecossistema Firebird + PHP saudável.

---

## 🇬🇧 English

A predictable Firebird integration for Laravel: Query Builder, Eloquent, pagination, `insertGetId()` via `INSERT ... RETURNING`, `insertOrIgnore`, `upsert` via `MERGE`, joined update/delete, truncate, identity columns, computed columns, temporary tables and schema introspection — supporting Firebird 2.5 → 5.0 (dialects 1 and 3).

### Installation

```bash
composer require benson/laravel-firebird
```

Configure the connection in `config/database.php`:

```php
'firebird' => [
    'driver' => 'firebird',
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '3050'),
    'database' => env('DB_DATABASE'),
    'username' => env('DB_USERNAME', 'sysdba'),
    'password' => env('DB_PASSWORD', 'masterkey'),
    'charset' => env('DB_CHARSET', 'UTF8'),

    'quote_identifiers' => true,
    'uppercase_identifiers' => false,
    'pagination_mode' => 'first_skip',
],
```

### Driver-specific options

| Option | Default | Description |
| --- | --- | --- |
| `quote_identifiers` | `true` | Wraps identifiers in double quotes. Disable for legacy unquoted schemas. |
| `uppercase_identifiers` | `false` | Uppercases unquoted identifiers (legacy style). |
| `pagination_mode` | `first_skip` | `first_skip` = `SELECT FIRST n SKIP m`; `offset_fetch` = `OFFSET ... FETCH`. |
| `dialect` | — | Set `1` for dialect-1 databases (adjusts types, dates, disables identity). |
| `role` | — | SQL role sent on connect. |
| `server_version` | auto-detected | Pin the server version (e.g. `'3.0.10'`) to skip detection over the connection. |
| `index_names` | — | Naming templates for PK/FK/unique/index. |

### Highlights

- **Version-aware features**: identity columns and nullability `ALTER COLUMN` on Firebird 3+; `WITH TIME ZONE` types and 63-char identifiers on Firebird 4+.
- **Configurable naming** of constraints/indexes (`index_names`), including the inline PK of identity columns.
- **Computed columns** (`virtualAs`/`storedAs` → `COMPUTED BY`) and **temporary tables** (`temporary()` → GTT).
- **`uniqueIndex()`** (unique index without a constraint), **`COMMENT ON`**, real-constraint **`insertOrIgnore`**, automatic reconnect and introspection with `auto_increment`.
- **Dates**: the `SerializesFirebirdDates` trait (and base model) stores `date` casts without the time component, avoiding Firebird's conversion error.

> 📖 **Full feature documentation: [docs/funcionalidades.md](docs/funcionalidades.md)** (in Portuguese) — covers identifiers/case/quoting, pagination, dates, naming, computed columns, GTT, limitations and more.

### Support open source

This driver exists because the community shares its work openly. If it saved you time, consider giving back: ⭐ star the repo, 🐛 open issues/PRs, or 💜 **sponsor the development** at **[github.com/sponsors/bensonsbc](https://github.com/sponsors/bensonsbc)**. Any support helps keep the Firebird + PHP ecosystem healthy and sustainable.

---

## Testes / Tests

```bash
docker compose up -d        # Firebird 5 (port 3055) and Firebird 4 (port 3054)
DB_PORT=3055 vendor/bin/phpunit
```

Para dialeto 1, exporte `DB_DIALECT=1` apontando para um banco em dialeto 1. / For dialect 1, export `DB_DIALECT=1` pointing at a dialect-1 database.

O CI roda a suíte contra Firebird 3, 4 e 5 em PHP 8.3 e 8.4. / CI runs the suite against Firebird 3, 4 and 5 on PHP 8.3 and 8.4.

## Créditos / Credits

- **[Jacques van Zuydam](https://github.com/jacquestvanzuydam)** — autor original / original author.
- **[Harry Gulliford](https://github.com/harrygulliford)** — mantenedor do fork-base, maior parte da implementação / maintainer of the base fork, bulk of the implementation.
- **Contribuidores / contributors**: mariuz, Ricardo Seriani, Victor Vilella, Donny Kurnia, Felipi Franco, Johan Weultjes, Simon Rasmussen, fesoft, selmo47, Maitrepylos, entre outros / among others.
- **Projeto Firebird**, extensão **`pdo_firebird`** e a **comunidade Laravel** / the Firebird project, the `pdo_firebird` extension and the Laravel community.

Fork atual / current fork: **[`benson/laravel-firebird`](https://github.com/bensonsbc/laravel-firebird)** — Alexandre Benson Smith ([Thor Software](https://thorsoftware.com.br)).

## Licença / License

MIT — a mesma do projeto de origem / same as the upstream project.
