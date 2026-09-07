<?php
$config = require dirname(__DIR__) . '/config/database.php';

try {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $config['host'], $config['port'], $config['database'], $config['charset']);
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    echo 'OK';
} catch (PDOException $e) {
    // Show only the error code, not credentials
    echo 'FAIL: ' . $e->getCode() . ' - ' . $e->getMessage();
}
