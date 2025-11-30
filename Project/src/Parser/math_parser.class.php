<?php

require_once __DIR__ . "/../Utils/helpers.php";

class MathParser
{

  public static  function parseMath(array &$tokens)
  {
    // 1. LÓGICA DE PRÉ-PROCESSAMENTO DE TOKENS (Multiplicação Implícita e Negação Unária)
    // Isso garante que o array $tokens seja sintaticamente correto antes de ser consumido pelo loop.
    $new_tokens = [];
    // O que consideramos um operando no parser (que pode ser seguido por um '(' para justaposição)
    $operand_ends = ['number', 'string', ')'];
    $ops = ["+", "=", "-", "*", "/"];

    // Esta variável rastreia se o token ANTERIOR foi um operando (para detectar 3(...) )
    $previous_token_is_operand = false;

    foreach ($tokens as $tok) {

      // --- INJEÇÃO DA MULTIPLICAÇÃO IMPLÍCITA (3(...) ou var(...)) ---
      // Se o token atual é '(' E o token anterior era um operando, injetamos um '*'
      if ($tok === '(' && $previous_token_is_operand) {
        $new_tokens[] = '*';
      }

      $new_tokens[] = $tok; // Adiciona o token atual

      // --- ATUALIZAÇÃO DO CONTEXTO DE OPERANDO ---
      // Um token é um operando se for número, string ou parêntese de fechamento.
      if (
        is_numeric($tok) ||
        (is_string($tok) && !in_array($tok, $ops) && $tok !== '(' && $tok !== ')') ||
        $tok === ')'
      ) {
        $previous_token_is_operand = true;
      } else {
        $previous_token_is_operand = false;
      }
    }

    $tokens = $new_tokens; // Substitui o array de tokens original pelo processado.


    // 2. CORREÇÃO DA NEGAÇÃO UNÁRIA (-5, -(...))
    // Se a expressão começar com um sinal de subtração/adição, ou tiver um após '(', 
    // injetamos '0' para transformá-lo em subtração/adição binária (0 - X).
    $i = 0;
    while ($i < count($tokens)) {
      $tok = $tokens[$i];

      // Condição para Negeção Unária:
      // 1. É o primeiro token (ex: -5)
      // 2. É um '-' ou '+' após um '(' (ex: (-x) )
      if (in_array($tok, ['-', '+'])) {
        $is_unary_start = $i === 0;
        $is_unary_after_paren = ($i > 0 && $tokens[$i - 1] === '(');

        if ($is_unary_start || $is_unary_after_paren) {
          // Injeta '0' na frente. Se for o primeiro, $i=0. Se for depois de '(', $i é a posição do operador.
          array_splice($tokens, $i, 0, '0');
          $i++; // Avança o índice para o '0' que acabou de ser inserido
        }
      }
      $i++;
    }

    // FIM DO PRÉ-PROCESSAMENTO
    $root = new Node("root", []);

    $ops = ["+", "=", "-", "*", "/"];

    while (count($tokens) > 0) {
      $tok = array_shift($tokens);

      if ($tok === '(') {
        $root->content[] = self::parseMath($tokens);
        continue;
      }

      if ($tok === ')') {
        break;
      }

      if (in_array($tok, $ops)) {
        $node = new Node("operator", [], ["value" => (string)$tok]);
        $root->content[] = $node;
      } elseif (is_numeric($tok)) {
        $node = new Node("number", [], ["value" => (float)$tok]);
        $root->content[] = $node;
      } elseif (is_string($tok)) {
        $node = new Node("string", [], ["value" => (string)$tok]);
        $root->content[] = $node;
      }

      if ($tok instanceof Node) {
        $root->content[] = $tok;
        continue;
      }
    }



    if (!empty($root->content)) {
      // 2.1. Primeira Passagem (Multiplicação/Divisão)
      $highContent = self::highParser($root->content);

      // 2.2. Segunda Passagem (Adição/Subtração)
      $lowContent = self::lowParser($highContent);

      $root->content = $lowContent;
    }

    // Se não houver nenhum conteúdo válido, devolve um nó literal (false)
    if (empty($root->content)) {
      // Node tipo 'literal' com params['value'] = false
      return new Node('literal', [], ['value' => false]);
    }

    // Se o primeiro elemento não existir por qualquer motivo, devolve false seguro
    if (!isset($root->content[0]) || $root->content[0] === null) {
      return new Node('literal', [], ['value' => false]);
    }

    return $root->content[0];
  }

