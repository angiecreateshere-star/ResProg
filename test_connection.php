<?php

require_once __DIR__ . '/config/db.php';

try {
    $stmt = $pdo->query("SELECT DATABASE()");
    $database = $stmt->fetchColumn();

    echo "<h1>ResProg Database Test</h1>";
    echo "<p>PHP connection successful!</p>";
    echo "<p>Connected database: " .
         htmlspecialchars($database, ENT_QUOTES, 'UTF-8') .
         "</p>";
} catch (PDOException $e) {
    error_log('ResProg connection test failed: ' . $e->getMessage());
    http_response_code(500);
    echo "<p>Database test failed. Check the PHP error log.</p>";
}