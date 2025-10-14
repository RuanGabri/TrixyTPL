<?php
require_once __DIR__ . "/../Parser/templateparser.class.php";
require_once __DIR__ . "/../Render/template_renderer.class.php";
require_once __DIR__ . "/../Debugger/node_debugger.class.php";
require_once __DIR__ . "/../Cache/cache_manager.class.php";

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
