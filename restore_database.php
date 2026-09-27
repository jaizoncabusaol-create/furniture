<?php

require_once __DIR__ . '/db.php';

$backupFile = __DIR__ . '/backups/furniquest_backup_2026-09-26_210831_bdcd88d0.sql';

if (!file_exists($backupFile)) {
    die("Backup file not found: $backupFile\n");
}

echo "Restoring database from: $backupFile\n";

try {
    $db = appDb();
    
    $sql = file_get_contents($backupFile);
    if ($sql === false) {
        die("Failed to read backup file\n");
    }
    
    $statements = [];
    $current = '';
    $inString = false;
    $stringChar = '';
    $escapeNext = false;
    
    for ($i = 0; $i < strlen($sql); $i++) {
        $char = $sql[$i];
        
        if (!$inString) {
            if ($char === '\'' || $char === '"' || $char === '`') {
                $inString = true;
                $stringChar = $char;
            } elseif ($char === ';') {
                $trimmed = trim($current);
                if ($trimmed !== '' && !str_starts_with($trimmed, '--')) {
                    $statements[] = $trimmed;
                }
                $current = '';
                continue;
            }
        } else {
            if ($escapeNext) {
                $escapeNext = false;
            } elseif ($char === '\\') {
                $escapeNext = true;
            } elseif ($char === $stringChar) {
                $inString = false;
                $stringChar = '';
            }
        }
        
        $current .= $char;
    }
    
    $trimmed = trim($current);
    if ($trimmed !== '' && !str_starts_with($trimmed, '--')) {
        $statements[] = $trimmed;
    }
    
    echo "Found " . count($statements) . " SQL statements\n";
    
    $db->begin_transaction();
    $executed = 0;
    $errors = 0;
    
    foreach ($statements as $stmt) {
        if (str_starts_with(trim($stmt), 'SET ') || str_starts_with(trim($stmt), 'CREATE TABLE')) {
            try {
                $db->query($stmt);
                $executed++;
            } catch (Throwable $e) {
                echo "Warning: " . $e->getMessage() . "\n";
                $errors++;
            }
            continue;
        }
        
        try {
            $db->query($stmt);
            $executed++;
        } catch (Throwable $e) {
            echo "Error executing: " . substr($stmt, 0, 100) . "...\n";
            echo "Error: " . $e->getMessage() . "\n";
            $errors++;
        }
    }
    
    $db->commit();
    
    echo "Restored successfully: $executed statements executed, $errors errors\n";
    
    echo "Products: " . appDbValue($db, 'SELECT COUNT(*) FROM products') . "\n";
    echo "Users: " . appDbValue($db, 'SELECT COUNT(*) FROM users') . "\n";
    echo "Orders: " . appDbValue($db, 'SELECT COUNT(*) FROM orders_store') . "\n";
    echo "Messages: " . appDbValue($db, 'SELECT COUNT(*) FROM messages') . "\n";
    echo "Customization requests: " . appDbValue($db, 'SELECT COUNT(*) FROM customization_requests') . "\n";
    
} catch (Throwable $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
    if (isset($db) && $db instanceof mysqli) {
        $db->rollback();
    }
}