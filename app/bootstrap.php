<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers/functions.php';

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ini_set('session.cookie_secure', '1');
session_name('supporthub_session');
session_start();

spl_autoload_register(function (string $class): void {
    foreach (['services', 'models', 'controllers'] as $folder) {
        $file = __DIR__ . "/{$folder}/{$class}.php";
        if (is_file($file)) { require_once $file; return; }
    }
});

Database::connection();
