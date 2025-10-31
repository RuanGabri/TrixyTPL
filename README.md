# TrixyTPL — Documentação

**Resumo** este documento descreve a versão atual do motor de templates em PHP, Trixy — parser, AST de `Node`, renderer, parser de condições, debugger e utilitários. Atualizado para refletir o código fonte fornecido (funções globais e classes: `Node`, `replaceVar`, `processFilter`, `split_args`, `resolveValue`, `NodeDebugger`, `TemplateParser`, `ConditionParser`, `TemplateRenderer`, `Template`).

---

## 1. Visão geral / fluxo

1. **Template** — carrega o arquivo `.tpl`/`.html`.
2. **TemplateParser** — transforma o texto em uma árvore de `Node` (AST).
3. **ConditionParser** — quando há expressões condicionais, constrói uma AST específica para avaliação.
4. **TemplateRenderer** — percorre a árvore e gera o output final, usando `replaceVar` para variáveis e `processFilter` para filtros.
5. **NodeDebugger** — visualização amigável da árvore para depuração.

O motor foi projetado para ser simples, previsível e seguro (usa `htmlspecialchars` nas saídas de variáveis por padrão).

---

## 2. Conceitos principais

### Node

A entidade básica da AST. Estrutura pública (propriedades):

* `type` — tipo do nó (`root`, `text`, `if`, `elseif`, `else`, `for`, `foreach`, `require`, `str_filter`, `comparison`, `and`, `or`, `not`, `literal`, etc.).
* `content` — conteúdo do nó (string, array de `Node`s ou outros valores conforme o tipo).
* `params` — parâmetros do nó (ex.: `condition`, `times`, `listname`, `item`, `key`, `archive`, etc.).
* `dependents` — nós dependentes (ex.: `elseif`/`else` ligados a um `if`).

### Contextos de dados

* **Global**: array passado em `Template::render($data)`; contém dados acessíveis por nome de variável.
* **Local**: contexto interno gerado para loops/iteração; contém variáveis injetadas (ex.: item do `foreach`, `loop_index`, chave).

---

## 3. Sintaxe do template

### 3.1 Variáveis

Variáveis são escritas com chaves: `{nome}`, `{usuario.email}`, `{pedido.total}`.

* Suporta *dot notation* para acessar subcampos.
* Na renderização, `_todas_` as variáveis passam por `htmlspecialchars` (ENT_QUOTES, UTF-8).
* **Filtros simples** em variáveis: `{nome|upper}` — o filtro deve ser um único identificador (apenas `\w+`).

**Exemplo:**

```html
Olá, {nome}! Seu e-mail: {usuario.email}.
```

### 3.2 Condicionais

Blocos condicionais usam colchetes e chaves com sintaxe:

```text
[if <expressão> {
  ...
} elseif <expressão> {
  ...
} else {
  ...
}]
```

* Operadores suportados: `==`, `=`, `!=`, `!==`, `===`, `>`, `<`, `>=`, `<=`.
* Conectivos: `&&`, `||`, `!`.
* Parênteses `()` são aceitos e respeitados pela prioridade.

*Observação:* o `ConditionParser` tokeniza comparações e monta uma AST composta por nós `comparison`, `and`, `or`, `not` e `literal`.

### 3.3 Loops

#### For

```text
[for 3 {
  Iteração: {loop_index}
}]
```

* Repete o bloco N vezes, pode ser um número ou uma variável. 
* `loop_index` (0-based) está disponível no contexto local.

#### Foreach

```text
[foreach usuarios as usuario {
  Nome: {usuario.nome}
}]
```

Com chave e valor:

```text
[foreach pedidos as id => pedido {
  Pedido #{id}: {pedido.valor}
}]
```

* `listname` (a lista a ser iterada) é resolvida por `resolvepath` no contexto global
* `item` e `key` são injetados no contexto local do bloco.
* `loop_index` também é criado para cada iteração.

### 3.4 Require (inclusão)

Sintaxe suportada:

```
[require "arquivo.tpl"]
[require 'outro.tpl']
[require nome_variavel]   # resolveValue() é chamado para obter o nome
```

* O motor usa `TemplateParser::requireParser()` para ler o arquivo e inserir a subárvore.
* O nome do arquivo passa por `resolveValue()`, permitindo usar variáveis no nome.
* Se o arquivo não existir, `showRunTimeExcept()` é chamado (lança e imprime uma RuntimeException amigável).

### 3.5 str_filter

Forma para aplicar filtros complexos diretamente sobre uma string literal, variável ou expressão:

```
[str_filter("texto qualquer", (filter1, filter2(param1,param2)))]
```

Internamente o parser avalia o primeiro argumento com `resolveValue()` e cada filtro com `processFilter()`.

---

## 4. Filtros (processFilter)

Dois modos:

* **Filtro simples** (nome): usado via `{var|upper}` ou via `str_filter` (quando fornecido apenas o nome). Implementados: `strip_tags`, `trim`, `nl2br`, `upper`, `lower`, `capitalize`, `ufirst`, `length`.

* **Filtro complexo** (com sintaxe de função): servido por `processFilter` e por `{var|upper}` quando a string do filtro tem a forma `name(params...)`. Implementados no código atual:

  * `date(format)` — converte string para data via `strtotime` e `date(format, time)` (atenção: comportamento atual precisa atribuir o resultado à variável `$str`).
  * `number_format(decimals, decimal_sep, thousands_sep)` — wrapper para `number_format`.
  * `replace(search, replace, countFlag)` — `str_replace` (o tratamento do parâmetro `count` tem comportamento especial se for `true`).
  * `round(precision, mode)` — suporta modos por nome (`ROUND_HALF_UP`, etc.) ou valor numérico.
  * `truncate(limit, suffix)` — recorta strings via `str_truncate()`.

