<?php

require_once __DIR__ . "/../core/node.class.php";
require_once __DIR__ . "/../render/template_renderer.class.php";
require_once __DIR__ . "/../Utils/helpers.php";

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
