# Documentação do driver `benson/laravel-firebird`

Referência das funcionalidades do driver Firebird para Laravel. Mantida
atualizada conforme novos recursos são adicionados.

> Visão geral rápida e instalação estão no [README](../README.md). Este
> documento detalha o uso de cada recurso.

## Sumário

- [Configuração da conexão](#configuracao-da-conexao)
- [Identificadores e dialeto](#identificadores-e-dialeto)
- [Recursos por versão do servidor](#recursos-por-versao-do-servidor)
- [Paginação](#paginacao)
- [Datas: cast `date` sem hora](#datas-cast-date-sem-hora)
- [Nomenclatura de constraints e índices](#nomenclatura-de-constraints-e-indices)
- [Índice único sem constraint](#indice-unico-sem-constraint)
- [Comportamentos específicos do Firebird](#comportamentos-especificos-do-firebird)
- [Limitações conhecidas](#limitacoes-conhecidas)
- [Testes](#testes)

---

## Configuração da conexão

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

| Opção | Padrão | Descrição |
| --- | --- | --- |
| `quote_identifiers` | `true` | Envolve identificadores em aspas duplas. Desative para bases legadas com objetos sem aspas. |
| `uppercase_identifiers` | `false` | Converte identificadores não quotados para maiúsculas (estilo legado). |
| `pagination_mode` | `first_skip` | `first_skip` usa `SELECT FIRST n SKIP m`; `offset_fetch` usa `OFFSET ... FETCH`. |
| `dialect` | — | Defina `1` para bancos em dialeto 1 (ajusta tipos, datas e desliga identity). |
| `role` | — | Role SQL enviada na conexão. |
| `server_version` | autodetectada | Fixa a versão do servidor (ex.: `'3.0.10'`) e evita a detecção via conexão. |
| `index_names` | — | Templates para a nomenclatura padrão de PK/FK/unique/index. Veja a seção própria. |

---

## Identificadores, case e quoting

Esta é a área mais específica (e mais sensível) do Firebird. Entender como o
servidor trata identificadores evita a maior parte dos problemas de "tabela/
coluna não existe".

### Como o Firebird trata identificadores

- **Sem aspas** (`select * from CLIENTE`): case-insensitive; o servidor
  **dobra para MAIÚSCULAS** e é assim que o nome fica no catálogo
  (`rdb$relation_name = 'CLIENTE'`).
- **Com aspas** (`select * from "Cliente"`): case-sensitive; o nome é
  guardado **exatamente** como escrito. `"Cliente"`, `"cliente"` e `CLIENTE`
  são três objetos diferentes.

Consequência prática: uma tabela criada por uma ferramenta legada **sem aspas**
vira `AUDITORIA` no catálogo; uma tabela criada pelo Laravel (que por padrão
quota) vira `auditoria`. Os dois convivem no mesmo banco.

### Os dois parâmetros do driver

| Config | Avaliação interna | Efeito |
| --- | --- | --- |
| `quote_identifiers` | verdadeiro a menos que seja exatamente `false` (padrão: quota) | Quando ligado, envolve identificadores em aspas duplas, preservando o case. Quando desligado, emite identificadores **sem** aspas. |
| `uppercase_identifiers` | verdadeiro só quando exatamente `true` (padrão: `false`) | Quando ligado, converte identificadores não quotados para MAIÚSCULAS antes de emitir. |

### Matriz de combinações

Para `DB::table('cliente')->select('nome')`:

| `quote_identifiers` | `uppercase_identifiers` | SQL gerado | Use quando |
| --- | --- | --- | --- |
| `true` (padrão) | `false` (padrão) | `select "nome" from "cliente"` | Banco novo, criado pelo próprio Laravel (nomes minúsculos). |
| `false` | `true` | `select NOME from CLIENTE` | Banco legado, criado sem aspas (nomes maiúsculos). |
| `false` | `false` | `select nome from cliente` | Raro: o servidor dobra para `NOME`/`CLIENTE` na resolução. |
| `true` | `true` | `select "NOME" from "CLIENTE"` | Legado que você quer referenciar quotando em maiúsculas. |

> Regra prática: **banco novo** → mantenha o padrão (`quote_identifiers = true`,
> `uppercase_identifiers = false`). **Banco legado uppercase** →
> `quote_identifiers = false` + `uppercase_identifiers = true`.

### Identificadores reservados sempre quotados

Mesmo com `quote_identifiers = false`, alguns nomes são **sempre** quotados
porque colidem com palavras reservadas do Firebird:

```
KEY, TIMESTAMP, VALUE
```

Então `select key, value from cache` com modo legado gera
`select "KEY", "VALUE" from CACHE` — as colunas reservadas ficam quotadas (e
uppercased), o resto segue o modo configurado.

### Aliases

Fora do dialeto 1, aliases de coluna são **sempre** quotados, para que o nome
da coluna no resultado preserve o case (`select X as "userName"`). No dialeto 1
o alias segue a normalização de identificador (uppercase quando configurado).

### Case no resultado (`PDO::ATTR_CASE`)

A conexão usa `PDO::CASE_LOWER`: **todos os nomes de coluna do resultset voltam
em minúsculas**, inclusive aliases quotados. Ou seja, `select X as "userName"`
retorna a chave `username` no array/objeto. Os **valores** não são afetados —
só os nomes das colunas.

### Case na introspecção de schema

Métodos como `Schema::getTables()`, `getColumns()`, `getIndexes()` e
`getForeignKeys()` retornam os nomes **em minúsculas** (normalização do
processor), para casar com as expectativas do Laravel. Internamente, as
consultas de metadados normalizam o nome procurado conforme
`uppercase_identifiers` (maiúsculas quando ligado), de modo que
`Schema::hasTable('cliente')` encontra `CLIENTE` num banco legado.

> Atenção em bancos **mistos** (tabelas legadas maiúsculas + tabelas Laravel
> minúsculas): operações que pegam o nome minúsculo da introspecção e
> re-emitem um identificador quotado podem não casar com o nome real. O driver
> já trata isso em `dropAllTables`/`dropAllViews` (usando o nome real do
> catálogo); se encontrar outro ponto sensível, reporte.

### Case na nomenclatura de constraints/índices

O nome gerado pelos templates de [`index_names`](#nomenclatura-de-constraints-e-indices)
é literal — o case que você escreve no template é o ponto de partida. Em
seguida ele passa pelo mesmo wrapping acima:

- Com o padrão (`quote_identifiers = true`, `uppercase_identifiers = false`):
  `'PK_{table}'` numa tabela `clientes` vira a constraint `"PK_clientes"`
  (case preservado).
- Com `uppercase_identifiers = true`: o mesmo template vira `"PK_CLIENTES"`
  (forçado a maiúsculas).
- Com `quote_identifiers = false`: sai sem aspas, e o servidor dobra para
  maiúsculas no catálogo.

Escolha o case do template de acordo com a convenção do projeto **e** com o
modo de identificadores da conexão.

### Dialeto 1

Com `'dialect' => '1'`, além dos ajustes de tipos e datas, o auto incremento
por identity é desligado (volta ao caminho generator + trigger), e a
normalização de aliases segue o modo de identificadores em vez de quotar
sempre.

---

## Recursos por versão do servidor

A versão é detectada automaticamente (ou fixada via `server_version`) e habilita recursos:

- **Firebird 3+** — auto incremento via `GENERATED BY DEFAULT AS IDENTITY` (sem generator/trigger); `ALTER COLUMN SET/DROP NOT NULL` em `->change()`; `auto_increment` correto na introspecção; limites de eager load (`->limit()` em relações) via `ROW_NUMBER()`. Em servidores 2.5 o driver usa o caminho legado de generator + trigger e altera nulabilidade pela tabela de sistema.
- **Firebird 4+** — identificadores de até 63 caracteres; `timestampTz()`, `timeTz()` e `dateTimeTz()` geram tipos `WITH TIME ZONE`.

Nomes de objetos acima do limite de identificadores do servidor recebem sufixo de hash automático para evitar colisão silenciosa.

---

## Paginação

Padrão (`first_skip`):

```sql
select first 10 skip 20 * from CLIENTE
```

Com `'pagination_mode' => 'offset_fetch'`:

```sql
select * from CLIENTE offset 20 rows fetch first 10 rows only
```

---

## Datas: cast `date` sem hora

No dialeto 3 o tipo `DATE` do Firebird **não tem componente de hora**. O Laravel formata todo valor temporal com um único formato (`Y-m-d H:i:s`), o que faz o Firebird recusar a gravação de uma coluna `DATE` com `-413 conversion error from string`.

A solução fica na camada do model, usando o cast que você já declara. Há duas formas:

**1. Trait `SerializesFirebirdDates`:**

```php
use Benson\LaravelFirebird\Eloquent\Concerns\SerializesFirebirdDates;
use Illuminate\Database\Eloquent\Model;

class Pedido extends Model
{
    use SerializesFirebirdDates;

    protected $casts = [
        'data_emissao' => 'date',      // gravado como 2026-06-18
        'criado_em'    => 'datetime',  // gravado como 2026-06-18 13:45:00
    ];
}
```

**2. Model base `Benson\LaravelFirebird\Eloquent\Model`** (já inclui o trait):

```php
use Benson\LaravelFirebird\Eloquent\Model;

class Pedido extends Model
{
    protected $casts = ['data_emissao' => 'date'];
}
```

Comportamento:

| Cast | Você atribui | Vai para o banco |
| --- | --- | --- |
| `date` | `'2026-06-18'` | `2026-06-18` |
| `date` | `Carbon` com hora | `2026-06-18` (hora descartada) |
| `datetime` | `Carbon` com hora | `2026-06-18 13:45:00` |

A leitura continua devolvendo instâncias `Carbon`. Vale para acesso via Eloquent; um `DB::table()->insert(['data' => $carbon])` cru ainda usa o formato global — nesse caso passe a data como string `Y-m-d`.

---

## Nomenclatura de constraints e índices

Por padrão o Laravel nomeia constraints/índices como `{tabela}_{colunas}_{tipo}`. Para seguir a convenção do seu projeto, configure `index_names` na conexão, com os placeholders `{table}` e `{columns}`:

```php
'firebird' => [
    // ...
    'index_names' => [
        'primary'     => 'PK_{table}',
        'foreign'     => 'FK_{table}_{columns}',
        'unique'      => 'UQ_{table}_{columns}',
        'index'       => 'IX_{table}_{columns}',
        'uniqueIndex' => 'UX_{table}_{columns}',
    ],
],
```

A partir daí, constraints criadas **sem nome explícito** seguem o padrão:

```php
Schema::create('clientes', function (Blueprint $table) {
    $table->integer('id');
    $table->string('email');
    $table->integer('user_id');

    $table->primary('id');                                      // PK_clientes
    $table->unique('email');                                    // UQ_clientes_email
    $table->unique(['cpf', 'nome']);                            // UQ_clientes_cpf_nome
    $table->index('nome');                                      // IX_clientes_nome
    $table->foreign('user_id')->references('id')->on('users');  // FK_clientes_user_id
});
```

Detalhes:

- **Cada tipo é opcional** — os não definidos mantêm o padrão do Laravel.
- **Nome explícito** (2º argumento) sempre tem prioridade: `$table->unique('email', 'UQ_LOGIN')`.
- **Drop por colunas** reusa o mesmo gerador de nome, então `$table->dropUnique(['email'])` casa o nome certo.
- **`unique()` cria constraint + índice único** de mesmo nome (no Firebird uma UNIQUE constraint gera um índice único por baixo).
- **Foreign keys criam um índice automaticamente.** No Firebird, ao criar uma FK o servidor cria um índice nas colunas da FK, com o **mesmo nome** da constraint. Ou seja, com `'foreign' => 'FK_{table}_{columns}'`, existe uma constraint `FK_...` e um índice `FK_...`. Não é preciso (nem se deve) criar um `index()` adicional para a coluna da FK — isso geraria índice duplicado.
- **Limite de tamanho**: nomes longos recebem sufixo de hash respeitando o limite do servidor (31/63 caracteres).

---

## Índice único sem constraint

`unique()` cria uma UNIQUE **constraint**. Quando você quer um índice único **puro** (`CREATE UNIQUE INDEX`, sem constraint — não referenciável por FK, dropado com `DROP INDEX`), use `uniqueIndex()`:

```php
Schema::table('clientes', function (Blueprint $table) {
    $table->uniqueIndex('email');                 // UX_clientes_email (template uniqueIndex)
    $table->uniqueIndex(['cpf', 'nome']);         // UX_clientes_cpf_nome
    $table->uniqueIndex('cnpj', 'UX_ESPECIAL');   // nome explícito
});
```

Compila para `CREATE UNIQUE INDEX`. O nome automático segue o template `uniqueIndex` do `index_names` (com fallback para o padrão do Laravel se não definido).

Para remover:

```php
$table->dropUniqueIndex('UX_clientes_email'); // por nome
$table->dropUniqueIndex(['email']);           // por colunas (reconstrói o nome pelo template)
```

Diferença entre os dois:

| | `unique()` | `uniqueIndex()` |
| --- | --- | --- |
| Objeto criado | UNIQUE constraint (+ índice) | apenas índice único |
| Aparece em `rdb$relation_constraints` | sim | não |
| Pode ser alvo de FK | sim | não |
| Removido com | `dropUnique()` | `dropUniqueIndex()` |

---

## Comportamentos específicos do Firebird

- **`insertOrIgnore`** insere linha a linha em transação e ignora violações de qualquer constraint única. `insertOrIgnoreUsing` resolve os índices únicos da tabela e gera `NOT EXISTS` por índice.
- **`firstOrCreate`/`createOrFirst`** funcionam em condição de corrida (violações de unique viram `UniqueConstraintViolationException`).
- **`truncate()`** emula `TRUNCATE` com `DELETE FROM` e reinicia generators e colunas identity.
- **`upsert()`** compila para `MERGE`.
- **Update/delete com joins** localizam as linhas alvo via `RDB$DB_KEY`.
- **Listas `IN` com mais de 1499 itens** são divididas em múltiplos grupos `IN` automaticamente.
- **`whereLike`** respeita a collation do Firebird (não força `UPPER()`, para não quebrar índices/collation CI).
- **`dropAllTables`/`dropAllViews`** usam o nome real do catálogo (case preservado), funcionando tanto para tabelas minúsculas (criadas com aspas) quanto maiúsculas (legadas, criadas sem aspas).

---

## Limitações conhecidas

- A conexão usa `PDO::ATTR_CASE_LOWER`: nomes de coluna do resultset voltam em minúsculas, inclusive aliases quotados.
- `disableForeignKeyConstraints()`/`enableForeignKeyConstraints()` são no-ops (Firebird não tem toggle de FK por conexão). `dropAllTables()` derruba as FKs antes das tabelas.
- Operações JSON (`whereJsonContains`, seletores `->`) não são suportadas; colunas `json()` viram `BLOB SUB_TYPE TEXT`.
- `Schema::rename()` (renomear tabela) não é suportado pelo Firebird.
- `lock(false)` (shared lock) é ignorado; apenas `lockForUpdate()` tem efeito.
- `orderByRandom()` ignora seed.
- CHECK constraints não têm nomenclatura automática (o Laravel não tem `$table->check()` nativo); o único CHECK emitido é o inline do `enum()`.
- O cliente `fbclient` deve ser compatível com o servidor: um cliente 3.0 contra servidor 4+/5 falha (`-204 Data type unknown`) ao ler tipos novos (`TIMESTAMP WITH TIME ZONE`, `DECFLOAT`, `INT128`) ou `CURRENT_TIMESTAMP` cru.

---

## Testes

```bash
docker compose up -d        # Firebird 5 (porta 3055) e Firebird 4 (porta 3054)
DB_PORT=3055 vendor/bin/phpunit
```

Para dialeto 1, exporte `DB_DIALECT=1` apontando para um banco em dialeto 1.

O CI (GitHub Actions) roda a suíte contra Firebird 3, 4 e 5 em PHP 8.3 e 8.4.
