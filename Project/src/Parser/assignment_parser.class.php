<?php

class AssignmentParser
{

  public static function parser(string $string)
  {

    $left = trim(strtok($string, "="));

    $right = trim(strtok("="));

    $node =  new Node("assignment", [], ["left" => $left, "right" => $right]);
    return $node;
  }

  public static function evaluateNode(
    Node $node,
    array|object $global,
    array|object &$local
  ) {

    $varName = resolveValue($node->params["left"], $global, $local);
    $varValue = resolveValue($node->params["right"], $global, $local);

    if (!isset($varName) || $varName === '') {
      showException("O nome de variável '{$varName}' é inválido");
      return;
    }

    $path = explode('.', $varName);
    $first = array_shift($path);

    if (!is_array($local)) $local = [];

    if (empty($path)) {
      // simples, nível único
      $local[$first] = $varValue;
      return;
    }

    // mergulha pelos níveis intermediários
    $current = &$local;
    foreach (array_merge([$first], $path) as $key) {
      if (!isset($current[$key]) || !is_array($current[$key])) {
        $current[$key] = [];
      }
      $prev = &$current;
      $current = &$current[$key];
    }

    // atribui o valor final
    $prev[$key] = $varValue;
  }
}
