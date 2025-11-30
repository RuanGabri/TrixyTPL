<?php

require_once __DIR__ . "/../core/node.class.php";
require_once __DIR__ . "/../render/template_renderer.class.php";
require_once __DIR__ . "/../Utils/helpers.php";

//Transforma html com comando em arvore em hierarquia de comandos
class TemplateParser
{

  private $html; //html do template original
  private $global; //dados globais do template
  private $local; //dados globais do template
  public $root; //Arvore de hierarquia de comandos e condições

  public function __construct(string $string, array|object $global, array|object $local = [])
  {
    $this->html = $string;
    $this->global = $global;
    $this->local = $local;
    $this->root =  $this->parse($string); //Salva o html como arvore na variavel;
  }

  private function parse(string $html)
  {
    // Remove comentários HTML
    $html = preg_replace('/<!--.*?-->/s', '', $html);

    $stack = []; //cria a arvore vazia
    $current = new Node('root', []); // cria o ponteiro do pai atual e cria primeiro nível, raiz
    $stack[] = $current; //salva a raiz na arvore
    $bracepattern = '
    (?P<brace>
      \{
        \s*
        (?:
              Define\s*\((?<assignment>.*?)\)
            | (?P<varStr>' . Config::VAR_PATTERN . ')
            | Math\s*\((?<expression>.*?)\)
            | require\s+\((?P<archive>.*?)\)
            | str_filter\s*\((?P<str>.*?),\s*(?P<filters>.*?)\)
            | (?<randNum>random_num\s*(?:\((?P<maxRN>.*?)(?:,(?P<minRN>.*?))?\))?)
            | (?<randStr>random_str\s*(?:\((?P<lenRS>.*?)\))?)
            

        )(?:\s*\|\s*(?P<varFilter>.*?))?
        \s*
      \}
    )';
    $pattern = '~
     (?P<foreach> \[\s*foreach\s+(?P<listname>' . Config::VAR_PATTERN . ') \s+as\s+ (?:(?P<key>\w+)\s*=>\s*)? (?P<item>\w+) \s*{)
    | (?P<for>\[\s*for\s+(?P<times>\d+|' . Config::VAR_PATTERN . ')\s*{)
    | (?P<if>\[\s*if\s*(?P<if_condition>.*?)\s*{)
    | (?P<elseif>\[\s*else\s*if\s*(?P<elseif_condition>.*?)\s*{)
    | (?P<else>\[\s*else\s*{)
    | ' . $bracepattern . '
    | (?P<close>\}\s*\])
    ~six';

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
          $tokens = ConditionParser::tokenizer($node->params['condition']);
          $root = ConditionParser::parseCondition($tokens);

          $node->params['condNode'] = $root; // AST pronta
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
              $tokens = ConditionParser::tokenizer($node->params['condition']);
              $root = ConditionParser::parseCondition($tokens);
              $node->params['condNode'] = $root; // AST pronta
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
      } elseif (isset($m['brace'][0]) && $m['brace'][0] !== '') {
        // Variáveis 
        if (isset($m['varStr'][0]) && $m['varStr'][0] !== '') {
          $node = new Node('var', []);
          $node->params["varStr"] = (string)$m['varStr'][0] ?? '';
          $node->params["varFilter"] = isset($m['varFilter'][0]) ? (string)$m['varFilter'][0] : "";

          $stack[count($stack) - 1]->content[] = $node;
        }
        // Expressões matemáticas 
        elseif (isset($m['expression'][0]) && $m['expression'][0] !== '') {
          $node = new Node('math', []);
          $node->params["expression"] = (string)$m['expression'][0] ?? "";
          $node->params["Filter"] = isset($m['varFilter'][0]) ? (string)$m['varFilter'][0] : "";

          $stack[count($stack) - 1]->content[] = $node;
        }
        // Definir variáveis 
        elseif (isset($m['assignment'][0]) && $m['assignment'][0] !== '') {
          $node = new Node('define', []);
          $node->params["assignment"] = (string)$m['assignment'][0] ?? "";

          $stack[count($stack) - 1]->content[] = $node;
        } // processa o require e adiciona os dados da pagina requerida na arvore
        elseif (isset($m['archive'][0]) && $m['archive'][0] !== '') {

          $filerequired = $m['archive'][0];
          $node = new Node('require', []);

          $this->requireParser($node, $filerequired);
          $stack[count($stack) - 1]->content[] = $node;
        } // lê o str_filter adiciona seus dados à arvore
        elseif (isset($m['filters'][0]) && $m['filters'][0] !== '') {
          $node = new Node('str_filter', []);

          $str = isset($m['str'][0]) ? (string)$m['str'][0] : '';

          $node->params["str"] = $str;

          $filters = (string)$m['filters'][0];

          $filters = trim_once($filters, "(", ")");
          $filters = split_args(",", $filters);


          $node->params["filters"] = $filters;

          $stack[count($stack) - 1]->content[] = $node;
        } elseif (isset($m['randNum'][0]) && $m['randNum'][0] !== '') {
          $node = new Node('randNum', []);
          $node->params["min"] = isset($m['minRN'][0]) ? (float)$m['minRN'][0] : 1;
          $node->params["max"] = isset($m['maxRN'][0]) ? (float)$m['maxRN'][0] : 100;

          $stack[count($stack) - 1]->content[] = $node;
        } elseif (isset($m['randStr'][0]) && $m['randStr'][0] !== '') {
          $node = new Node('randStr', []);
          $node->params["len"] = isset($m['lenRS'][0]) ? (int)$m['lenRS'][0] : 10;

          $stack[count($stack) - 1]->content[] = $node;
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
      $pos = $start + strlen($m[0][0] ?? '');
    }
    if ($pos < strlen($html)) { //verifica se ainda não acabamos o html

      //Todo texto depois de todos os comandos é adicionado também
      $stack[count($stack) - 1]->content[] = new Node('text', substr($html, $pos));
    }
    return $current;
  }


  //processa a subarvore do arquivo requerido
  private function requireParser(Node $node, String $Filename): void
  {
    $content = '';

    $name = $Filename ? (string)$Filename : '';
    $name = resolveValue($name, $this->global, $this->local);

    $node->params['archive'] = $name;

    if (file_exists($name)) {
      $content = file_get_contents($name) ?? '';
    } else {
      showException("Arquivo: " . $node->params['archive'] . " não encontrado");
    }

    $node->content[] = $this->parse($content);
  }
}
