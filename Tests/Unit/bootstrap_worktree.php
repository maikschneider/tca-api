<?php

// Register a prepended autoloader that loads classes from this worktree's Classes/ directory,
// overriding the main project's classes. This allows unit tests to run against worktree code
// without installing a separate vendor directory.
spl_autoload_register(function (string $class): bool {
    $prefix = 'MaikSchneider\\TcaApi\\';
    if (!str_starts_with($class, $prefix)) {
        return false;
    }
    $relative = substr($class, \strlen($prefix));
    $file = __DIR__ . '/../../Classes/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) {
        require_once $file;
        return true;
    }
    return false;
}, true, true);

require_once '/var/www/html/vendor/autoload.php';
