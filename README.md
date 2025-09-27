# Template Engine – Documentação

Este sistema implementa um **parser e renderer de templates** em PHP.
Ele permite **variáveis, laços, condicionais** e **debug da árvore de nós**.

---

## 1. Estrutura geral

O fluxo básico é:

1. **Template** → carrega o arquivo de template (`.tpl` ou `.html`).
2. **TemplateParser** → transforma o HTML com marcações especiais em uma árvore de **Node**.
3. **TemplateRenderer** → percorre a árvore e renderiza o resultado substituindo variáveis e avaliando condições.
4. **ConditionParser** → usado para interpretar e avaliar expressões condicionais (`if`, `elseif`).
5. **NodeDebugger** → permite visualizar a árvore de nós para debug.

---

## 2. Sintaxe do Template

### 2.1 Variáveis

As variáveis são delimitadas por `{ ... }`:

```python
{nome}
{usuario.email}
{endereco.rua}
```

* Suporta **dot notation** para acessar subcampos (`usuario.nome`).
* O parser substitui pela variável correspondente no array `$data` passado ao `render()`.

Exemplo:

```python
Olá, {nome}! Seu email é {usuario.email}.
```

---

### 2.2 Condicionais

A sintaxe de **if/elseif/else**:

```js
[if usuario.idade >= 18 {
  Você é maior de idade.
} elseif usuario.idade >= 16 {
  Você é quase maior.
} else {
  Você é menor de idade.
}]
```

* Operadores suportados: `==`, `=`, `!=`, `!==`, `===`, `>`, `<`, `>=`, `<=`
* Conectivos: `&&`, `||`, `!`
* Parênteses `()` são aceitos para agrupar condições.

---

### 2.3 Loops

#### For

```js
[for 3 {
  Iteração: {loop_index}
}]
```

* Executa o bloco **3 vezes**.
* A variável especial `{loop_index}` está disponível.

#### Foreach

```js
[foreach usuarios as usuario {
  Nome: {usuario.nome}, Email: {usuario.email}
}]
```

Com chave e valor:

```js
[foreach pedidos as id => pedido {
  Pedido #{id}: {pedido.valor}
}]
```

---

## 3. Exemplos completos

### Exemplo 1 – Template simples

```html
<h1>Olá, {nome}!</h1>

[if usuario.idade >= 18 {
  <p>Bem-vindo(a), você pode acessar os conteúdos +18.</p>
} else {
  <p>Você ainda não tem permissão.</p>
}]
```

### Exemplo 2 – Lista

```html
<h2>Usuários:</h2>
<ul>
  [foreach usuarios as user {
    <li>{user.nome} ({user.email})</li>
  }]
</ul>
```

---

## 4. Debug

Para inspecionar a árvore de parsing:

```php
$template = new Template("exemplo.tpl");
$template->debug($data);
```

Isso renderiza uma árvore hierárquica no navegador, mostrando os `Node`s e seus parâmetros.

---

## 5. Classes principais

* **Node** → Estrutura de árvore (tipo, conteúdo, parâmetros e dependentes).
* **TemplateParser** → Constrói a árvore a partir do HTML com marcações.
* **ConditionParser** → Tokeniza e interpreta condições.
* **TemplateRenderer** → Renderiza a árvore substituindo variáveis e executando lógica.
* **NodeDebugger** → Ferramenta de debug para visualizar a árvore.

---

## 6. Observações

* Strings podem ser usadas em comparações:

  ```js
  [if usuario.status == "ativo" { ... }]
  ```
* O motor usa `htmlspecialchars` na substituição de variáveis → evita XSS.
* Loops (`for` e `foreach`) têm suporte à variável `loop_index`.

---

## 7. Futuras extensões

* **Include de templates** (`[include ...]`).
* **Custom tags** (ex: `[date format="Y-m-d"]`).
* **Filtros** para variáveis (ex: `{nome|upper}`).

---
