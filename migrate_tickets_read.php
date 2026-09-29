<?php
// migrate_tickets_read.php — adiciona coluna `read_at` em tickets (idempotente)
// Não lido = read_at IS NULL. Rode uma vez por banco (local ou produção).
require_once __DIR__ . '/api/config.php';

try {
    $pdo = getConnection();
    $check = $pdo->query("SHOW COLUMNS FROM tickets LIKE 'read_at'")->fetch();
    if ($check) {
        echo "OK: coluna read_at já existe.\n";
        exit(0);
    }
    $pdo->exec("ALTER TABLE tickets ADD COLUMN read_at TIMESTAMP NULL DEFAULT NULL AFTER created_at");
    echo "OK: coluna read_at criada.\n";
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
    exit(1);
}