---

## 5. Funções utilitárias importantes

* `replaceVar($block, $global, $local)` — encontra `{var}` e `{var|filter}` no bloco e substitui, resolvendo dot-notation e aplicando `htmlspecialchars` + `processFilter`.

* `processFilter(string $str, string|null $filter, $global, $local)` — aplica filtros simples ou complexos.

* `split_args($separator, $str, $starts = "([{", $ends = ")]}")` — explode inteligente que respeita parênteses, colchetes e aspas.

* `str_truncate($str, $limit, $suffix)` — recorta string adicionando sufixo quando necessário.

* `resolveValue(string $str, $global, $local)` — interpreta literais (strings entre aspas), arrays literais (`[...]`), números, booleans (`true/false/null`) ou resolve variáveis via `replaceVar`.

* `trim_once($str, $start, $end = '')` — remove o caractere inicial/final se coincidir.

* `showRunTimeExcept($message)` — lança e imprime uma `RuntimeException` com rastreamento para debug (útil em desenvolvimento).

* `resolvePath(string $path, $context)` — navega em dot-notation (`path.to.value`) em arrays ou objetos.

---

## 6. ConditionParser — detalhes de avaliação

* `tokenizer()` produz tokens: `(`, `)`, `&&`, `||`, `!` e nós `comparison` (que carregam `left`, `op`, `right`).
* `parseCondition()` monta uma estrutura com `and`/`or` e nós `not` quando apropriado.
* `evaluateNode(Node $node, $global, $local)` — avalia recursivamente a AST de condição; o nó `comparison` usa `resolveValue()` para ler valores à esquerda/direita e aplica o operador.

---

## 7. TemplateRenderer — comportamento de render

* O método principal é `renderNodeTo` (chamado por `render`), que usa um callable (`$writer`) para emitir pedaços do output. Isso é uma técnica de streaming (em vez de concatenar uma string enorme) e permite otimizações.
* **Otimização** `for/foreach`: `childrenAreStaticText` verifica se o conteúdo do loop é composto apenas por texto sem placeholders (`{}`). Se for, o bloco é renderizado uma única vez e repetido `N` vezes com `str_repeat`, evitando a recursão do renderNodeTo em cada iteração.
* **Condicionais**: O tratamento de `if/elseif/else` itera os `dependents` para encontrar a primeira condição verdadeira, renderizando seu conteúdo e parando.
---

## 8. NodeDebugger — uso

* `NodeDebugger::dump($root)` — imprime texto bruto (CLI/browser).
* `NodeDebugger::toHtml($root, $opts)` — imprime HTML com a árvore dentro de `<pre>` (opções: `maxDepth`, `trimText`, `showEmpty`).

Exemplo de uso na aplicação:

```php
$template = new Template("exemplo.tpl");
$template->debug($data);
```

---

# 9. CacheManager — sistema de cache
A classe `CacheManager` gerencia o cache da árvore de parsing e do template renderizado final.

* **Localização:** Os caches são salvos em subpastas (`templates`, `parsers`) dentro do diretório `$cachedir` (passado para `Template`).

* **Tipos de Cache:**

  * `parsers`: Guarda a AST (`TemplateParser` ou subárvores) baseada no conteúdo do template.

  * `templates`: Guarda o template renderizado final em string.

* **Validação (Hash):** O cache é validado por um `hash_hmac("sha256", $inputs, $secret)`.

  * Cache de parsers usa o conteúdo do template como `$inputs`.

  * Cache de templates usa o conteúdo do template e os dados (`$data`) como `$inputs`.

* Funções:

  * `setCache($filename, $data, $inputs, $type, $secret)` — Serializa, comprime (`gzencode`), codifica (`base64`) e salva o cache com o hash.

  * `getCache($filename, $inputs, $type, $secret)` — Recupera, verifica o hash, e deserializa (`unserialize`). Se o hash falhar, o cache é deletado (`dellCache`).

  * `dellCache($filename, $type)` — Remove o arquivo de cache.

---

## 10. API pública — classe Template

* `new Template(string $name, string $secret, string $cachedir, string $envdir)` — Construtor que lê o arquivo `$name`, define o `$secret` para o cache, o diretório `$cachedir` e carrega variáveis de ambiente de `$envdir`.
* `render(array $data)` — Tenta obter o template renderizado do cache (`templates`). Se falhar, tenta obter a AST do cache (`parsers`). Se falhar, faz o parsing, renderiza o resultado e salva ambos os caches, exibindo o resultado com `echo`.
* `debug(array $data)` — Tenta obter a AST do cache (`parsers`). Se falhar, faz o parsing. Em seguida, mostra a árvore com `NodeDebugger::toHtml()`.

---

## 10. Exemplos rápidos

Template (`exemplo.tpl`):

```html
<h1>Olá, {nome}!</h1>
[if usuario.idade >= 18 {
  <p>Maior de idade</p>
} else {
  <p>Menor de idade</p>
}]
```

Render (PHP):

```php
// Assumindo que estas constantes estão definidas ou carregadas no .env
$secret = 'minha_chave_secreta'; 
$cachedir = __DIR__ . '/cache'; 
$envdir = __DIR__ . '/.env';

$t = new Template('exemplo.tpl', $secret, $cachedir, $envdir);
$t->render(['nome' => 'Rian', 'usuario' => ['idade' => 25]]);
```
---

## Licença

```
License: Apache 2.0
```

---

## 12. Futuras extensões

1. Estrutua SOLID.
2. Sistema de salvar variáveis em contexto `global`.
3. Sistema de realizar operações
4. Sistema de string randômica
