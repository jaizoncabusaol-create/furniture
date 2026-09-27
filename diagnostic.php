<?php

require_once __DIR__ . '/db.php';

try {
    $db = appDb();
    echo 'products=' . appDbValue($db, 'SELECT COUNT(*) FROM products') . PHP_EOL;
    echo 'catalog_images=' . appDbValue($db, "SELECT COUNT(*) FROM products WHERE image LIKE 'uploads/fur_clean_%'") . PHP_EOL;
    echo 'png_images=' . appDbValue($db, "SELECT COUNT(*) FROM products WHERE image LIKE '%.png'") . PHP_EOL;
    echo 'seed_version=' . appDbValue($db, "SELECT option_value FROM settings_options WHERE option_name = 'catalog_seed_version'") . PHP_EOL;
} catch (Throwable $error) {
    echo get_class($error) . ': ' . $error->getMessage() . PHP_EOL;
}
