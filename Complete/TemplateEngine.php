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

if (!function_exists("replaceVar")) {
  function replaceVar($block, $global, $local = [])
  {

    return preg_replace_callback(
      '/\{\s*
      (?P<var>[a-zA-Z_]\w*  (?:\.[a-zA-Z_0-9]\w*)*)
        (?:\s*\|\s*
            (?P<filter>.*?) # {var| f1}
        )?
      \s*\}/xs',
      function ($m) use ($global, $local) {
        $var = $m["var"];
        $filter = $m["filter"] ?? null;
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

        $return = processFilter($return, $filter, $global, $local);

        return $return;
      },
      $block
    );
  }
}
if (!function_exists("processFilter")) {
  function processFilter(
    string $str,
    string | null $filter,
    array | object $global,
    array | object $local = []
  ): string {
    // se houver processa os filtros
    if (isset($filter) && !empty($filter)) {
      // se houver processa os filtros complexos
      if (preg_match('/^(?P<command>\w+)\((?P<params>.*?)\)$/', $filter, $m)) {
        $command = (isset($m['command'])) ? trim($m['command']) : '';
        $params = (isset($m['params'])) ? trim($m['params']) : '';
        $params = split_args(",", $params);
        foreach ($params as $key => $value) {
          $params[$key] = resolveValue($value, $global, $local);
        }
        $str = resolveValue($str, $global, $local);

        switch ($command) {
          case 'date':
            $time = strtotime($str);
            $format = $params[0] ?? '';
            $str = trim(date($format, $time), "'\"");
            $str = htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
            break;

          case 'number_format':
            $number = (is_numeric($str)) ? (float)$str : 0;
            $decimals = (isset($params[0]) && $params[0] != '') ? (int)$params[0] : 2;
            $decimal_separator = (isset($params[1])) ? (string)$params[1] : ".";
            $thousands_separator = (isset($params[2])) ? (string)$params[2] : ",";
            $str = number_format($number, $decimals, $decimal_separator, $thousands_separator);
            $str = htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
            break;


          case 'replace':
            $search = (isset($params[0]) && $params[0] != '') ? $params[0] : '';
            $replace = (isset($params[1])) ? (string)$params[1] : '';
            $count = (isset($params[2]) && $params[2] != '') ? (bool)$params[2] : false;

            if ($count || $count == "true") {
              str_replace($search, $replace, $str, $count);
              $str = $count;
              $str = htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
              break;
            }
            $str = str_replace($search, $replace, $str, $count);
            $str = htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
            break;
          case 'round':
            $precision = (isset($params[0]) && $params[0] != '') ? (float)$params[0] : 1;
            $mode = (isset($params[1]) && $params[1] != '') ? $params[1] : 0;
            if (is_string($mode)) {
              switch ($mode) {
                case "ROUND_HALF_UP":
                  $mode = PHP_ROUND_HALF_UP;
                  break;
                case "ROUND_HALF_DOWN":
                  $mode = PHP_ROUND_HALF_DOWN;
                  break;
                case "ROUND_HALF_EVEN":
                  $mode = PHP_ROUND_HALF_EVEN;
                  break;
                case "ROUND_HALF_ODD":
                  $mode = PHP_ROUND_HALF_ODD;
                  break;
                default:
                  $mode = 0;
              }
            }
            $str = round((float)$str, $precision, $mode);
            $str = htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
            break;
          case 'truncate':
            $limit = (isset($params[0]) && $params[0] != '') ? (int)$params[0] : 10;
            $suffix = (isset($params[1]) && $params[1] != '') ? (string)$params[1] : '...';

            $str = str_truncate($str, $limit, $suffix);
            $str = htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
            break;
          case 'default':
            $default = (isset($params[0]) && $params[0] != '') ? (string)$params[0] : '';

            $str = ($str !== '') ? $str : $default;
            $str = htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
            break;
        }
      } else {
        // se houver processa os filtros simples
        switch ($filter) {
          case "strip_tags":
            $str = strip_tags($str);
            break;
          case "trim":
            $str = trim($str);
            break;
          case "nl2br":
            $str = nl2br($str);
            break;
          case "upper":
            $str = strtoupper($str);
            break;
          case "lower":
            $str = strtolower($str);
            break;
          case "capitalize":
            $str = ucwords($str);
            break;
          case "ufirst":
            $str = ucfirst($str);
            break;
          case "length":
            $str = strlen($str);
            break;
          default:
            break;
        }
      }
    }
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');;
  }
}
if (!function_exists("split_args")) {
  //Dá um explode apenas em separatodes que não estão entre aspas ou entre parenteses

  function split_args(
    string $separator,
    string $str,
    string $starts = "([{",
    string $ends = ")]}"
  ): array {

    $str = trim($str);
    $result = [];
    $buffer = '';
    $depth = 0;
    $inQuotes = false;
    $quoteChar = null;
    $len = strlen($str);

    for ($i = 0; $i < $len; $i++) {
      $ch = $str[$i];

      // alterna estado de aspas
      if (($ch === '"' || $ch === "'") && ($i === 0 || $str[$i - 1] !== '\\')) {
        if ($inQuotes && $ch === $quoteChar) {
          $inQuotes = false;
          $quoteChar = null;
        } elseif (!$inQuotes) {
          $inQuotes = true;
          $quoteChar = $ch;
        }
      }

      if (!$inQuotes) {
        if (str_contains($starts, $ch)) {
          $depth++;
        } elseif (str_contains($ends, $ch)) {
          $depth--;
        }
      }

      // separa por vírgula, mas só no nível zero
      if ($ch === $separator && $depth === 0 && !$inQuotes) {
        $result[] = trim($buffer);
        $buffer = '';
      } else {
        $buffer .= $ch;
      }
    }

    if (strlen(trim($buffer)) > 0) {
      $result[] = trim($buffer);
    }

    return $result;

    // $pattern = '/' . $separator . '(?=(?:[^()]*\([^()]*\))*[^()]*$)(?=(?:[^[]]*\([^\[]]*\))*[^()]*$)(?=(?:[^"]*"[^"]*")*[^"]*$)/';
    // $args = preg_split($pattern, $str);
    // return array_map(fn($p) => trim($p, " '\""), $args);
  }
}
if (!function_exists("str_truncate")) {
  //recorta string até um limite e,se recortar, adicionar um sulfixo

  function str_truncate(
    string $str,
    int $limit,
    string $suffix = "..."
  ): string {
    $len = strlen($str);

    if ($len > $limit) {
      $prefix = substr($str, 0, $limit);
      return $prefix . $suffix;
    }
    return $str;
  }
}
if (!function_exists("resolveValue")) {
  /*Verifica se certa string é um número, string literal ou 
  uma variável e retorna seu valor correto*/
  function resolveValue(string $str, array|object $global, array|object $local = []): mixed
  {
    if (isset($str) && $str !== '') {
      // verifica se a string é literal ou variável
      if (preg_match('/^([\'"])(.*)\1$/s', $str, $m)) {
        // literal string entre aspas - desempacota e unescapa
        $str = stripcslashes($m[2]);
      } // verifica se é um array
      else if (preg_match('/^\[.*\]$/s', $str, $m)) {
        $array = split_args(",", trim_once($m[0], "[", "]"));
        foreach ($array as $key => $value) {
          $array[$key] = resolveValue(
            $value,
            $global,
            $local
          );
        }

        $str = $array;
      } //verifica se é um número
      else if (preg_match('/^-?\d+(\.\d+)?$/', $str)) {
        $str = (float) $str;
      } // verifica se é booleano ou null
      else if (in_array(strtolower($str), ["true", "false", "null"])) {
        $map = ['true' => true, 'false' => false, 'null' => null];
        $str = $map[strtolower($str)];
      } else {
        // trata como variável
        $str = replaceVar('{' . $str . '}', $global, $local);
      }
    }
    return $str;
  }
}

