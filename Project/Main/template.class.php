<?php

require_once "Parsers/templateparser.class.php";
require_once "Render/template_renderer.class.php";
require_once "Debuggers/node_debugger.class.php";

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
