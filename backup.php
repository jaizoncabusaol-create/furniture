<?php

function appBackupDirectory(): string
{
    return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . basename(__DIR__) . DIRECTORY_SEPARATOR . 'backups';
}

function appBackupPath(string $name): ?string
{
    if (!preg_match('/^(?:rn-backup-\d{8}-\d{6}-[a-f0-9]{8}\.zip|furniquest_backup_\d{4}-\d{2}-\d{2}_\d{6}_[a-f0-9]{8}\.sql)$/', $name)) {
        return null;
    }

    $path = appBackupDirectory() . DIRECTORY_SEPARATOR . $name;
    return is_file($path) ? $path : null;
}

function appBackupHistory(): array
{
    $files = array_merge(glob(appBackupDirectory() . DIRECTORY_SEPARATOR . 'rn-backup-*.zip') ?: [], glob(appBackupDirectory() . DIRECTORY_SEPARATOR . 'furniquest_backup_*.sql') ?: []);
    $files = array_values(array_filter($files, static function ($path) {
        return appBackupPath(basename($path)) !== null;
    }));
    usort($files, static function ($left, $right) {
        return (filemtime($right) <=> filemtime($left)) ?: strcmp(basename($right), basename($left));
    });
    return $files;
}

function appBackupCreatedAt(string $path): DateTimeImmutable
{
    return (new DateTimeImmutable('@' . (string) filemtime($path)))
        ->setTimezone(new DateTimeZone('Asia/Manila'));
}

function appBackupWrite($handle, string $contents): void
{
    while ($contents !== '') {
        $written = fwrite($handle, $contents);
        if ($written === false || $written === 0) {
            throw new RuntimeException('Could not write the database backup.');
        }
        $contents = substr($contents, $written);
    }
}

function appBackupDatabaseTables(mysqli $db): array
{
    $tables = [];
    $result = $db->query('SHOW FULL TABLES');
    while ($row = $result->fetch_row()) {
        if (($row[1] ?? '') === 'BASE TABLE') {
            $tables[] = (string) $row[0];
        }
    }
    $result->free();
    sort($tables, SORT_STRING);
    return $tables;
}

