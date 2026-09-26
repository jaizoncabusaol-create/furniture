<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db.php';

$db = appDb();
$database = 'lumber_palar';
$tables = [];
$result = $db->query('SHOW TABLES');

while ($row = $result->fetch_row()) {
    $tables[] = $row[0];
}

$sql = [];
$sql[] = "CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;";
$sql[] = "USE `{$database}`;";
$sql[] = '';
$sql[] = 'SET FOREIGN_KEY_CHECKS=0;';
$sql[] = '';

foreach ($tables as $table) {
    $createResult = $db->query("SHOW CREATE TABLE `{$table}`");
    $createRow = $createResult->fetch_assoc();
    $createSql = $createRow['Create Table'] ?? '';

    $sql[] = "DROP TABLE IF EXISTS `{$table}`;";
    $sql[] = $createSql . ';';
    $sql[] = '';

    $rows = $db->query("SELECT * FROM `{$table}`");
    while ($dataRow = $rows->fetch_assoc()) {
        $columns = array_map(static function (string $column): string {
            return '`' . str_replace('`', '``', $column) . '`';
        }, array_keys($dataRow));

        $values = array_map(static function ($value) use ($db): string {
            if ($value === null) {
                return 'NULL';
            }

            return "'" . $db->real_escape_string((string) $value) . "'";
        }, array_values($dataRow));

        $sql[] = 'INSERT INTO `' . $table . '` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ');';
    }

    if ($rows->num_rows > 0) {
        $sql[] = '';
    }
}

$sql[] = 'SET FOREIGN_KEY_CHECKS=1;';
$sql[] = '';

file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . 'lumber_palar.sql', implode(PHP_EOL, $sql));

echo "Wrote lumber_palar.sql\n";
