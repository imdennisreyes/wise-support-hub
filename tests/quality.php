<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$failures = [];
$count = 0;

foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $count++;
    try {
        token_get_all(file_get_contents($file->getPathname()), TOKEN_PARSE);
    } catch (ParseError $error) {
        $failures[] = str_replace($root . '/', '', $file->getPathname()) . ': ' . $error->getMessage();
    }
}

if ($failures) {
    fwrite(STDERR, "PHP syntax failures:\n" . implode("\n", $failures) . "\n");
    exit(1);
}

echo "Parsed {$count} PHP files successfully.\n";
echo 'PHP ' . PHP_VERSION . '; extensions: ' . implode(', ', array_intersect(['PDO', 'pdo_mysql', 'pdo_sqlite', 'mbstring', 'fileinfo'], get_loaded_extensions())) . "\n";
