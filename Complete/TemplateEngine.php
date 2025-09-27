<?php class Node
{
  public $type; //tipo de conteúdo: variável, for, foreach ou condição lógica.
  public $content; // Conteúdo dele, filhos
  public $params; // paramétros que ele recebe
  public $dependents; // dependentes dele

  public function __construct($type, $content = [], $params = [], $depedents = [])
  {
    $this->type = $type;
    $this->content = $content;
    $this->params = $params;
    $this->dependents = $depedents;
  }
}
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


//Transforma html com comando em arvore em hierarquia de comandos
class TemplateParser
{

  private $html; //html do template original
  public $root; //Arvore de hierarquia de comandos e condições

  public function __construct(string $string)
  {
    $this->html = $string;
    $this->root =  $this->parse($string); //Salva o html como arvore na variavel;
  }

  private function parse(string $html)
  {
    $stack = []; //cria a arvore vazia
    $current = new Node('root', []); // cria o ponteiro do pai atual e cria primeiro nível, raiz
    $stack[] = $current; //salva a raiz na arvore

    $pattern = '/
     (?P<foreach> \[\s*foreach\s* (?P<listname>\w+) \s+as\s* (?:(?P<key>\w+)\s*=>\s*)? (?P<item>\w+) \s*{)
    | (?P<for>\[\s*for\s*(?P<times>\d+)\s*{)
    | (?P<if>\[\s*if\s*(?P<if_condition>.*?)\s*{)
    | (?P<elseif>\[\s*else\s*if\s*(?P<elseif_condition>.*?)\s*{)
    | (?P<else>\[\s*else\s*{)
    | (?P<close>}\s*\])
    /six';

    $pos = 0; //guarda a posição começando em 0.
    preg_match_all($pattern, $html, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
    // encontra todos os comandos [for] etc e seus fechamentos.

    foreach ($matches as $m) {

      $start = $m[0][1];
      $top = end($stack);

      // encontra o último NODE do tipo if|elseif imediatamente anterior,
      // ignorando nós de texto que contenham apenas whitespace.
      $prevIf = [];
      if (is_array($top->content) && count($top->content) > 0) {
        for ($i = count($top->content) - 1; $i >= 0; $i--) {
          $candidate = $top->content[$i];

          // se for nó e for if/elseif: achamos o alvo
          if ($candidate instanceof Node && ($candidate->type === 'if' || $candidate->type === 'elseif')) {
            $prevIf = $candidate;
            break;
          }

          // se for nó de texto contendo só whitespace: ignora e continua buscando
          if ($candidate instanceof Node && $candidate->type === 'text') {
            if (trim((string)$candidate->content) === '') {
              continue;
            } else {
              // texto útil entre blocos — não consideramos que exista um if imediatamente anterior
              break;
            }
          }

          // se não for Node (defensivo), continua procurando
        }
      }



      //Se houver, adiciona o texto antes do comando à arvore
      if ($start > $pos) {
        $text = substr($html, $pos, $start - $pos);
        //Pega só a parte entre a posição atual e o comando atual da string.

        $stack[count($stack) - 1]->content[] = new Node('text', $text);
        //Transforma esse texto em um nó
      }
      // --- Abertura de foreach ---
      if (isset($m['foreach'][0]) && $m['foreach'][0] !== '') {
        $node = new Node('foreach', []);
        $node->params['listname'] = $m['listname'][0] ?? null;
        $node->params['key']      = $m['key'][0] ?? null;
        $node->params['item']     = $m['item'][0] ?? null;

        $stack[count($stack) - 1]->content[] = $node;
        $stack[] = $node;
      }
      // --- Abertura de for ---
      elseif (isset($m['for'][0]) && $m['for'][0] !== '') {
        $node = new Node('for', []);
        $node->params['times'] = isset($m['times'][0]) ? (int)$m['times'][0] : 0;

        $stack[count($stack) - 1]->content[] = $node;
        $stack[] = $node;
      }
      // ---- Abertura if ----
      elseif (isset($m['if'][0]) && $m['if'][0] !== '') {
        $node = new Node('if', []);
        $node->params['condition'] = isset($m['if_condition'][0]) ? (string)$m['if_condition'][0] : '';

        // parse da condição e guarda no próprio Node
        if ($node->params['condition'] !== '') {
          $parser = new ConditionParser($node->params['condition']);
          $node->params['condNode'] = $parser->root; // AST pronta
        }

        $stack[count($stack) - 1]->content[] = $node;
        $stack[] = $node;
      } // elseif / else dependentes (se houver um prev válido do tipo if/elseif)
      elseif ((isset($m['elseif'][0]) && $m['elseif'][0] !== '') || (isset($m['else'][0]) && $m['else'][0] !== '')) {
        // se prev é um Node e é if/elseif -> anexa como dependent
        if ($prevIf instanceof Node && ($prevIf->type === 'if' || $prevIf->type === 'elseif')) {
          if (isset($m['elseif'][0]) && $m['elseif'][0] !== '') {
            $node = new Node('elseif', []);
            $node->params['condition'] = isset($m['elseif_condition'][0]) ? (string)$m['elseif_condition'][0] : '';

            // parse da condição e guarda no próprio Node
            if ($node->params['condition'] !== '') {
              $parser = new ConditionParser($node->params['condition']);
              $node->params['condNode'] = $parser->root; // AST pronta
            }

            $prevIf->dependents[] = $node;
            $stack[] = $node;
          } elseif (isset($m['else'][0]) && $m['else'][0] !== '') {
            $node = new Node('else', []);
            $prevIf->dependents[] = $node;
            $stack[] = $node;
          }
        } else {
          // fallback: sem if anterior, anexa ao nível atual como nó solto
          if (isset($m['elseif'][0]) && $m['elseif'][0] !== '') {
            $node = new Node('elseif', []);
            $node->params['condition'] = isset($m['elseif_condition'][0]) ? (string)$m['elseif_condition'][0] : '';
            $stack[count($stack) - 1]->content[] = $node;
            $stack[] = $node;
          } elseif (isset($m['else'][0]) && $m['else'][0] !== '') {
            $node = new Node('else', []);
            $stack[count($stack) - 1]->content[] = $node;
            $stack[] = $node;
          }
        }
      }

      // --- Fechamento ---
      elseif (isset($m['close'][0]) && $m['close'][0] !== '') {
        if ($top instanceof Node && $top->type !== 'root') {
          array_pop($stack);
        } else {
          // tag de fechamento inesperada — ignora ou loga
          $node = new Node('text', $m[0][0]);
          $stack[count($stack) - 1]->content[] = $node;
        }
      }

      // adiciona o número de letras do comando na posição
      $pos = $start + strlen($m[0][0]);
    }
    if ($pos < strlen($html)) { //verifica se ainda não acabamos o html

      //Todo texto depois de todos os comandos é adicionado também
      $stack[count($stack) - 1]->content[] = new Node('text', substr($html, $pos));
    }

    return $current;
  }
}

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

      // decide o valor da esquerda: literal entre aspas? número? ou variável?
      if (is_string($leftRaw) && preg_match('/^([\'"])(.*)\1$/s', $leftRaw, $m)) {
        // literal string entre aspas - desempacota e unescapa
        $leftVal = stripcslashes($m[2]);
      } elseif (is_numeric($leftRaw)) {
        $leftVal = $leftRaw + 0;
      } else {
        // trata como variável
        $leftVal = TemplateRenderer::replaceVar('{' . $leftRaw . '}', $global, $local);
      }

      // decide o valor da direita: literal entre aspas? número? ou variável?
      if (is_string($rightRaw) && preg_match('/^([\'"])(.*)\1$/s', $rightRaw, $m)) {
        // literal string entre aspas - desempacota e unescapa
        $rightVal = stripcslashes($m[2]);
      } elseif (is_numeric($rightRaw)) {
        $rightVal = $rightRaw + 0;
      } else {
        // trata como variável
        $rightVal = TemplateRenderer::replaceVar('{' . $rightRaw . '}', $global, $local);
      }

      if (is_numeric($leftVal) && is_numeric($rightVal)) {
        $leftVal  = $leftVal + 0;
        $rightVal = $rightVal + 0;
      }

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
class Template
{

  private $html;

  public function __construct($name)
  {
    if (file_exists($name)) $this->html = file_get_contents($name);
  }

  public function render(array $data)
  {

    $parse = new TemplateParser($this->html);
    $rend = new TemplateRenderer();
    echo $rend->render($parse->root, $data);
  }

  public function debug(array $data)
  {
    $parse = new TemplateParser($this->html);
    NodeDebugger::toHtml($parse->root, ['maxDepth' => 99999, 'trimText' => 99999, 'showEmpty' => false]);
  }
}
