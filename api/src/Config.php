<?php
declare(strict_types=1);
namespace Wellness;

use RuntimeException;

final class Config {
  private static bool $loaded = false;

  public static function load(string $path): void {
    if (self::$loaded) return;
    self::$loaded = true;
    if (!is_file($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
      $line = trim($line);
      if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
      [$name, $value] = array_map('trim', explode('=', $line, 2));
      $value = trim($value, "\"'");
      if (getenv($name) === false) { putenv("$name=$value"); $_ENV[$name] = $value; }
    }
  }

  public static function get(string $name, ?string $default = null): string {
    $value = getenv($name);
    if ($value === false || $value === '') {
      if ($default !== null) return $default;
      throw new RuntimeException("Missing required environment variable: $name");
    }
    return $value;
  }
}
