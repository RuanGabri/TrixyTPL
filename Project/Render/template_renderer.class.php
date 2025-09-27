<?php
require_once "node/node.class.php";
require_once "parsers/condition_parser.class.php";

class TemplateRenderer
{

  public function render($root, array $global = [], $local = []): string
  {
    return self::renderNode($root, $global, $local);
  }

  private static function renderNode($node, array $global, $local = []): string
  {

    $out = '';
    $children = (is_array($node->content)) ? $node->content : [];

    if ($node->type === "text") {

      return self::replaceVar((string)$node->content, $global, $local);
    }

    if ($node->type === "root") {

      foreach ($children as $child) $out .= self::renderNode($child, $global, $local);
      return $out;
    }
    if ($node->type === "for") {
      $times = (int)($node->params["times"] ?? 0);
      for ($i = 0; $i < $times; $i++) {
        $localCtx = $local;
        // você pode opcionalmente injetar uma variável de iteração no contexto:
        $localCtx['loop_index'] = $i;
        foreach ($children as $child) $out .= self::renderNode($child, $global, $localCtx);
      }
      return $out;
    }
    if ($node->type === "foreach") {
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
    }
    if ($node->type === "if" || $node->type === "elseif") {
      // $conditionStr = (string)($node->params['condition'] ?? '');
      $dependents = $node->dependents ?? [];

      // // parse da condição (ConditionParser retorna uma AST)
      // $condParser = new ConditionParser($conditionStr);
      // $condRoot = $condParser->root;
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
    }

    if ($node->type === "else") {
      foreach ($children as $child) $out .= self::renderNode($child, $global, $local);
      return $out;
    }

    foreach ($children as $child) $out .= self::renderNode($child, $global, $local);
    return $out;
  }

  public static function replaceVar($block, $global, $local = [])
  {

    return preg_replace_callback(
      "/\{\s*
      (?P<var>[a-zA-Z_]\w*  (?:\.[a-zA-Z_0-9]\w*)*)
      \s*\}/xs",
      function ($m) use ($global, $local) {
        $var = $m["var"];
        $data = $global ?? '';
        $return = '';

        $path = explode(".", $var);
        $first = array_shift($path);

        if (is_array($local)) $data = (array_key_exists($first, $local)) ?
          $local[$first] : ($global[$first] ?? '');

        $index = $data;
        foreach ($path as $i) {
          if (is_array($index) && array_key_exists($i, $index)) {

            $index = $index[$i];
          } else if ($index instanceof ArrayAccess && $index->offsetExists($i)) {

            $index = $index[$i];
          } else if (
            is_object($index) &&
            (property_exists($index, $i) || isset($index->$i))
          ) {

            $index = $index->$i;
          } else {

            $index = null;
            break;
          }
        }
        $return = (isset($index)) ? $index : '';

        return htmlspecialchars((string)$return, ENT_QUOTES, 'UTF-8');
      },
      $block
    );
  }
}
