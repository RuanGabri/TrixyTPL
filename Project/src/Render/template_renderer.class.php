<?php

require_once __DIR__ . "/../core/node.class.php";
require_once __DIR__ . "/../parser/condition_parser.class.php";
require_once __DIR__ . "/../parser/math_parser.class.php";
require_once __DIR__ . "/../parser/assignment_parser.class.php";

class TemplateRenderer
{

  public function render($root, array $global, &$local = []): string
  {
    // return self::renderNode($root, $global, $local);
    $pieces = [];
    $this->renderNodeTo($root, function ($chunk) use (&$pieces) {
      $pieces[] = $chunk;
    }, $global, $local);
    return implode('', $pieces);
  }

  private static function renderNodeTo(Node $node, callable $writer, array|object $global, array|object &$local = [])
  {
    $mathparser = null;
    $assignparser = null;
    $type = $node->type;
    $children = (is_array($node->content)) ? $node->content : [];

    if ($type === "text") {
      $writer($node->content);
      return;
    }
    if ($node->type === "root") {

      foreach ($children as $child) self::renderNodeTo($child, $writer, $global, $local);
      return;
    } else if ($type === "for") {
      $times = ($node->params["times"] ?? 0);
      $times = resolveValue($times, $global, $local);

      if ($times <= 0) return;

      $staticText = self::childrenAreStaticText($children);

      if ($staticText !== false) {

        // podemos repetir o texto N vezes sem executar renderNode para cada iteração
        // chunk grande aqui — mas é rápido
        $writer(str_repeat($staticText, $times));
        return;
      }

      for ($i = 0; $i < $times; $i++) {
        $localCtx = $local;
        // você pode opcionalmente injetar uma variável de iteração no contexto:
        $localCtx['loop_index'] = $i;
        foreach ($children as $child) self::renderNodeTo($child, $writer, $global, $localCtx);
        if (array_key_exists('loop_index', (array)$localCtx)) {
          unset($localCtx['loop_index']);
        }

        // persiste alterações feitas dentro da iteração para o contexto externo
        $local = $localCtx;
      }
      return;
    } else if ($node->type === "foreach") {
      $listName = $node->params["listname"] ?? null;
      if ($listName === null) return;

      $list = resolvePath($listName, $global);
      if (!is_iterable($list)) return; // nada pra iterar


      $staticText = self::childrenAreStaticText($children);
      if ($staticText !== false) {

        $times = count($list);
        $total = str_repeat($staticText, $times); // para listas enormes, streaming abaixo é melhor
        $writer($total);
        return;
      }

      $idx = 0;
      $localCtx = $local;
      foreach ($list as $k => $v) {
        if (isset($node->params["item"]) && $node->params["item"] !== null) {
          $localCtx[$node->params["item"]] = $v;
        }
        if (isset($node->params["key"]) && $node->params["key"] !== null) {
          $localCtx[$node->params["key"]] = $k;
        }
        $localCtx['loop_index'] = $idx;
        foreach ($children as $child) {
          self::renderNodeTo($child, $writer, $global, $localCtx);
        }

        // cleanup apenas na cópia
        if (isset($node->params["item"]) && $node->params["item"] !== null) unset($localCtx[$node->params["item"]]);
        if (isset($node->params["key"]) && $node->params["key"] !== null) unset($localCtx[$node->params["key"]]);
        if (array_key_exists('loop_index', (array)$localCtx)) unset($localCtx['loop_index']);

        // persiste alterações da iteração
        $local = $localCtx;

        $idx++;
      }

      return;
    } else if ($node->type === "if" || $node->type === "elseif") {

      $dependents = $node->dependents ?? [];

      $condRoot = $node->params['condNode'] ?? null;
      $condVal = false;
      if ($condRoot instanceof Node) {
        $condVal = ConditionParser::evaluateNode($condRoot, $global, $local);
      }
      if ($condVal) {
        // se a condição do if atual for verdadeira, renderiza seus filhos
        if (ConditionParser::evaluateNode($condRoot, $global, $local)) {
          foreach ($children as $child) {
            self::renderNodeTo($child, $writer, $global, $local);
          }
          return;
        }
      } // se o if foi falso, testa os dependents (elseif / else) em ordem
      if (!empty($dependents) && is_array($dependents)) {
        foreach ($dependents as $dep) {
          if (!($dep instanceof Node)) continue;

          if ($dep->type === 'elseif') {
            // $cstr = (string)($dep->params['condition'] ?? '');
            // $cp = new ConditionParser($cstr);
            $cp = $dep->params['condNode'] ?? null;
            if (ConditionParser::evaluateNode($cp, $global, $local)) {
              // renderiza o conteúdo deste elseif e pára (comportamento de if/elseif)
              foreach ($dep->content as $child) {
                self::renderNodeTo($child, $writer, $global, $local);
              }
              break;
            }
            // se false, continua para o próximo dependent
          } elseif ($dep->type === 'else') {
            // else não tem condição: renderiza e pára
            foreach ($dep->content as $child) {
              self::renderNodeTo($child, $writer, $global, $local);
            }
            break;
          }
        }
      }

      return;
    } else if ($node->type === "str_filter") {
      $str = (isset($node->params["str"])) ? trim((string)$node->params["str"]) : '';

      $filters = (isset($node->params["filters"])) ? (array) $node->params["filters"] : [];
      foreach ($filters as $f) {
        $str = processFilter($str, $f, $global, $local);
      }

      $writer($str);
      return;
    } else if ($node->type === "var") {
      $str = (isset($node->params["varStr"])) ? trim((string)$node->params["varStr"]) : '';
      $filter = (isset($node->params["varFilter"])) ? trim((string)$node->params["varFilter"]) : "";
      $str = replaceVar($str, $global, $local, $filter);

      $writer($str);
      return;
    } else if ($node->type === "math") {
      $expr = (isset($node->params["expression"])) ? trim((string)$node->params["expression"]) : '';
      if (!isset($mathparser)) $mathparser = new MathParser();
      $tokens = $mathparser::tokenizer($expr);
      $node = $mathparser::parseMath($tokens);
      $str = trim($mathparser::evaluateNode($node, $global, $local));

      $writer($str);
      return;
    } else if ($node->type === "define") {
      $assign = (isset($node->params["assignment"])) ? trim((string)$node->params["assignment"]) : '';
      if (!isset($assignparser)) $assignparser = new AssignmentParser();
      $node = $assignparser::parser($assign);
      $assignparser::evaluateNode($node, $global, $local);
      return;
    } else if ($node->type === "randNum") {
      $min = (float)$node->params["min"];
      $max = (float)$node->params["max"];

      $writer(mt_rand($min, $max));
      return;
    } else if ($node->type === "randStr") {
      $len = (float)$node->params["len"];

      $writer(createToken($len));
      return;
    }


    if ($node->type === "else") {
      foreach ($children as $child) self::renderNodeTo($child, $writer, $global, $local);
      return;
    }

    foreach ($children as $child) self::renderNodeTo($child, $writer, $global, $local);
    return;
  }


  /**
   * Retorna a string concatenada se todas as children forem nós de text
   * e contém ZERO placeholders '{' (logo é estático para todas iterações).
   * Caso contrário, retorna false.
   */
  private static function childrenAreStaticText(array $children)
  {
    if (count($children) === 0) return '';
    $buf = '';
    foreach ($children as $c) {
      if (!($c instanceof Node) || $c->type !== 'text') return false;
      $txt = (string)$c->content;
      if (strpos($txt, '{') !== false) return false; // tem variável -> não estático
      $buf .= $txt;
    }
    return $buf;
  }

  public function renderTo($root, callable $writer, array $global = [], $local = []): void
  {
    $this->renderNodeTo($root, $writer, $global, $local);
  }
}
