<?php
/**
 * Minimal .env loader — no Composer dependency.
 *
 * Reads KEY=VALUE pairs from the project root .env file and
 * makes them available via getenv() / $_ENV. Skips blank lines,
 * comments (#), and lines without an = sign. Strips surrounding
 * quotes from values.
 *
 * Loaded once per request by pool.php.
 */

function loadEnv($path = null) {
  if ($path === null) {
    // Two levels up from public/api/ → project root
    $path = dirname(__DIR__, 2) . '/.env';
  }

  if (!file_exists($path) || !is_readable($path)) {
    return;
  }

  $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if ($lines === false) {
    return;
  }

  foreach ($lines as $line) {
    $line = trim($line);

    // Empty check must come first — short-circuits before $line[0] access
    if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
      continue;
    }

    list($key, $value) = explode('=', $line, 2);
    $key = trim($key);
    $value = trim($value);

    // Strip surrounding quotes
    if (strlen($value) >= 2) {
      $first = $value[0];
      $last = $value[strlen($value) - 1];
      if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
        $value = substr($value, 1, -1);
      }
    }

    // Don't overwrite vars already set by the real environment
    if (getenv($key) === false) {
      putenv("$key=$value");
      $_ENV[$key] = $value;
    }
  }
}
?>
