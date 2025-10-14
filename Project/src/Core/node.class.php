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
