## 📄 TemplateEngine — Documentação Detalhada do Motor (PHP)

**Resumo:** Este documento descreve a arquitetura e os componentes internos do **TemplateEngine**, um motor de templates em PHP contido no arquivo `TemplateEngine.min.php`. Ele abrange o processo de *parsing*, a estrutura da Árvore de Sintaxe Abstrata (**AST**), a avaliação de condições e expressões matemáticas, o sistema de *cache*, e a interface de renderização.

-----

## 1\. ⚙️ Visão Geral e Fluxo de Execução

O motor opera através de um pipeline de três estágios principais:

1.  **Carregamento e Configuração:** A classe `Template` carrega o arquivo de template e configura o contexto (dados, chave secreta, diretórios de cache).
2.  **Análise (*Parsing*):** O `TemplateParser` transforma o código do template em uma **Árvore de Sintaxe Abstrata (AST)**, estruturando-o em objetos `Node`.
3.  **Renderização (*Rendering*):** O `TemplateRenderer` percorre a AST, resolve variáveis, executa lógicas de controle (loops/condicionais) e expressões, e gera a saída final (HTML). O `CacheManager` otimiza todo o processo, armazenando a AST e o HTML final.

$$\text{Template File} \xrightarrow{\text{Template Class/Cache}} \text{TemplateParser (AST)} \xrightarrow{\text{TemplateRenderer}} \text{HTML Output}$$

-----

## 2\. 🧩 Conceitos e Componentes Principais

### 2.1. Classe `Node` (AST Base)

É a entidade fundamental que compõe a Árvore de Sintaxe Abstrata.

| Propriedade | Descrição |
| :--- | :--- |
| `type` | O tipo do nó (ex: `'root'`, `'text'`, `'var'`, `'if'`, `'foreach'`, `'math'`, `'define'`, etc.). |
| `content` | O corpo do nó. Para nós de estrutura, é um **array de `Node`s filhos**. Para nós de texto/variável, é uma **string**. |
| `params` | Parâmetros de configuração específicos (ex: `condition`, `listname`, `item`, `expression`). |
| `dependents` | Armazena nós relacionados que alteram o fluxo, como `elseif` ou `else` que dependem de um nó `if`. |

### 2.2. Contextos de Dados

O motor gerencia dois níveis de dados durante a renderização:

  * **Global (`$global`):** O array de dados principal passado para `Template::render($data)`. É o contexto mais amplo.
  * **Local (`$local`):** Um array temporário que armazena dados injetados por estruturas de controle, como a variável `$item` ou `$key` em um `foreach`, ou a variável `$loop_index` em *loops*.

### 2.3. Módulos de Parsing e Avaliação

| Módulo | Função | Detalhes |
| :--- | :--- | :--- |
| `TemplateParser` | Constrói a AST principal a partir do template, usando *regex* para identificar *tokens* e um *stack* para a hierarquia. Lida com todas as tags de estrutura e variáveis. | Suporta tags como `[if]`, `[foreach]`, `[for]`, `{require}`, `{Define}`, `{Math}`, etc. |
| `ConditionParser` | Analisa e avalia expressões lógicas em *tokens*, parênteses e operadores (`==`, `!=`, `&&`, `||`, `!`), e constrói uma mini-AST de condição. | Chamado pelo `TemplateRenderer` quando encontra um nó `if`/`elseif`. |
| `MathParser` | Analisa e avalia expressões matemáticas em *tokens* e resolve a ordem de precedência dos operadores. | Chamado quando o nó é do tipo `math`. |
| `AssignmentParser` | Analisa e executa a atribuição de valores (`{Define($var = value)}`) no contexto **local**. | Suporta literais, variáveis e caminhos de ponto para atribuição. |

-----

## 3\. 📝 Sintaxe do Template (Tags Suportadas)

O motor utiliza duas sintaxes de *tags*: chaves `{}` para variáveis e comandos de uma linha, e colchetes `[]` com chaves `{}` para blocos de estrutura.

### 3.1 Variáveis e Filtros

Variáveis são resolvidas com a função **`replaceVar`** e acessam dados usando a **notação de ponto** (*dot notation*).

  * **Exemplo:** `{usuario.endereco.cidade}`
  * **Filtros:** Podem ser aplicados com o *pipe* (`|`). Ex: `{nome|upper}`, `{data|date('Y-m-d')}`.
  * **Segurança:** Todas as variáveis renderizadas passam por **`htmlspecialchars`** (com `ENT_QUOTES|ENT_SUBSTITUTE`, UTF-8) para prevenir ataques XSS, a menos que um filtro como `|raw` seja implementado para sobrescrever esse comportamento (não implementado explicitamente no código fornecido).

### 3.2 Estruturas de Controle

| Estrutura | Sintaxe (Exemplo) | Variáveis Locais |
| :--- | :--- | :--- |
| **Condicional** | `[if cond { ... } elseif cond { ... } else { ... }]` | Nenhuma |
| **Foreach** | `[foreach $listname as $key => $item { ... }]` | `$item`, `$key`, `$loop_index` (0-based) |
| **For** | `[for $times { ... }]` | `$loop_index` (0-based) |

