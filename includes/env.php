<?php
declare(strict_types=1);

/**
 * Minimal .env loader (no Composer dependency).
 * Loads KEY=VALUE pairs into putenv / $_ENV / $_SERVER if not already set.
 */
function allureone_load_env(?string $path = null): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $file = $path ?? (dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');
    if (!is_file($file) || !is_readable($file)) {
        return;
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if ($name === '') {
            continue;
        }
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }
        // Do not override real environment / server vars.
        if (array_key_exists($name, $_ENV) || array_key_exists($name, $_SERVER) || getenv($name) !== false) {
            continue;
        }
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

function allureone_env(string $key, ?string $default = null): ?string
{
    allureone_load_env();
    if (array_key_exists($key, $_ENV) && $_ENV[$key] !== null && $_ENV[$key] !== '') {
        return (string) $_ENV[$key];
    }
    if (array_key_exists($key, $_SERVER) && $_SERVER[$key] !== null && $_SERVER[$key] !== '') {
        return (string) $_SERVER[$key];
    }
    $v = getenv($key);
    if ($v !== false && $v !== '') {
        return (string) $v;
    }

    return $default;
}