if (!function_exists("showException")) {
  //mostra erro
  function showException(string $message): void
  {

    $env = $_ENV['APP_ENV'] ?: 'production';
    if ($env === 'development') {
      ini_set('display_errors', 1);
      try {
        throw new Exception($message);
      } catch (Exception $e) {
        $errorpath = $e->getFile() . " " . $e->getLine() . "<br>" . $e->getTraceAsString();
        echo "Exception: " . $e->getMessage() . "<br>" . "<strong>{$errorpath}</strong>";
      }
    } else {
      ini_set('display_errors', 0);
      try {
        throw new Exception($message);
      } catch (Exception $e) {
        echo "Um erro ocorreu. Tente novamente mais tarde <br>";
      }
    }
  }
}
if (!function_exists("trim_once")) {
  //mostra erro
  function trim_once(string $str, string $start, string $end = ''): string
  {
    $end = (isset($end) && !empty($end) ? $end : $start);
    $strStart = substr($str, 0, 1);
    $strEnd = substr($str, -1);
    if ($strStart === $start && $strEnd === $end) {
      $str = substr($str, 1, -1);
    }
    return $str;
  }
}

if (!function_exists("resolvePath")) {
  //buscar hierarquicamente em um array ou abjeto
  function resolvePath(string $path, array|object $context): array|object|bool
  {
    $parts = explode(".", $path);
    foreach ($parts as $part) {
      if (is_array($context) && array_key_exists($part, $context)) {
        $context = $context[$part];
      } elseif (is_object($context) && property_exists($context, $part)) {
        $context = $context->$part;
      } else {
        return false; // caminho quebrado
      }
    }
    return $context;
  }
}