### 3.3 Comandos Úteis

| Comando | Sintaxe (Exemplo) | Função |
| :--- | :--- | :--- |
| **Inclusão** | `{require('header.tpl')}` | Inclui e analisa um sub-template (usa `TemplateParser::requireParser`). |
| **Atribuição** | `{Define($var = 100)}` | Define uma variável no contexto local. |
| **Matemática** | `{Math(5 + $var * 2)}` | Calcula e exibe o resultado da expressão. |
| **Filtro Avançado** | `{str_filter($var, (trim, upper))}` | Aplica múltiplos filtros a uma variável ou string literal. |

-----

## 4\. 🛠️ Funções Utilitárias e Resolução de Valores

O código se baseia em um conjunto de funções globais para manipulação de dados:

  * **`replaceVar()`**: Resolve o valor de uma variável (`$var`), navegando por *dot notation* e aplicando o **`processFilter()`** opcional.
  * **`processFilter()`**: Recebe uma string e aplica um filtro. Suporta filtros simples (como `upper`, `strip_tags`) e filtros complexos com argumentos (como `date(format)`, `number_format(dec, sep, thousands)`).
  * **`resolveValue()`**: Funçao chave para interpretação de *tokens*. Determina se uma string de entrada é uma **literal** (string, número, boolean) ou uma **variável**, retornando o valor correto.
  * **`resolvePath()`**: Navega em profundidade em arrays ou objetos PHP usando uma string de caminho de ponto (ex: `"usuario.email"`).
  * **`split_args()`**: Utilizado pelo *parser* para dividir argumentos de funções/filtros (ex: `date('Y-m-d')`) sem quebrar ao encontrar vírgulas dentro de aspas ou parênteses.

-----

## 5\. 🚀 TemplateRenderer — Otimização e Comportamento

O `TemplateRenderer` é responsável por gerar o output final.

  * **Método `renderNodeTo`**: Percorre a AST. Para nós de texto, envia o conteúdo. Para nós lógicos, executa a avaliação antes de chamar a renderização recursiva.
  * **Otimização de Loops (Foreach/For)**: O motor verifica se o conteúdo de um *loop* (os nós filhos) é composto **apenas por texto estático** (`childrenAreStaticText`). Se for, ele renderiza o bloco uma única vez e usa **`str_repeat`** para gerar o HTML do loop N vezes, economizando o custo da recursão em cada iteração.
  * **Gerenciamento de Contexto**: Para cada iteração de *loop* ou chamada de bloco, ele atualiza a cópia do contexto `$local` com as variáveis injetadas (ex: `$item`, `$loop_index`).

-----

## 6\. 💾 CacheManager — Sistema de Cache

A classe `CacheManager` implementa um sistema de *cache* de dois níveis para otimizar a performance.

| Tipo de Cache | Conteúdo Armazenado | Fator de Validação (Hash) | Benefício |
| :--- | :--- | :--- | :--- |
| **`parsers`** | **AST** (Árvore de `Node`s) serializada. | Conteúdo do arquivo de template. | Evita o custoso processo de *parsing* se o template não mudar. |
| **`templates`** | **HTML Final** (string renderizada). | Conteúdo do template **e** os dados (`$data`) fornecidos. | Evita *parsing* **e** *renderização* se nem o template nem os dados mudarem. |

**Mecanismo:**

  * O *cache* é validado usando **`hash_hmac("sha256", $inputs, $secret)`**.
  * Os dados são salvos com compressão (`gzencode`) e codificação (`base64`) após serialização (`serialize`).

-----

## 7\. 💻 API Pública e Debugging

### Classe `Template`

A interface principal para o desenvolvedor:

  * **`__construct(string $name, string $secret, string $cachedir, string $envdir)`**: Inicializa o motor, carregando o template `$name` e configurando o `CacheManager` com o `$secret` e `$cachedir`.
  * **`render(array $data)`**: O método de execução. Segue o fluxo de cache (tenta `templates`, depois `parsers`, se falhar, faz *parsing* e *renderização*), e exibe o HTML final via `echo`.
  * **`debug(array $data)`**: Executa o *parsing* (com cache de `parsers`) e exibe a AST formatada em HTML, usando a classe **`NodeDebugger`**.

### Classe `NodeDebugger`

Ferramenta estática para visualizar a estrutura da AST:

  * **`NodeDebugger::toHtml($node, $opts)`**: Renderiza a estrutura da AST dentro de uma *tag* `<pre>` HTML para facilitar a leitura no navegador.

-----

## Exemplo de Uso (PHP)

```php
// Instancia o motor de templates
$t = new Template(
    'caminho/para/meu_template.tpl', 
    'minha_chave_secreta_aqui', 
    '/tmp/cache_templates', 
    '/caminho/.env'
);

// Dados a serem injetados no template
$data = [
    'titulo' => 'Relatório Mensal',
    'produtos' => [
        ['nome' => 'Caneta', 'preco' => 2.50],
        ['nome' => 'Caderno', 'preco' => 15.90]
    ]
];

// Renderiza e exibe o template
$t->render($data);

// Para depurar a AST
// $t->debug($data); 
```

-----