  public static  function tokenizer(string $expr)
  {
    $expr = preg_replace('/^\x{FEFF}+/u', '', $expr); // remove BOM UTF-8
    $expr = str_replace("\xC2\xA0", ' ', $expr); // NBSP -> space


    $len = strlen($expr);
    $i = 0;
    $out = [];
    $last_token_was_operand = false;

    // regex para comparações (sem o ^ porque vamos aplicá-lo na substr)
    $cmpRegex = '/^
           (?P<Element>
                ' . Config::VAR_PATTERN . '   # variável
                | \'(?:\\\\\'|[^\'])*\'             # string simples
                | "(?:\\\\\"|[^"])*"                # string dupla
                | \d+(?:\.\d+)?                     # número
            )
            |(?P<Operator>\-|\/|\+|\*|\(|\))
        /x';


    while ($i < $len) {
      // pula espaços
      if (preg_match('/^\s+/A', substr($expr, $i), $m)) {
        $i += strlen($m[0]);
        continue;
      }

      $rest = substr($expr, $i);

      // --- LÓGICA DE TRATAMENTO DE SINAL NEGATIVO UNÁRIO ---
      // Se o próximo caractere for '-' ou '+', e o último token NÃO foi um operando (número, string, parêntese de fechamento),
      // ele é um sinal unário.
      if (in_array($rest[0], ['-', '+']) && !$last_token_was_operand) {

        // Tenta casar o número unário (com o sinal)
        if (preg_match('/^[\+\-]\s*(\d+(?:\.\d+)?)/A', $rest, $m)) {
          $sign = $m[0][0]; // Captura o sinal
          $number = $m[1];   // Captura o número

          // Trata o número unário como um único token de elemento
          $element = trim($sign . $number);
          $out[] = $element;

          // ATUALIZA CONTEXTO: O último token foi um operando (o número)
          $last_token_was_operand = true;
          $i += strlen($m[0]);
          continue;
        }
      }

      // parênteses
      if ($rest[0] === '(') {
        $out[] = '(';
        $i++;
        $last_token_was_operand = false;
        continue;
      }
      if ($rest[0] === ')') {
        $out[] = ')';
        $i++;
        $last_token_was_operand = false;
        continue;
      }

      // monta expressão
      if (preg_match($cmpRegex, $rest, $mm)) {

        if (isset($mm['Element']) && $mm['Element'] !== '') {
          $element = trim($mm['Element']);
          $out[] = $element;
          $last_token_was_operand = true;
        } else if (isset($mm['Operator']) && $mm['Operator'] !== '') {
          $operator = trim($mm['Operator']);
          $out[] = $operator;
          $last_token_was_operand = false;
        }
        $i += strlen($mm[0]);
        continue;
      }

      // se nada bateu: token inválido — devolve erro detalhado (útil pra dev)
      showException("ConditionParser tokenizer: token inválido em: " . substr($expr, $i, 50));
      return;
    }

    return $out;
  }
  private static function highParser(array|object $content)
  {
    $newContent = [];
    $i = 0;

    while ($i < count($content)) {
      $tok = $content[$i];

      if ($tok instanceof Node && $tok->type === 'operator' && in_array($tok->params['value'], ['*', '/'])) {

        $left = array_pop($newContent);

        // CORRIGIDO: Pega o próximo elemento ($i+1) e avança $i após a atribuição.
        $i++;
        $right = $content[$i] ?? new Node('literal', [], ['value' => 0]);

        $opNode = new Node("Math");
        $opNode->params['left'] = $left;
        $opNode->params['op'] = $tok->params['value'];
        $opNode->params['right'] = $right;

        $newContent[] = $opNode;
      } else {
        $newContent[] = $tok;
      }
      $i++;
    }
    return $newContent;
  }

  private static function lowParser(array|object $content)
  {
    $newContent = [];
    $i = 0;

    while ($i < count($content)) {
      $tok = $content[$i];
      if ($tok instanceof Node && $tok->type === 'operator' && in_array($tok->params['value'], ['+', '-'])) {

        $left = array_pop($newContent);

        // CORRIGIDO: Pega o próximo elemento ($i+1) e avança $i após a atribuição.
        $i++;
        $right = $content[$i] ?? new Node('literal', [], ['value' => 0]);

        $opNode = new Node("Math");
        $opNode->params['left'] = $left;
        $opNode->params['op'] = $tok->params['value'];
        $opNode->params['right'] = $right;

        $newContent[] = $opNode;
      } else {
        $newContent[] = $tok;
      }
      $i++;
    }
    return $newContent;
  }


  // CORREÇÃO DA AVALIAÇÃO RECURSIVA
  public static function evaluateNode(
    Node $node,
    array|object $global,
    array|object $local = []
  ) {

    // 1. CASO BASE: LITERAISE e VARIÁVEIS
    if (in_array($node->type, ['number', 'string'])) {
      $value = $node->params['value'] ?? null;
      if (is_string($value)) {
        // Se for uma string (pode ser nome de variável), resolve.
        return resolveValue($value, $global, $local);
      }
      // Se for número, retorna o valor.
      return $value;
    }


    // 2. CASO RECURSIVO: OPERAÇÕES MATEMÁTICAS
    if ($node->type == 'Math') {
      $leftRaw = $node->params['left'] ?? null;
      $rightRaw = $node->params['right'] ?? null;
      $op = $node->params['op'] ?? '';

      // Avaliação do Operando Esquerdo
      if ($leftRaw instanceof Node) {
        $leftVal = self::evaluateNode($leftRaw, $global, $local);
      } else {
        // Caso raro onde o nó não é um objeto (trata como valor/variável)
        $leftVal = resolveValue($leftRaw, $global, $local);
      }

      // Avaliação do Operando Direito
      if ($rightRaw instanceof Node) {
        $rightVal = self::evaluateNode($rightRaw, $global, $local);
      } else {
        // Caso raro onde o nó não é um objeto (trata como valor/variável)
        $rightVal = resolveValue($rightRaw, $global, $local);
      }

      // EXECUÇÃO DA OPERAÇÃO
      $isNumeric = is_numeric($leftVal) && is_numeric($rightVal);

      if ($op === '+') {
        return $isNumeric ? ($leftVal + $rightVal) : ($leftVal . $rightVal);
      } elseif ($op === '-') {
        if (!$isNumeric) {
          showException("Não é possível subtrair uma string na operação {$leftVal} - {$rightVal}");
        }
        return $leftVal - $rightVal;
      } elseif ($op === '*') {
        if (!$isNumeric) {
          showException("Não é possível multiplicar uma string na operação {$leftVal} * {$rightVal}");
        }
        return $leftVal * $rightVal;
      } elseif ($op === '/') {
        if (!$isNumeric) {
          showException("Não é possível dividir uma string na operação {$leftVal} / {$rightVal}");
        }
        return $leftVal / $rightVal;
      }
    }

    // Se o nó não for 'Math' nem um literal
    return false;
  }
}