function appCreateDatabaseBackup(mysqli $db): string
{
    $directory = appBackupDirectory();
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the backup folder.');
    }
    $name = 'furniquest_backup_' . (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d_His')
        . '_' . bin2hex(random_bytes(4)) . '.sql';
    $path = $directory . DIRECTORY_SEPARATOR . $name;
    $handle = fopen($path, 'xb');
    if ($handle === false) {
        throw new RuntimeException('Could not create the database backup.');
    }
    try {
        $tables = appBackupDatabaseTables($db);
        if ($tables === []) {
            throw new RuntimeException('No database tables were found to back up.');
        }
        $db->begin_transaction(MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
        $createdAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('F j, Y g:i A T');
        appBackupWrite($handle, "-- FURNIQUEST SQL BACKUP v1\n--  +--------------------------+\n--  |  RN  /  RN FURNITURE     |\n--  +--------------------------+\n--  Official database backup\n--  Created: {$createdAt}\n--  Tables: " . count($tables) . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
        foreach ($tables as $table) {
            $quotedTable = '`' . str_replace('`', '``', $table) . '`';
            $definition = $db->query('SHOW CREATE TABLE ' . $quotedTable)->fetch_assoc();
            $createSql = (string) ($definition['Create Table'] ?? '');
            if ($createSql === '') {
                throw new RuntimeException('Could not read a database table definition.');
            }
            $createSql = preg_replace('/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', $createSql, 1);
            appBackupWrite($handle, str_replace(["\r", "\n"], ' ', $createSql) . ";\n");
            appBackupWrite($handle, 'DELETE FROM ' . $quotedTable . ";\n");
            $result = $db->query('SELECT * FROM ' . $quotedTable, MYSQLI_USE_RESULT);
            $fields = array_map(static function ($field) { return '`' . str_replace('`', '``', $field->name) . '`'; }, $result->fetch_fields());
            while ($row = $result->fetch_assoc()) {
                $values = array_map(static function ($value) use ($db) {
                    return $value === null ? 'NULL' : "'" . $db->real_escape_string((string) $value) . "'";
                }, array_values($row));
                appBackupWrite($handle, 'INSERT INTO ' . $quotedTable . ' (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $values) . ");\n");
            }
            $result->free();
        }
        appBackupWrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n-- END FURNIQUEST SQL BACKUP\n");
        $db->commit();
        if (!fclose($handle) || !is_file($path) || filesize($path) === 0) {
            throw new RuntimeException('Could not finish the database backup.');
        }
    } catch (Throwable $error) {
        try { $db->rollback(); } catch (Throwable $ignored) {}
        if (is_resource($handle)) fclose($handle);
        @unlink($path);
        throw $error;
    }
    return $name;
}

function appRestoreDatabaseBackup(mysqli $db, string $name): void
{
    $path = appBackupPath($name);
    if ($path === null || !str_ends_with($name, '.sql')) {
        throw new RuntimeException('Database backup file not found.');
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false || ($lines[0] ?? '') !== '-- FURNIQUEST SQL BACKUP v1'
        || end($lines) !== '-- END FURNIQUEST SQL BACKUP') {
        throw new RuntimeException('Database backup is incomplete.');
    }
    $backupTables = [];
    foreach ($lines as $line) {
        if ($line === '' || str_starts_with($line, '--')) continue;
        if (!str_ends_with($line, ';')) throw new RuntimeException('Database backup contains an incomplete statement.');
        if (preg_match('/^DELETE FROM `([^`]+)`;$/', $line, $matches)) {
            $backupTables[] = $matches[1];
            continue;
        }
        if (str_starts_with($line, 'CREATE TABLE IF NOT EXISTS ') || str_starts_with($line, 'INSERT INTO ')
            || in_array($line, ['SET NAMES utf8mb4;', 'SET FOREIGN_KEY_CHECKS=0;', 'SET FOREIGN_KEY_CHECKS=1;'], true)) continue;
        throw new RuntimeException('Database backup contains an invalid statement.');
    }
    sort($backupTables, SORT_STRING);
    if ($backupTables === [] || $backupTables !== appBackupDatabaseTables($db)) {
        throw new RuntimeException('Database backup does not match the current table set.');
    }
    $foreignKeyChecks = (int) $db->query('SELECT @@FOREIGN_KEY_CHECKS')->fetch_row()[0];
    try {
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        $db->begin_transaction();
        foreach ($lines as $line) {
            if (str_starts_with($line, 'DELETE FROM ') || str_starts_with($line, 'INSERT INTO ')) {
                $db->query($line);
            }
        }
        $db->commit();
    } catch (Throwable $error) {
        $db->rollback();
        throw new RuntimeException('Database restore failed; current data was kept.', 0, $error);
    } finally {
        $db->query('SET FOREIGN_KEY_CHECKS=' . $foreignKeyChecks);
    }
}

function appCreateBackupArchive(array $store, string $uploadDir): string
{
    $directory = appBackupDirectory();
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the backup folder.');
    }

    $name = 'rn-backup-' . (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Ymd-His')
        . '-' . bin2hex(random_bytes(4)) . '.zip';
    $path = $directory . DIRECTORY_SEPARATOR . $name;
    $archive = new ZipArchive();
    if ($archive->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
        throw new RuntimeException('Could not create the backup file.');
    }

    $json = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $archive->close();
        @unlink($path);
        throw new RuntimeException('Could not encode the backup data.');
    }
    $archive->addFromString('data.json', $json);
    $archive->addFromString('README.txt', "RN Furniture backup\n\nRestore in Admin Settings restores data.json and uploads. App source is included for manual recovery. Keep this file private: it contains account and order data.\n");
    foreach (['php', 'css', 'js'] as $extension) {
        foreach (glob(__DIR__ . DIRECTORY_SEPARATOR . '*.' . $extension) ?: [] as $sourceFile) {
            if (is_file($sourceFile) && basename($sourceFile) !== 'config.php') {
                $archive->addFile($sourceFile, 'app/' . basename($sourceFile));
            }
        }
    }
    foreach (glob($uploadDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        if (is_file($file) && !is_link($file)) {
            $archive->addFile($file, 'uploads/' . basename($file));
        }
    }
    if (!$archive->close() || !is_file($path) || filesize($path) === 0) {
        @unlink($path);
        throw new RuntimeException('Could not finish the backup file.');
    }

    return $name;
}

function appRestoreBackupArchive(string $name, string $uploadDir): void
{
    $path = appBackupPath($name);
    if ($path === null) {
        throw new RuntimeException('Backup file not found.');
    }

    $archive = new ZipArchive();
    if ($archive->open($path) !== true) {
        throw new RuntimeException('Could not open the backup file.');
    }
    $json = $archive->getFromName('data.json');
    $store = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($store) || !isset($store['users'], $store['products'], $store['orders'], $store['messages'], $store['customization_requests'], $store['settings'])) {
        $archive->close();
        throw new RuntimeException('Backup data is incomplete.');
    }
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
        $archive->close();
        throw new RuntimeException('Could not create the uploads folder.');
    }

    for ($index = 0; $index < $archive->numFiles; $index++) {
        $entry = $archive->getNameIndex($index);
        if (!is_string($entry) || !str_starts_with($entry, 'uploads/')) {
            continue;
        }
        $filename = substr($entry, strlen('uploads/'));
        if ($filename === '' || basename($filename) !== $filename || !preg_match('/^[a-zA-Z0-9._-]+$/', $filename)) {
            $archive->close();
            throw new RuntimeException('Backup contains an invalid upload path.');
        }
        $contents = $archive->getFromIndex($index);
        if ($contents === false || file_put_contents($uploadDir . DIRECTORY_SEPARATOR . $filename, $contents) === false) {
            $archive->close();
            throw new RuntimeException('Could not restore an uploaded file.');
        }
    }
    $archive->close();
    appSaveStore($store);
}
