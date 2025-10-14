<?php

require_once __DIR__ . "/../core/node.class.php";
require_once __DIR__ . "/../parser/condition_parser.class.php";

class TemplateRenderer
{

  public function render($root, array $global = [], $local = []): string
  {
    // return self::renderNode($root, $global, $local);
    $pieces = [];
    $this->renderNodeTo($root, function ($chunk) use (&$pieces) {
      $pieces[] = $chunk;
    }, $global, $local);
    return implode('', $pieces);
  }

  private static function renderNodeTo(Node $node, callable $writer, array $global, $local = [])
  {
    $type = $node->type;
    $children = (is_array($node->content)) ? $node->content : [];

    if ($type === "text") {

      $writer(replaceVar((string)$node->content, $global, $local));
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
        // NOTE: replaceVar no texto estático só depende de $global/$local (mesmo para todas iterações)
        $single = replaceVar($staticText, $global, $local);
        // chunk grande aqui — mas é rápido
        $writer(str_repeat($single, $times));
        return;
      }

      for ($i = 0; $i < $times; $i++) {
        $localCtx = $local;
        // você pode opcionalmente injetar uma variável de iteração no contexto:
        $localCtx['loop_index'] = $i;
        foreach ($children as $child) self::renderNodeTo($child, $writer, $global, $localCtx);
        unset($local['loop_index']);
      }
      return;
    } else if ($node->type === "foreach") {
      $listName = $node->params["listname"] ?? null;
      if ($listName === null) return;

      $list = resolvePath($listName, $global);
      if (!is_iterable($list)) return; // nada pra iterar


      $staticText = self::childrenAreStaticText($children);
      if ($staticText !== false) {
        // só útil se o conteúdo a ser repetido não depende de item/key/loop_index
        $single = replaceVar($staticText, $global, $local);
        // mas se dependesse de item/key, staticText teria { } e não seria static

        $times = count($list);
        $total = str_repeat($single, $times); // para listas enormes, streaming abaixo é melhor
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

        // cleanup
        if (isset($node->params["item"]) && $node->params["item"] !== null) unset($local[$node->params["item"]]);
        if (isset($node->params["key"]) && $node->params["key"] !== null) unset($local[$node->params["key"]]);
        unset($local['loop_index']);

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
      $str = resolveValue($str, $global, $local);

      $filters = (isset($node->params["filters"])) ? (array) $node->params["filters"] : [];

      foreach ($filters as $f) {
        $str = processFilter($str, $f, $global, $local);
      }
      $writer($str);
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

  /* Versão antiga menos otimizada
  private static function renderNode(Node $node, array $global, $local = []): string
  {
    $out = '';
    $children = (is_array($node->content)) ? $node->content : [];

    if ($node->type === "text") {

      return replaceVar((string)$node->content, $global, $local);
    }

    if ($node->type === "root") {

      foreach ($children as $child) $out .= self::renderNode($child, $global, $local);
      return $out;
    } else if ($node->type === "for") {
      $times = (int)($node->params["times"] ?? 0);
      for ($i = 0; $i < $times; $i++) {
        $localCtx = $local;
        // você pode opcionalmente injetar uma variável de iteração no contexto:
        $localCtx['loop_index'] = $i;
        foreach ($children as $child) $out .= self::renderNode($child, $global, $localCtx);
      }
      return $out;
    } else if ($node->type === "foreach") {
      $listName = $node->params["listname"] ?? null;
      $list = $global[$listName] ?? null;
      if (!is_iterable($list)) return ''; // nada pra iterar

      $idx = 0;
      foreach ($list as $k => $v) {
        // novo contexto: copia do atual, injeta locais (item e key)
        $localCtx = $local;
        if ($node->params["item"] !== null) {
          $localCtx[$node->params["item"]] = $v;
        }
        if ($node->params["key"] !== null) {
          $localCtx[$node->params["key"]] = $k;
        }
        $localCtx['loop_index'] = $idx;
        foreach ($children as $child) $out .= self::renderNode($child, $global, $localCtx);
        $idx++;
      }
      return $out;
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
            $out .= self::renderNode($child, $global, $local);
          }
          return $out;
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
                $out .= self::renderNode($child, $global, $local);
              }
              break;
            }
            // se false, continua para o próximo dependent
          } elseif ($dep->type === 'else') {
            // else não tem condição: renderiza e pára
            foreach ($dep->content as $child) {
              $out .= self::renderNode($child, $global, $local);
            }
            break;
          }
        }
      }

      return $out;
    } else if ($node->type === "str_filter") {
      $str = (isset($node->params["str"])) ? trim((string)$node->params["str"]) : '';
      $str = resolveValue($str, $global, $local);

      $filters = (isset($node->params["filters"])) ? (array) $node->params["filters"] : [];

      foreach ($filters as $f) {
        $str = processFilter($str, $f, $global, $local);
      }

      return $str;
    }


    if ($node->type === "else") {
      foreach ($children as $child) $out .= self::renderNode($child, $global, $local);
      return $out;
    }

    foreach ($children as $child) $out .= self::renderNode($child, $global, $local);
    return $out;
  }*/
}
