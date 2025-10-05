<?php
require_once __DIR__ . "/../Utils/helpers.php";

class ConditionParser
{
  private $cond; //html do template original
  public $root; //Arvore de hierarquia de comandos e condições

  public function __construct(string $string)
  {
    $this->cond = $string;
    $tokens = $this->tokenizer($string);
    $this->root =  $this->parseCondition($tokens);
  }
  private function parseCondition(array $tokens)
  {
    $root = new Node("root", []);

    while (count($tokens) > 0) {
      $tok = array_shift($tokens);

      if ($tok === '(') {
        $root->content[] = $this->parseCondition($tokens);
        continue;
      }

      if ($tok === ')') {
        break;
      }

      if ($tok === '&&' || $tok === '||') {
        $root->content[] = $tok;
        continue;
      }
      if ($tok === '!') {
        // pega próximo token e cria NOT
        $next = array_shift($tokens);
        if (!$next) throw new \RuntimeException("NOT sem expressão");
        if ($next === '(') {
          $expr = $this->parseCondition($tokens);
        } /*else {
          $expr = $next;
        }*/
        $node = new Node('not', [], ['expr' => $expr]);
        $root->content[] = $node;
        continue;
      }
      if ($tok instanceof Node) {
        $root->content[] = $tok;
        continue;
      }
    }
    while (($idx = array_search('&&', $root->content, true)) !== false) {
      $left = $root->content[$idx - 1] ?? null;
      $right = $root->content[$idx + 1] ?? null;
      $new = new Node('and');
      $new->params['left'] = $left;
      $new->params['right'] = $right;
      $root->content[$idx - 1] = $new;
      array_splice($root->content, $idx, 2);
    }

    while (($idx = array_search('||', $root->content, true)) !== false) {
      $left = $root->content[$idx - 1] ?? null;
      $right = $root->content[$idx + 1] ?? null;
      $new = new Node('or');
      $new->params['left'] = $left;
      $new->params['right'] = $right;
      $root->content[$idx - 1] = $new;
      array_splice($root->content, $idx, 2);
    }
    while (($idx = array_search('!', $root->content, true)) !== false) {
      $right = $root->content[$idx + 1] ?? null;
      $new = new Node('not');
      $new->params['expr'] = $right;
      $root->content[$idx] = $new;
      array_splice($root->content, $idx + 1, 1);
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

  private function tokenizer(string $condition)
  {
    $condition = preg_replace('/^\x{FEFF}+/u', '', $condition); // remove BOM UTF-8
    $condition = str_replace("\xC2\xA0", ' ', $condition); // NBSP -> space

    $len = strlen($condition);
    $i = 0;
    $out = [];

    // regex para comparações (sem o ^ porque vamos aplicá-lo na substr)
    $cmpRegex = '/^
            (
                [A-Za-z_]\w*(?:\.[A-Za-z_0-9]\w*)*   # variável
                | \'(?:\\\\\'|[^\'])*\'             # string simples
                | "(?:\\\\\"|[^"])*"                # string dupla
                | \d+(?:\.\d+)?                     # número
            )
            \s*(==|=|!=|===|!==|>=|<=|>|<|\(|\))\s*
            (
                [A-Za-z_]\w*(?:\.[A-Za-z_0-9]\w*)*
                | \'(?:\\\\\'|[^\'])*\'
                | "(?:\\\\\"|[^"])*"
                | \d+(?:\.\d+)?
            )
        /x';


    while ($i < $len) {
      // pula espaços
      if (preg_match('/^\s+/A', substr($condition, $i), $m)) {
        $i += strlen($m[0]);
        continue;
      }

      $rest = substr($condition, $i);

      // parênteses
      if ($rest[0] === '(') {
        $out[] = '(';
        $i++;
        continue;
      }
      if ($rest[0] === ')') {
        $out[] = ')';
        $i++;
        continue;
      }

      // operadores lógicos longos
      if (substr($rest, 0, 2) === '&&') {
        $out[] = '&&';
        $i += 2;
        continue;
      } else if (substr($rest, 0, 2) === '||') {
        $out[] = '||';
        $i += 2;
        continue;
      } else if ($rest[0] === '!') {
        // operador NOT unário
        $out[] = '!';
        $i++;
        continue;
      }


      // tenta comparação
      if (preg_match($cmpRegex, $rest, $mm)) {
        $node = new Node('comparison', []);
        $node->params['left'] = $mm[1];
        $node->params['op']   = $mm[2];
        $node->params['right'] = $mm[3];
        $out[] = $node;
        $i += strlen($mm[0]);
        continue;
      }

      // se nada bateu: token inválido — devolve erro detalhado (útil pra dev)
      throw new \RuntimeException("ConditionParser tokenizer: token inválido em: " . substr($condition, $i, 50));
    }

    return $out;
  }

  public static function evaluateNode(Node $node, $global, $local = [])
  {

    // Segurança: caso recebamos um nó literal criado como fallback
    if ($node->type === 'literal') {
      return (bool)($node->params['value'] ?? false);
    } else if ($node->type === 'comparison') {
      $leftRaw  = $node->params['left'] ?? '';
      $rightRaw = $node->params['right'] ?? '';
      $opRaw    = $node->params['op'] ?? '';

      $leftVal = resolveValue($rightRaw, $global, $local);

      $rightVal = resolveValue($rightRaw, $global, $local);

      $op = trim((string)$opRaw);

      if ($op === '==' || $op === '=') return $leftVal == $rightVal;
      elseif ($op === '===') return $leftVal === $rightVal;
      elseif ($op === '!=') return $leftVal != $rightVal;
      elseif ($op === '!==') return $leftVal !== $rightVal;
      elseif ($op === '>=') return $leftVal >= $rightVal;
      elseif ($op === '<=') return $leftVal <= $rightVal;
      elseif ($op === '>') return $leftVal > $rightVal;
      elseif ($op === '<') return $leftVal < $rightVal;
    }

    if ($node->type === 'and') {
      return (bool)self::evaluateNode($node->params["left"], $global, $local) && (bool)self::evaluateNode($node->params["right"], $global, $local);
    } else if ($node->type === 'or') {
      return (bool)self::evaluateNode($node->params["left"], $global, $local) || (bool)self::evaluateNode($node->params["right"], $global, $local);
    } else if ($node->type === 'not') {
      return ! (bool) self::evaluateNode($node->params["expr"], $global, $local);
    }

    return false;
  }
}
