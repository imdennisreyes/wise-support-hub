<?php

// All values must be set via environment variables in production.
// For local development, copy config/database.example.php to a local .env or
// set the variables in your XAMPP/Docker environment.
return [
    'driver'      => getenv('DB_DRIVER')   ?: 'mysql',
    'host'        => getenv('DB_HOST')     ?: '127.0.0.1',
    'port'        => getenv('DB_PORT')     ?: '3306',
    'database'    => getenv('DB_DATABASE') ?: '',
    'username'    => getenv('DB_USERNAME') ?: '',
    'password'    => getenv('DB_PASSWORD') ?: '',
    'charset'     => 'utf8mb4',
    'sqlite_path' => dirname(__DIR__) . '/database/support_hub.sqlite',
];
