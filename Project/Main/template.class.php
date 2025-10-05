<?php
require_once __DIR__ . "/../Parsers/templateparser.class.php";
require_once __DIR__ . "/../Render/template_renderer.class.php";
require_once __DIR__ . "/../Debuggers/node_debugger.class.php";

class Template
{

  private $html;

  public function __construct($name)
  {
    if (file_exists($name)) $this->html = file_get_contents($name);
    else {
      showRunTimeExcept("Template: " . $name . " não encontrado");
    }
  }

  public function render(array $data)
  {
    if (!isset($this->html)) {
      showRunTimeExcept("Erro ao processar o html");
    }

    $parse = new TemplateParser((string)$this->html, $data);
    $rend = new TemplateRenderer();
    echo $rend->render($parse->root, $data);
  }

  public function debug(array $data)
  {
    $parse = new TemplateParser((string)$this->html, $data);
    NodeDebugger::toHtml($parse->root, ['maxDepth' => 99999, 'trimText' => 99999, 'showEmpty' => false]);
  }
}
