<?php
if (!function_exists("replaceVar")) {
  function replaceVar($block, $global, $local = [])
  {

    return preg_replace_callback(
      '/\{\s*
      (?P<var>[a-zA-Z_]\w*  (?:\.[a-zA-Z_0-9]\w*)*)
        (?:\s*\|\s*
            (?P<filter>\w+) # {var| f1}
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
        $return = htmlspecialchars((string)$return, ENT_QUOTES, 'UTF-8');

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

        switch ($command) {
          case 'date':
            $time = strtotime($str);
            $format = $params[0] ?? '';
            $str = trim(date($format, $time), "'\"");
            break;

          case 'number_format':
            $number = (is_numeric($str)) ? (float)$str : 0;
            $decimals = (isset($params[0]) && $params[0] != '') ? (int)$params[0] : 2;
            $decimal_separator = (isset($params[1])) ? (string)$params[1] : ".";
            $thousands_separator = (isset($params[2])) ? (string)$params[2] : ",";
            $str = number_format($number, $decimals, $decimal_separator, $thousands_separator);
            break;


          case 'replace':
            $search = (isset($params[0]) && $params[0] != '') ? $params[0] : '';
            $replace = (isset($params[1])) ? (string)$params[1] : '';
            $count = (isset($params[2]) && $params[2] != '') ? (bool)$params[2] : false;

            if ($count || $count == "true") {
              str_replace($search, $replace, $str, $count);
              $str = $count;
              break;
            }
            $str = str_replace($search, $replace, $str, $count);
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
            $str = round($str, $precision, $mode);
            break;
          case 'truncate':
            $limit = (isset($params[0]) && $params[0] != '') ? (int)$params[0] : 10;
            $suffix = (isset($params[1]) && $params[1] != '') ? (string)$params[1] : '...';

            $str = str_truncate($str, $limit, $suffix);
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
    return $str;
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
  function resolveValue(string $str, array|object $global, array|object $local = []): string|float|array
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

if (!function_exists("showRunTimeExcept")) {
  //mostra erro
  function showRunTimeExcept(string $message): void
  {
    try {
      throw new RuntimeException($message);
    } catch (RuntimeException $e) {
      $errorpath = $e->getFile() . " " . $e->getLine() . "<br>" . $e->getTraceAsString();
      echo "RuntimeException: " . $e->getMessage() . "<br>" . "<strong>" . $errorpath . "</strong>";
    }
  }
}
if (!function_exists("trim_once")) {
  //mostra erro
  function trim_once(string $str, string $start, string $end = ''): string
  {
    $end = (isset($end) && !empty($end) ? $end : $start);
    if ($str[0] === $start && $str[-1] === $end) {
      $str = substr($str, 1, -1);
    }
    return $str;
  }
}