//Transforma html com comando em arvore em hierarquia de comandos
class TemplateParser
{

  private $html; //html do template original
  private $global; //html do template original
  public $root; //Arvore de hierarquia de comandos e condições

  public function __construct(string $string, array|object $global)
  {
    $this->html = $string;
    $this->global = $global;
    $this->root =  $this->parse($string); //Salva o html como arvore na variavel;
  }

  private function parse(string $html)
  {
    // Remove comentários HTML
    $html = preg_replace('/<!--.*?-->/s', '', $html);

    $stack = []; //cria a arvore vazia
    $current = new Node('root', []); // cria o ponteiro do pai atual e cria primeiro nível, raiz
    $stack[] = $current; //salva a raiz na arvore
    $pattern = '/
     (?P<foreach> \[\s*foreach\s* (?P<listname>[a-zA-Z_]\w*(?:\.[a-zA-Z_0-9]\w*)*) \s+as\s* (?:(?P<key>\w+)\s*=>\s*)? (?P<item>\w+) \s*{)
    | (?P<for>\[\s*for\s*(?P<times>\d+|[a-zA-Z_]\w*(?:\.[a-zA-Z_0-9]\w*)*)\s*{)
    | (?P<if>\[\s*if\s*(?P<if_condition>.*?)\s*{)
    | (?P<elseif>\[\s*else\s*if\s*(?P<elseif_condition>.*?)\s*{)
    | (?P<else>\[\s*else\s*{)
    | (?P<require>\[\s*require\s*\(?\s*
        (?P<archive>
            "(?:\\\\.|[^"\\\\])*"      # aspas duplas
          | \'(?:\\\\.|[^\'\\\\])*\'   # aspas simples
          | [a-zA-Z_]\w*(?:\.[a-zA-Z_0-9]\w*)*  # sem aspas
        )
    \s*\)?\])
    | (?P<str_filter> \[\s*str_filter\s*\(\s*(?P<str>.*?)\s*,\s*(?P<filters>.*?)\s*\)\s*])
    | (?P<close>}\s*\])
    /six';

    $pos = 0; //guarda a posição começando em 0.
    preg_match_all($pattern, $html, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
    // encontra todos os comandos [for] etc e seus fechamentos.
    $matches = $matches ?? [];
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
        $node->params['times'] = isset($m['times'][0]) ? $m['times'][0] : 0;

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
      } // processa o require e adiciona os dados da pagina requerida na arvore
      elseif (isset($m['require'][0]) && $m['require'][0] !== '') {
        $node = new Node('require', []);

        $this->requireParser($node, $m['archive'][0]);

        $stack[count($stack) - 1]->content[] = $node;
      } // lê o str_filter adiciona seus dados à arvore
      elseif (isset($m['str_filter'][0]) && $m['str_filter'][0] !== '') {
        $node = new Node('str_filter', []);

        $str = isset($m['str'][0]) ? (string)$m['str'][0] : '';

        $node->params["str"] = $str;

        $filters = isset($m['filters'][0]) ? (string)$m['filters'][0] : [];

        $filters = trim_once($filters, "(", ")");
        $filters = split_args(",", $filters);


        $node->params["filters"] = $filters;

        $stack[count($stack) - 1]->content[] = $node;
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
      $pos = $start + strlen($m[0][0] ?? '');
    }
    if ($pos < strlen($html)) { //verifica se ainda não acabamos o html

      //Todo texto depois de todos os comandos é adicionado também
      $stack[count($stack) - 1]->content[] = new Node('text', substr($html, $pos));
    }

    return $current;
  }


  //processa a subarvore do arquivo requerido
  private function requireParser(Node $node, String $Filename, array $local = []): void
  {
    $content = '';

    $name = $Filename ? (string)$Filename : '';
    $name = resolveValue($name, $this->global, $local);

    $node->params['archive'] = $name;

    if (file_exists($name)) {
      $content = file_get_contents($name) ?? '';
    } else {
      showException("Arquivo: " . $node->params['archive'] . " não encontrado");
    }

    $node->content[] = $this->parse($content);
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
        if (!$next) showException("NOT sem expressão");
        if ($next === '(') {
          $expr = $this->parseCondition($tokens);
        } elseif ($next instanceof Node) {
          $expr = $next;
        } else {
          // caso raro: token inesperado — erro
          showException("NOT sem expressão válida perto de: " . json_encode($next));
        }
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
      if (preg_match('/^[A-Za-z_]\w*(?:\.[A-Za-z_0-9]\w*)*/A', $rest, $m)) {
        $lit = $m[0];
        $node = new Node('literal', [], ['expr' => $lit]);
        $out[] = $node;
        $i += strlen($lit);
        continue;
      }
      if (preg_match('/^\'(?:\\\\\'|[^\'])*\'/A', $rest, $m) || preg_match('/^"(?:\\\\\"|[^"])*"/A', $rest, $m)) {
        $lit = $m[0];
        $node = new Node('literal', [], ['expr' => $lit]);
        $out[] = $node;
        $i += strlen($lit);
        continue;
      }
      if (preg_match('/^\d+(?:\.\d+)?/A', $rest, $m)) {
        $lit = $m[0];
        $node = new Node('literal', [], ['expr' => $lit]);
        $out[] = $node;
        $i += strlen($lit);
        continue;
      }

      // se nada bateu: token inválido — devolve erro detalhado (útil pra dev)
      showException("ConditionParser tokenizer: token inválido em: " . substr($condition, $i, 50));
    }

    return $out;
  }

  public static function evaluateNode(Node $node, $global, $local = [])
  {

    // Segurança: caso recebamos um nó literal criado como fallback
    if ($node->type === 'literal') {
      // se veio com 'expr' -> resolve o valor dinamicamente (variável, string, número)
      if (array_key_exists('expr', $node->params)) {
        $expr = $node->params['expr'];

        // se expr for nulo ou vazio, considera false
        if ($expr === null || $expr === '') {
          return false;
        }

        $val = resolveValue($expr, $global, $local);
        return (bool)$val;
      }
      return (bool)($node->params['value'] ?? false);
    } else if ($node->type === 'comparison') {
      $leftRaw  = $node->params['left'] ?? '';
      $rightRaw = $node->params['right'] ?? '';
      $opRaw    = $node->params['op'] ?? '';

      $leftVal = resolveValue($leftRaw, $global, $local);

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




class CacheManager
{

  private $folderpath = '';
  private $arraysubfolders = ["templates", "parsers"];
  public function __construct(string $folderpath = __DIR__ . "/")
  {
    $this->folderpath = rtrim($folderpath, "/") . "/";

    if (is_dir($folderpath)) {

      foreach ($this->arraysubfolders as $subfolder) {
        if (!is_dir($folderpath . $subfolder)) {
          // Cria a pasta
          mkdir($folderpath . $subfolder, 0755, true);
        }
      }
    }
  }

  public function setCache(
    string $filename,
    array|object|string $data,
    array|object|string $inputs,
    string|int $type,
    string $secret
  ): bool {
    if ((is_string($type) && in_array($type, $this->arraysubfolders))) {

      $type = $type;
    } elseif (key_exists($type, $this->arraysubfolders)) {

      $type = $this->arraysubfolders[$type];
    } else {
      showException("Tipo de cache inexistente ou inválido");
      return false;
    }
    $data = serialize($data);
    $inputs = serialize($inputs);

    $hash = hash_hmac("sha256", $inputs, $secret);

    $cache = [
      'hash' => $hash,
      'data' => base64_encode(gzencode($data)),
      'modifiedAt' => time(),
    ];

    $cache = json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    $dir = $this->folderpath . $type;
    $filenameComp = $filename  . "." . $type . '.cache';
    $filepath = $dir . "/" . $filenameComp;

    if (!is_dir($dir)) {
      if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
        showException("Falha ao criar diretório de cache: {$dir}");
      }
    }
    return file_put_contents($filepath, $cache, LOCK_EX) !== false;
  }

  public function dellCache(
    string $filename,
    string|int $type,
  ): bool {
    if ((is_string($type) && in_array($type, $this->arraysubfolders))) {

      $type = $type;
    } elseif (key_exists($type, $this->arraysubfolders)) {

      $type = $this->arraysubfolders[$type];
    } else {
      showException("Tipo de cache inexistente ou inválido");
      return false;
    }

    $dir = $this->folderpath . $type;
    $filenameComp = $filename  . "." . $type . '.cache';
    $filepath = $dir . "/" . $filenameComp;

    if (!is_dir($dir)) {
      if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
        showException("Falha ao criar diretório de cache: {$dir}");
      }
    }
    return unlink($filepath) !== false;
  }

  public function getCache(
    string $filename,
    array|object|string $inputs,
    string $type,
    string $secret
  ): mixed {

    if ((is_string($type) && in_array($type, $this->arraysubfolders))) {

      $type = $type;
    } elseif (key_exists($type, $this->arraysubfolders)) {

      $type = $this->arraysubfolders[$type];
    } else {
      showException("Tipo de cache inexistente ou inválido");
      return false;
    }

    $dir = $this->folderpath . $type;
    $filenameComp = $filename  . "." . $type . '.cache';
    $filepath = $dir . "/" . $filenameComp;
    $cache = null;

    if (file_exists($filepath)) {
      $raw = file_get_contents($filepath);
      if ($raw === false) {
        showException("Falha ao ler o cache.");
        return false;
      }
      $cached = json_decode($raw, true);

      if (!is_array($cached)) {
        showException("Cache inválido ou corrompido: {$filepath}");
        return false;
      }

      $inputs = serialize($inputs);
      $hash = hash_hmac("sha256", $inputs, $secret);

      if (hash_equals($hash, $cached["hash"])) {
        $cache = gzdecode(base64_decode($cached["data"]));
      } else {
        $this->dellCache($filename, $type);
      }
    }
    return (isset($cache)) ? unserialize($cache) : false;
  }
}






class Template
{

  private $html;
  private $filename;
  private $secret;
  private $cachedir;
  private $canCache = true;

  public function __construct(
    string $name,
    string $secret,
    string $cachedir,
    string $envdir
  ) {
    $this->loadEnv($envdir);

    $this->secret = $secret;
    $this->cachedir = $cachedir;
    if (file_exists($name)) {
      $this->html = file_get_contents($name);
      $this->filename = $name;
    } else {
      showException("Template: " . $name . " não encontrado");
    }
  }

  public function render(array $data)
  {
    if (!isset($this->html)) {
      showException("Erro ao processar o html");
    }

    $cache = new CacheManager($this->cachedir);
    $rend = new TemplateRenderer();

    $parseInpt = [$this->html];
    $parse = $cache->getCache($this->filename, $parseInpt, "parsers", $this->secret);

    $templateInpt = [$this->html, $data];
    $template = $cache->getCache($this->filename, $templateInpt, "templates", $this->secret);

    if ($template === false || !$this->canCache) {
      if ($parse === false || !$this->canCache) {


        $parse = new TemplateParser($this->html, $data);

        $cache->setCache($this->filename, $parse, $parseInpt, "parsers", $this->secret);
      }


      $template = $rend->render($parse->root, $data);

      $cache->setCache($this->filename, $template, $templateInpt, "templates", $this->secret);
    }
    echo $template;
  }

  public function debug(array $data)
  {

    $parse = new TemplateParser($this->html, $data);

    $cache = new CacheManager($this->cachedir);

    $parseInpt = [$this->html];
    $parse = $cache->getCache($this->filename, $parseInpt, "parsers", $this->secret);

    NodeDebugger::toHtml($parse->root, ['maxDepth' => 99999, 'trimText' => 99999, 'showEmpty' => false]);
  }
  private function loadEnv($dir): void
  {
    if (file_exists($dir)) {
      $lines = file($dir, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
      foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue; // ignora comentários
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
      }
    }
  }
}
