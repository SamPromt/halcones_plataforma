<?php

declare(strict_types=1);

use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';
Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$database = $_ENV['DB_DATABASE'] ?? 'halcones';
$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$port = $_ENV['DB_PORT'] ?? '3306';
$username = $_ENV['DB_USERNAME'] ?? 'root';
$password = $_ENV['DB_PASSWORD'] ?? '';
if (!preg_match('/^[a-zA-Z0-9_]+$/', $database)) {
    fwrite(STDERR, "Invalid database name.\n");
    exit(1);
}

$server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $username, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$server->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", $username, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
    migration VARCHAR(190) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB');

$directory = dirname(__DIR__) . '/database/migrations';
$files = glob($directory . '/*.sql') ?: [];
sort($files, SORT_STRING);
foreach ($files as $file) {
    $name = basename($file);
    $check = $db->prepare('SELECT 1 FROM schema_migrations WHERE migration = :migration');
    $check->execute(['migration' => $name]);
    if ($check->fetchColumn()) {
        continue;
    }

    $sql = preg_replace('/^\s*(CREATE DATABASE IF NOT EXISTS `?halcones`?[^;]*;|USE `?halcones`?;)\s*/mi', '', (string) file_get_contents($file));
    try {
        foreach (array_filter(array_map('trim', explode(';', (string) $sql))) as $statement) {
            $db->exec($statement);
        }
        $insert = $db->prepare('INSERT INTO schema_migrations (migration) VALUES (:migration)');
        $insert->execute(['migration' => $name]);
        echo "Applied {$name}" . PHP_EOL;
    } catch (Throwable $error) {
        fwrite(STDERR, "Migration {$name} failed: {$error->getMessage()}" . PHP_EOL);
        exit(1);
    }
}
