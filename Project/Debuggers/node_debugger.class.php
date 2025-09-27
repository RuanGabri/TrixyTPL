<?php

/**
 * NodeDebugger
 * Impressão organizada de uma árvore de Node.
 *
 * Uso:
 *   NodeDebugger::dump($root);           // imprime em stdout (CLI / browser)
 *   echo NodeDebugger::toHtml($root);    // retorna HTML (use em página)
 *
 * Recursos:
 * - evita recursão infinita usando spl_object_hash
 * - imprime params (json), content (texto ou nós) e dependents
 * - controla profundidade máxima
 */

class NodeDebugger
{
  /**
   * Dump simples: imprime direto (texto). Opções:
   *  - maxDepth: int
   *  - trimText: int (limita tamanho do texto mostrado)
   */
  public static function dump($node, array $opts = [])
  {
    echo self::renderText($node, $opts);
  }

  /** Retorna HTML com <pre> pronto para exibir na página */
  public static function toHtml($node, array $opts = [])
  {
    $out = self::renderText($node, $opts);
    echo '<pre style="background:#f7f7f7;padding:12px;border-radius:6px;overflow:auto;">' .
      htmlspecialchars($out, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') .
      '</pre>';
  }

  /** Renderiza a árvore como texto */
  public static function renderText($node, array $opts = []): string
  {
    $maxDepth = $opts['maxDepth'] ?? 20;
    $trimText = $opts['trimText'] ?? 160;
    $showEmpty = $opts['showEmpty'] ?? false;

    $visited = [];
    return self::renderNode($node, '', true, 0, $maxDepth, $trimText, $showEmpty, $visited);
  }

  /** Função recursiva que monta a string */
  private static function renderNode($node, string $prefix, bool $isLast, int $depth, int $maxDepth, int $trimText, bool $showEmpty, array &$visited): string
  {
    // cabeçalho
    $line = '';
    $branch = $depth === 0 ? '' : ($isLast ? '└─ ' : '├─ ');
    $line .= $prefix . $branch;

    // proteção contra tipos inválidos
    if (!is_object($node) || !method_exists($node, 'type') && !property_exists($node, 'type') && !isset($node->type)) {
      $line .= "[non-Node or invalid] " . gettype($node) . PHP_EOL;
      return $line;
    }

    // ciclo/visitação
    $id = is_object($node) ? spl_object_hash($node) : md5(serialize($node));
    if (isset($visited[$id])) {
      $line .= "{$node->type} (ALREADY VISITED) [id={$id}]" . PHP_EOL;
      return $line;
    }
    $visited[$id] = true;

    // Cabeçalho do nó com params resumidos
    $params = !empty($node->params) ? json_encode($node->params, JSON_UNESCAPED_UNICODE) : '';
    $line .= "Node(type={$node->type}" . ($params !== '' ? " params={$params}" : "") . ")";

    $line .= PHP_EOL;

    // parar se excedeu profundidade
    if ($depth >= $maxDepth) {
      $line .= $prefix . ($isLast ? '    ' : '│   ') . "... max depth reached ..." . PHP_EOL;
      return $line;
    }

    // mostra conteúdo (pode ser string ou array)
    $children = $node->content;
    if (is_string($children) && trim($children) !== '') {
      $txt = trim($children);
      if (mb_strlen($txt) > $trimText) $txt = mb_substr($txt, 0, $trimText) . '…';
      $line .= $prefix . ($isLast ? '    ' : '│   ') . "content (text): " . self::reprText($txt) . PHP_EOL;
    } elseif (is_array($children) && count($children) > 0) {
      $line .= $prefix . ($isLast ? '    ' : '│   ') . "content:" . PHP_EOL;
      $count = count($children);
      $i = 0;
      foreach ($children as $k => $child) {
        $i++;
        $childIsLast = ($i === $count) && (empty($node->dependents));
        $subPrefix = $prefix . ($isLast ? '    ' : '│   ');
        // label do filho: índice/posição
        $label = is_int($k) ? "#{$k}" : (string)$k;
        // se for Node, recursão
        if ($child instanceof Node) {
          // mostrar uma linha de identificação para o filho (com label)
          $line .= $subPrefix . ($childIsLast ? '└─ ' : '├─ ') . "[$label] ";
          // recursão: chamamos renderNode para o filho (sem repetir o label)
          // porém precisamos ajustar o prefix e isLast: passamos subPrefix e childIsLast
          $line .= self::renderNode($child, $subPrefix, $childIsLast, $depth + 1, $maxDepth, $trimText, $showEmpty, $visited);
        } else {
          // filho texto/valor simples
          $val = is_scalar($child) ? (string)$child : gettype($child);
          if (mb_strlen($val) > $trimText) $val = mb_substr($val, 0, $trimText) . '…';
          $line .= $subPrefix . ($childIsLast ? '└─ ' : '├─ ') . "[$label] " . self::reprText($val) . PHP_EOL;
        }
      }
    } else {
      // content vazio (array vazio ou null)
      if ($showEmpty) $line .= $prefix . ($isLast ? '    ' : '│   ') . "content: (empty)" . PHP_EOL;
    }

    // dependents (elseif/else)
    if (!empty($node->dependents) && is_array($node->dependents)) {
      $line .= $prefix . ($isLast ? '    ' : '│   ') . "dependents:" . PHP_EOL;
      $count = count($node->dependents);
      $i = 0;
      foreach ($node->dependents as $dep) {
        $i++;
        $depIsLast = ($i === $count);
        $subPrefix = $prefix . ($isLast ? '    ' : '│   ');
        if ($dep instanceof Node) {
          $line .= self::renderNode($dep, $subPrefix, $depIsLast, $depth + 1, $maxDepth, $trimText, $showEmpty, $visited);
        } else {
          $line .= $subPrefix . ($depIsLast ? '└─ ' : '├─ ') . self::reprText((string)$dep) . PHP_EOL;
        }
      }
    }

    return $line;
  }

  private static function reprText(string $s): string
  {
    // sanear novas linhas e tabs pra exibição em uma linha
    $s2 = str_replace(["\r\n", "\n", "\r", "\t"], ['\n', '\n', '\n', '\t'], $s);
    // mostra com aspas se for texto
    return '"' . $s2 . '"';
  }
}
