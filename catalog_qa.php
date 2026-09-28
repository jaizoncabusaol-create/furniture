<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/db.php';

function catalogQaKey(string $value): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function catalogQaImageFile(string $image): ?string
{
    $image = trim(str_replace('\\', '/', $image));
    if ($image === '' || preg_match('#^https?://#i', $image)) {
        return null;
    }

    $path = __DIR__ . '/' . ltrim($image, '/');
    $real = realpath($path);
    $root = realpath(__DIR__);
    if ($real === false || $root === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
        return null;
    }

    return $real;
}

function catalogQaGroups(array $products, callable $keyForProduct): array
{
    $groups = [];
    foreach ($products as $product) {
        $key = (string) $keyForProduct($product);
        if ($key === '') {
            continue;
        }
        $groups[$key][] = [
            'id' => (string) ($product['id'] ?? ''),
            'name' => (string) ($product['name'] ?? ''),
            'category' => (string) ($product['category'] ?? ''),
            'image' => (string) ($product['image'] ?? ''),
        ];
    }

    return array_filter($groups, static fn(array $items): bool => count($items) > 1);
}

$store = appLoadStore();
$products = array_values(array_filter($store['products'] ?? [], 'is_array'));
$categories = array_values(array_filter(array_map(
    static fn($category): string => trim((string) (($category['name'] ?? $category))),
    $store['settings']['categories'] ?? []
)));

$categoryCounts = array_fill_keys($categories, 0);
$unknownCategories = [];
$missingNames = [];
$missingImages = [];
$unreadableImages = [];
$imageHashes = [];

foreach ($products as $product) {
    $id = (string) ($product['id'] ?? '');
    $name = trim((string) ($product['name'] ?? ''));
    $category = trim((string) ($product['category'] ?? ''));
    $image = trim((string) ($product['image'] ?? ''));

    if ($name === '') {
        $missingNames[] = $id;
    }
    if ($image === '') {
        $missingImages[] = $id;
    }
    if (array_key_exists($category, $categoryCounts)) {
        $categoryCounts[$category]++;
    } else {
        $unknownCategories[$category === '' ? '(blank)' : $category][] = $id;
    }

    if ($image !== '') {
        $file = catalogQaImageFile($image);
        if ($file === null || !is_readable($file)) {
            $unreadableImages[] = ['id' => $id, 'image' => $image];
        } else {
            $hash = hash_file('sha256', $file);
            if ($hash !== false) {
                $imageHashes[$hash][] = [
                    'id' => $id,
                    'name' => $name,
                    'category' => $category,
                    'image' => $image,
                ];
            }
        }
    }
}

$report = [
    'product_count' => count($products),
    'category_count' => count($categories),
    'category_counts' => $categoryCounts,
    'empty_categories' => array_keys(array_filter($categoryCounts, static fn(int $count): bool => $count === 0)),
    'duplicate_names' => catalogQaGroups($products, static fn(array $product): string => catalogQaKey((string) ($product['name'] ?? ''))),
    'duplicate_image_paths' => catalogQaGroups($products, static fn(array $product): string => catalogQaKey((string) ($product['image'] ?? ''))),
    'duplicate_image_files' => array_filter($imageHashes, static fn(array $items): bool => count($items) > 1),
    'missing_names' => $missingNames,
    'missing_images' => $missingImages,
    'unreadable_images' => $unreadableImages,
    'unknown_categories' => $unknownCategories,
];

$report['passed'] = $report['empty_categories'] === []
    && $report['duplicate_names'] === []
    && $report['duplicate_image_paths'] === []
    && $report['duplicate_image_files'] === []
    && $report['missing_names'] === []
    && $report['missing_images'] === []
    && $report['unreadable_images'] === []
    && $report['unknown_categories'] === [];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

if (PHP_SAPI === 'cli' && !$report['passed']) {
    exit(1);
}

