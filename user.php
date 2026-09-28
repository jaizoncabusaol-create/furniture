<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'session.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'db.php';

if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header('Location: index.php');
    exit;
}

if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

function setUserFlashNotice(string $message, string $type = 'success'): void
{
    $_SESSION['user_flash_notice'] = [
        'message' => $message,
        'type' => $type,
    ];
}

$currentUser = is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : [];
$currentUser += [
    'name' => 'User',
    'email' => '',
    'role' => 'user',
];
$flashNotice = $_SESSION['user_flash_notice'] ?? null;
unset($_SESSION['user_flash_notice']);
$notice = is_array($flashNotice) ? (string) ($flashNotice['message'] ?? '') : '';
$noticeType = is_array($flashNotice) ? (string) ($flashNotice['type'] ?? 'success') : 'success';
$uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
$uploadWebPath = 'uploads';
$search = trim($_GET['search'] ?? '');
$selectedCategory = trim($_GET['category'] ?? '');
$showAllProducts = trim($_GET['show'] ?? '') === 'all';
if (strcasecmp($selectedCategory, 'all') === 0) {
    $selectedCategory = '';
    $search = '';
    $showAllProducts = true;
}
$selectedView = trim($_GET['view'] ?? 'home');
$selectedOrderType = ($_GET['order_type'] ?? '') === 'customization' ? 'customization' : 'normal';
$selectedOrderId = trim((string) ($_GET['order'] ?? ''));
$selectedProductId = trim($_GET['product'] ?? '');
$selectedCategoryPopup = trim($_GET['catpopup'] ?? '');
$showFilterPopup = trim($_GET['filter'] ?? '') === 'categories';
$showProfileEditModal = false;
$showPasswordModal = false;
$showNotificationModal = false;
$showIncompleteOrderModal = false;
$customizationFormProductId = trim((string) ($_GET['customize_product'] ?? ''));
$requestedCustomizeCategory = trim((string) ($_GET['customize_category'] ?? ''));
$openCustomizeTab = $customizationFormProductId !== '' || $requestedCustomizeCategory !== '';

function normalizeCategories(array $categories): array
{
    $normalized = [];

    foreach ($categories as $category) {
        if (is_string($category)) {
            $name = trim($category);
            if ($name === '') {
                continue;
            }

            $normalized[] = [
                'name' => $name,
                'icon' => categoryIconUrl($name),
            ];
            continue;
        }

        if (!is_array($category)) {
            continue;
        }

        $name = trim((string) ($category['name'] ?? ''));
        if ($name === '') {
            continue;
        }

        $normalized[] = [
            'name' => $name,
            'icon' => trim((string) ($category['icon'] ?? '')) !== '' ? trim((string) ($category['icon'] ?? '')) : categoryIconUrl($name),
        ];
    }

    return array_values($normalized);
}

function categoryIconUrl(string $categoryName): string
{
    $query = http_build_query([
        'name' => $categoryName,
        'background' => '12357f',
        'color' => 'ffffff',
        'size' => '128',
        'rounded' => 'true',
        'bold' => 'true',
        'format' => 'svg',
    ]);

    return 'https://ui-avatars.com/api/?' . $query;
}

function renderCategoryIcon(string $categoryName): string
{
    $icon = normalizeCategoryIcon($categoryName);

    if ($icon === 'sofa') {
        return '<svg class="category-svg" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v4H5v-4Z"/><path d="M7 10V8a2 2 0 0 1 2-2h1v4"/><path d="M14 6h1a2 2 0 0 1 2 2v2"/><path d="M4 16h16"/><path d="M6 16v2"/><path d="M18 16v2"/></svg>';
    }
    if ($icon === 'chair') {
        return '<svg class="category-svg" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5h8v6H8z"/><path d="M7 11h10v5H7z"/><path d="M9 16v3"/><path d="M15 16v3"/><path d="M6 16h12"/></svg>';
    }
    if ($icon === 'bed') {
        return '<svg class="category-svg" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 11h16v5H4z"/><path d="M4 8h7a2 2 0 0 1 2 2v1H4V8Z"/><path d="M4 16v2"/><path d="M20 16v2"/></svg>';
    }
    if ($icon === 'cabinet') {
        return '<svg class="category-svg" viewBox="0 0 24 24" aria-hidden="true"><rect x="7" y="4" width="10" height="16" rx="1.5"/><path d="M12 4v16"/><circle cx="10" cy="10" r=".7" fill="currentColor"/><circle cx="14" cy="10" r=".7" fill="currentColor"/></svg>';
    }
    if ($icon === 'table') {
        return '<svg class="category-svg" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 7h14v4H5z"/><path d="M9 11v7"/><path d="M15 11v7"/><path d="M7 18h10"/></svg>';
    }

    return '<svg class="category-svg" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 8h14v8H5z"/><path d="M8 16v2"/><path d="M16 16v2"/><path d="M8 8V6h8v2"/></svg>';
}

function findProductById(array $products, string $productId): array
{
    foreach ($products as $index => $product) {
        if (($product['id'] ?? '') === $productId) {
            return [$product, $index];
        }
    }

    return [null, null];
}

function formatChatTimestamp(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    try {
        $date = new DateTimeImmutable($value);
    } catch (Exception $exception) {
        return $value;
    }

    return $date->format('M j, Y g:i A');
}

function createUniqueOrderId(array $orders): string
{
    $prefix = 'ORD-' . date('Ymd') . '-';
    $used = [];

    foreach ($orders as $order) {
        $orderId = (string) ($order['id'] ?? '');
        if (str_starts_with($orderId, $prefix)) {
            $used[$orderId] = true;
        }
    }

    $sequence = count($used) + 1;
    do {
        $candidate = $prefix . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
        $sequence++;
    } while (isset($used[$candidate]));

    return $candidate;
}

function createOrderRecord(array $product, array $currentUser, int $quantity, array $orders): array
{
    $total = ((float) ($product['price'] ?? 0)) * $quantity;

    return [
        'id' => createUniqueOrderId($orders),
        'customer' => $currentUser['name'] ?? 'User',
        'customer_email' => $currentUser['email'] ?? '',
        'customer_phone' => trim((string) ($currentUser['phone'] ?? '')),
        'delivery_address' => trim((string) ($currentUser['address'] ?? '')),
        'product_id' => $product['id'] ?? '',
        'product_name' => $product['name'] ?? '',
        'product_image' => $product['image'] ?? '',
        'quantity' => $quantity,
        'date' => date('M d, Y'),
        'status' => 'New Order',
        'tone' => 'pending',
        'total' => number_format($total, 2, '.', ''),
        'created_at' => date('c'),
    ];
}

function createWishlistRecord(array $product, array $currentUser): array
{
    return [
        'id' => uniqid('wish_', true),
        'customer_email' => $currentUser['email'] ?? '',
        'product_id' => $product['id'] ?? '',
        'product_name' => $product['name'] ?? '',
        'price' => number_format((float) ($product['price'] ?? 0), 2, '.', ''),
        'image' => $product['image'] ?? '',
        'created_at' => date('c'),
        'updated_at' => date('c'),
    ];
}

function customizationTone(string $status): string
{
    return match ($status) {
        'Quotation Sent' => 'processing',
        'Approved', 'Ongoing', 'In Production', 'Ready' => 'accepted',
        'Down Payment Paid' => 'processing',
        'Completed' => 'completed',
        'Cancelled', 'Unclaimed', 'Rejected' => 'declined',
        default => 'pending',
    };
}

function findCustomizationRequestById(array $requests, string $requestId, string $email): array
{
    foreach ($requests as $index => $request) {
        if (($request['id'] ?? '') === $requestId && strtolower((string) ($request['customer_email'] ?? '')) === strtolower($email)) {
            return [$request, $index];
        }
    }

    return [null, null];
}

function uploadCustomizationImage(array $file, string $uploadDir, string $uploadWebPath, bool $required, string $prefix): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [
            'path' => '',
            'error' => $required ? 'Upload a reference image.' : '',
        ];
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return [
            'path' => '',
            'error' => uploadErrorMessage((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)),
        ];
    }

    $tmpName = $file['tmp_name'] ?? '';
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return [
            'path' => '',
            'error' => 'The uploaded image could not be verified.',
        ];
    }

    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $mimeType = (string) (mime_content_type($tmpName) ?: '');
    $allowedMimeMap = [
        'image/jpeg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'jfif'];
    if (!isset($allowedMimeMap[$mimeType]) || !in_array($extension, $allowedExtensions, true)) {
        return [
            'path' => '',
            'error' => 'Upload a valid image file: jpg, jpeg, jfif, png, webp, or gif.',
        ];
    }

    if (!ensureUploadDirectory($uploadDir)) {
        return [
            'path' => '',
            'error' => 'The uploads folder is not writable.',
        ];
    }

    $filename = uniqid($prefix, true) . '.' . $allowedMimeMap[$mimeType];
    $target = $uploadDir . DIRECTORY_SEPARATOR . $filename;

    if (!persistUploadedFile($tmpName, $target)) {
        return [
            'path' => '',
            'error' => 'The server could not move the uploaded image.',
        ];
    }

    return [
        'path' => $uploadWebPath . '/' . $filename,
        'error' => '',
    ];
}

function mixRoomDefinitions(): array
{
     return [];
}

function mixRoomAllowsProduct(string $room, array $product): bool
{
    $rooms = mixRoomDefinitions();
    if (!isset($rooms[$room]) || $room === '') {
        return true;
    }

    $category = mixProductVisualCategory($product);
    return in_array($category, $rooms[$room], true);
}

function mixProductColor(array $product): string
{
    $text = strtolower((string) (($product['name'] ?? '') . ' ' . ($product['description'] ?? '')));
    if (str_contains($text, 'raw') || str_contains($text, 'light') || str_contains($text, 'cream')) {
        return 'Natural Light Wood';
    }
    if (str_contains($text, 'dark')) {
        return 'Dark Brown';
    }
    if (str_contains($text, 'red')) {
        return 'Natural Brown / Red Accent';
    }

    return 'Natural Brown';
}

function mixProductSize(array $product): string
{
    if (preg_match('/^\s*(?:size|dimension|dimensions)\s*:?\s*([^\n]+)/im', (string) ($product['description'] ?? ''), $matches)) {
        return trim($matches[1]);
    }

    return 'Confirm with seller';
}

function mixProductVisualCategory(array $product): string
{
    $category = trim((string) ($product['category'] ?? ''));
    $name = strtolower((string) ($product['name'] ?? ''));

    if (in_array($category, ['Bed', 'Cabinet', 'Chair', 'Dining Set', 'Door', 'Sofa'], true)) {
        return $category;
    }

    if (str_contains($name, 'door')) {
        return 'Door';
    }
    if (str_contains($name, 'dining') || str_contains($name, 'table')) {
        return 'Dining Set';
    }

    return 'Furniture';
}

function mixProductBoardWidth(array $product): int
{
    return match (mixProductVisualCategory($product)) {
        'Sofa' => 220,
        'Bed' => 230,
        'Dining Set' => 170,
        'Door' => 120,
        'Cabinet' => 140,
        'Chair' => 105,
        default => 110,
    };
}

function mixLoadDesigns(string $email): array
{
    $db = appDb();
    $designs = appDbFetchAll($db, 'SELECT id, user_id, user_email, design_name, room_type, total_price, created_at, updated_at FROM mix_match_designs WHERE user_email = ? ORDER BY updated_at DESC, created_at DESC', [$email]);

    foreach ($designs as $index => $design) {
        $designs[$index]['items'] = appDbFetchAll($db, 'SELECT product_id, selected_color, selected_material, selected_size, quantity, price, position_x, position_y, rotation, scale_value, layer_order FROM mix_match_items WHERE design_id = ? ORDER BY layer_order ASC, id ASC', [(string) ($design['id'] ?? '')]);
    }

    return $designs;
}

function mixLoadDesign(string $designId, string $email): ?array
{
    $designs = mixLoadDesigns($email);
    foreach ($designs as $design) {
        if (($design['id'] ?? '') === $designId) {
            return $design;
        }
    }

    return null;
}

function mixCartAddItem(array &$store, array $currentUser, array $product, int $quantity): bool
{
    $store['carts'] = isset($store['carts']) && is_array($store['carts']) ? $store['carts'] : [];
    $email = (string) ($currentUser['email'] ?? '');
    $productId = (string) ($product['id'] ?? '');

    foreach ($store['carts'] as $index => $item) {
        if (($item['customer_email'] ?? '') === $email && ($item['product_id'] ?? '') === $productId) {
            $newQuantity = (int) ($item['quantity'] ?? 1) + $quantity;
            if ($newQuantity > (int) ($product['stock'] ?? 0)) {
                return false;
            }

            $store['carts'][$index]['quantity'] = $newQuantity;
            $store['carts'][$index]['updated_at'] = date('c');
            return true;
        }
    }

    if ($quantity > (int) ($product['stock'] ?? 0)) {
        return false;
    }

    array_unshift($store['carts'], [
        'id' => uniqid('cart_', true),
        'customer_email' => $email,
        'product_id' => $productId,
        'product_name' => $product['name'] ?? '',
        'price' => number_format((float) ($product['price'] ?? 0), 2, '.', ''),
        'image' => $product['image'] ?? '',
        'quantity' => $quantity,
        'created_at' => date('c'),
        'updated_at' => date('c'),
    ]);

    return true;
}

function mixDecodePayload(string $raw): array
{
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function normalizeCategoryIcon(string $category): string
{
    $category = strtolower(trim($category));
    if (str_contains($category, 'sofa')) {
        return 'sofa';
    }
    if (str_contains($category, 'chair')) {
        return 'chair';
    }
    if (str_contains($category, 'bed')) {
        return 'bed';
    }
    if (str_contains($category, 'cabinet') || str_contains($category, 'drawer') || str_contains($category, 'shelf')) {
        return 'cabinet';
    }
    if (str_contains($category, 'table') || str_contains($category, 'desk')) {
        return 'table';
    }
    return 'furniture';
}

function findUserRecordIndex(array $users, string $email): array
{
    foreach ($users as $index => $user) {
        if (($user['email'] ?? '') === $email) {
            return [$index, $user];
        }
    }

    return [null, null];
}

function findAdminPhoneNumber(array $users): string
{
    foreach ($users as $user) {
        if (($user['role'] ?? '') !== 'admin') {
            continue;
        }

        $phone = trim((string) ($user['phone'] ?? ''));
        if ($phone !== '') {
            return $phone;
        }
    }

    return '';
}

function appBuildPhoneHref(string $phone): string
{
    $phone = trim($phone);
    if ($phone === '') {
        return '#';
    }

    $href = preg_replace('/[^\d+]/', '', $phone) ?? '';
    $href = preg_replace('/(?!^)\+/', '', $href) ?? '';

    if ($href === '' || $href === '+') {
        return '#';
    }

    return 'tel:' . $href;
}

function sliderColorValue(string $value, string $fallback): string
{
    $value = trim($value);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : $fallback;
}

function sliderSizeValue($value, int $fallback, int $min, int $max): string
{
    $size = filter_var($value, FILTER_VALIDATE_INT);
    if ($size === false) {
        $size = $fallback;
    }

    return (string) max($min, min($max, (int) $size));
}

function normalizeSliderSettings(array $settings): array
{
    $defaults = appDefaultStore()['settings']['slider'];
    $settings = array_merge($defaults, $settings);
    $fit = (string) ($settings['image_fit'] ?? $defaults['image_fit']);

    if (!in_array($fit, ['contain', 'cover', 'fill'], true)) {
        $fit = $defaults['image_fit'];
    }

    return [
        'height' => sliderSizeValue($settings['height'] ?? null, (int) $defaults['height'], 120, 320),
        'mobile_height' => sliderSizeValue($settings['mobile_height'] ?? null, (int) $defaults['mobile_height'], 110, 260),
        'image_fit' => $fit,
        'background_color' => sliderColorValue((string) ($settings['background_color'] ?? ''), $defaults['background_color']),
        'card_color' => sliderColorValue((string) ($settings['card_color'] ?? ''), $defaults['card_color']),
        'dot_color' => sliderColorValue((string) ($settings['dot_color'] ?? ''), $defaults['dot_color']),
        'dot_active_color' => sliderColorValue((string) ($settings['dot_active_color'] ?? ''), $defaults['dot_active_color']),
    ];
}

function findAdminRecord(array $users): ?array
{
    foreach ($users as $user) {
        if (($user['role'] ?? '') === 'admin') {
            return $user;
        }
    }

    return null;
}

function countUnreadMessagesForUser(array $messages, string $email): int
{
    $normalizedEmail = strtolower(trim($email));
    if ($normalizedEmail === '') {
        return 0;
    }

    $count = 0;
    foreach ($messages as $message) {
        if (!empty($message['deleted_by_user']) || appMessageCustomizationId($message) !== '' || strtolower((string) ($message['to'] ?? '')) !== $normalizedEmail) {
            continue;
        }

        if (!appMessageReadByUser($message)) {
            $count++;
        }
    }

    return $count;
}

function countUnreadAdminMessagesForUser(array $messages, string $email): int
{
    $normalizedEmail = strtolower(trim($email));
    if ($normalizedEmail === '') {
        return 0;
    }

    $count = 0;
    foreach ($messages as $message) {
        $sender = strtolower(trim((string) ($message['from_email'] ?? '')));
        if (empty($message['deleted_by_user']) && appMessageCustomizationId($message) === '' && strtolower(trim((string) ($message['to'] ?? ''))) === $normalizedEmail
            && $sender !== '' && $sender !== 'system' && $sender !== $normalizedEmail
            && !appMessageReadByUser($message)) {
            $count++;
        }
    }

    return $count;
}

function markMessagesReadForUser(array &$store, string $email): bool
{
    $normalizedEmail = strtolower(trim($email));
    if ($normalizedEmail === '') {
        return false;
    }

    $updated = false;
    foreach (($store['messages'] ?? []) as $index => $message) {
        if (!empty($message['deleted_by_user']) || appMessageCustomizationId($message) !== '' || strtolower((string) ($message['to'] ?? '')) !== $normalizedEmail || appMessageReadByUser($message)) {
            continue;
        }

        $store['messages'][$index]['read_by_user'] = 1;
        $updated = true;
    }

    return $updated;
}

function buildUserMessageState(array $store, array $currentUser): array
{
    $currentUserEmail = (string) ($currentUser['email'] ?? '');
    $thread = array_values(array_filter(($store['messages'] ?? []), function ($message) use ($currentUserEmail) {
        return empty($message['deleted_by_user']) && appMessageCustomizationId($message) === '' && (strcasecmp((string) ($message['from_email'] ?? ''), $currentUserEmail) === 0
            || strcasecmp((string) ($message['to'] ?? ''), $currentUserEmail) === 0);
    }));
    $unreadCount = countUnreadMessagesForUser($store['messages'] ?? [], $currentUserEmail);
    $latestMessage = $thread !== [] ? $thread[0] : null;

    return [
        'unread_count' => $unreadCount,
        'admin_unread_count' => countUnreadAdminMessagesForUser($store['messages'] ?? [], $currentUserEmail),
        'thread_count' => count($thread),
        'latest_id' => (string) ($latestMessage['id'] ?? ''),
        'latest_at' => (string) ($latestMessage['created_at'] ?? ''),
        'signature' => sha1(json_encode([
            'latest_id' => (string) ($latestMessage['id'] ?? ''),
            'latest_at' => (string) ($latestMessage['created_at'] ?? ''),
            'unread_count' => $unreadCount,
            'thread_count' => count($thread),
        ])),
    ];
}

function uploadErrorMessage(int $errorCode): string
{
    return match ($errorCode) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The image file is too large.',
        UPLOAD_ERR_PARTIAL => 'The image upload was interrupted. Please try again.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server upload temp folder is missing.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not save the uploaded image.',
        UPLOAD_ERR_EXTENSION => 'The server blocked this uploaded image.',
        default => 'Upload a valid image file.',
    };
}

function ensureUploadDirectory(string $uploadDir): bool
{
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
        return false;
    }

    @chmod($uploadDir, 0777);

    return is_writable($uploadDir);
}

function persistUploadedFile(string $tmpName, string $target): bool
{
    if (move_uploaded_file($tmpName, $target)) {
        @chmod($target, 0644);
        return true;
    }

    if (@rename($tmpName, $target)) {
        @chmod($target, 0644);
        return true;
    }

    if (@copy($tmpName, $target)) {
        @unlink($tmpName);
        @chmod($target, 0644);
        return true;
    }

    return false;
}

function uploadProfileImage(array $file, string $uploadDir, string $uploadWebPath): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [
            'path' => null,
            'error' => '',
        ];
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return [
            'path' => null,
            'error' => uploadErrorMessage((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)),
        ];
    }

    $tmpName = $file['tmp_name'] ?? '';
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return [
            'path' => null,
            'error' => 'The uploaded image could not be verified.',
        ];
    }

    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $mimeType = (string) (mime_content_type($tmpName) ?: '');
    $allowedMimeMap = [
        'image/jpeg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'jfif'];
    if (!isset($allowedMimeMap[$mimeType]) || !in_array($extension, $allowedExtensions, true)) {
        return [
            'path' => null,
            'error' => 'Upload a valid image file: jpg, jpeg, jfif, png, webp, or gif.',
        ];
    }

    if (!ensureUploadDirectory($uploadDir)) {
        return [
            'path' => null,
            'error' => 'The uploads folder is not writable.',
        ];
    }

    $savedExtension = $allowedMimeMap[$mimeType];
    $filename = uniqid('usr_img_', true) . '.' . $savedExtension;
    $target = $uploadDir . DIRECTORY_SEPARATOR . $filename;

    if (!persistUploadedFile($tmpName, $target)) {
        return [
            'path' => null,
            'error' => 'The server could not move the uploaded image.',
        ];
    }

    return [
        'path' => $uploadWebPath . '/' . $filename,
        'error' => '',
    ];
}

function uploadChatImage(array $file, string $uploadDir, string $uploadWebPath): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [
            'path' => '',
            'error' => '',
        ];
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return [
            'path' => '',
            'error' => uploadErrorMessage((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)),
        ];
    }

    $tmpName = $file['tmp_name'] ?? '';
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return [
            'path' => '',
            'error' => 'The uploaded image could not be verified.',
        ];
    }

    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $mimeType = (string) (mime_content_type($tmpName) ?: '');
    $allowedMimeMap = [
        'image/jpeg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'jfif'];
    if (!isset($allowedMimeMap[$mimeType]) || !in_array($extension, $allowedExtensions, true)) {
        return [
            'path' => '',
            'error' => 'Upload a valid chat image: jpg, jpeg, jfif, png, webp, or gif.',
        ];
    }

    if (!ensureUploadDirectory($uploadDir)) {
        return [
            'path' => '',
            'error' => 'The uploads folder is not writable.',
        ];
    }

    $savedExtension = $allowedMimeMap[$mimeType];
    $filename = uniqid('chat_img_', true) . '.' . $savedExtension;
    $target = $uploadDir . DIRECTORY_SEPARATOR . $filename;

    if (!persistUploadedFile($tmpName, $target)) {
        return [
            'path' => '',
            'error' => 'The server could not move the uploaded chat image.',
        ];
    }

    return [
        'path' => $uploadWebPath . '/' . $filename,
        'error' => '',
    ];
}

function userHasCompleteOrderProfile(?array $userRecord): bool
{
    if (!is_array($userRecord)) {
        return false;
    }

    $name = trim((string) ($userRecord['name'] ?? ''));
    $email = trim((string) ($userRecord['email'] ?? ''));
    $phone = trim((string) ($userRecord['phone'] ?? ''));
    $address = trim((string) ($userRecord['address'] ?? ''));

    return $name !== ''
        && $email !== ''
        && $phone !== ''
        && strcasecmp($phone, 'Not provided') !== 0
        && $address !== ''
        && strcasecmp($address, 'Not provided') !== 0;
}

$store = appLoadStore();
[$currentUserIndex, $currentUserRecord] = findUserRecordIndex($store['users'] ?? [], (string) ($currentUser['email'] ?? ''));

if (($_GET['ajax'] ?? '') === 'message_status') {
    if (trim($_GET['mark_read'] ?? '') === '1' && markMessagesReadForUser($store, (string) ($currentUser['email'] ?? ''))) {
        appSaveStore($store);
        $store = appLoadStore();
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(buildUserMessageState($store, $currentUser));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_mix_design') {
        $roomType = trim((string) ($_POST['room_type'] ?? ''));
        $designName = trim((string) ($_POST['design_name'] ?? ''));
        $designId = trim((string) ($_POST['design_id'] ?? ''));
        $items = mixDecodePayload((string) ($_POST['items_payload'] ?? ''));
        $rooms = mixRoomDefinitions();
        $email = (string) ($currentUser['email'] ?? '');
        $userId = (int) appDbValue(appDb(), 'SELECT id FROM users WHERE email = ?', [$email]);
        $validItems = [];
        $productQuantities = [];
        $total = 0.0;

         if ($roomType !== '' && !isset($rooms[$roomType])) {
             $notice = 'Select a valid room category.';
             $noticeType = 'error';
         } elseif ($designName === '') {
            $notice = 'Enter a design name.';
            $noticeType = 'error';
        } elseif ($items === []) {
            $notice = 'Add at least one furniture item.';
            $noticeType = 'error';
        } else {
            foreach ($items as $item) {
                $productId = trim((string) ($item['product_id'] ?? ''));
                $quantity = max(1, min(99, (int) ($item['quantity'] ?? 1)));
                [$product] = findProductById($store['products'], $productId);

                if ($product === null || !mixRoomAllowsProduct($roomType, $product) || (int) ($product['stock'] ?? 0) < $quantity) {
                    $notice = 'Some furniture items are unavailable or not valid for this room.';
                    $noticeType = 'error';
                    break;
                }

                $price = (float) ($product['price'] ?? 0);
                $productQuantities[$productId] = ($productQuantities[$productId] ?? 0) + $quantity;
                if ($productQuantities[$productId] > (int) ($product['stock'] ?? 0)) {
                    $notice = 'Some furniture item quantities exceed available stock.';
                    $noticeType = 'error';
                    break;
                }

                $total += $price * $quantity;
                $validItems[] = [
                    'product_id' => $productId,
                    'selected_color' => substr(trim((string) ($item['selected_color'] ?? mixProductColor($product))), 0, 120),
                    'selected_material' => substr(trim((string) ($item['selected_material'] ?? ($product['material'] ?? ''))), 0, 120),
                    'selected_size' => substr(trim((string) ($item['selected_size'] ?? mixProductSize($product))), 0, 120),
                    'quantity' => $quantity,
                    'price' => $price,
                    'position_x' => max(0, min(100, (float) ($item['position_x'] ?? 50))),
                    'position_y' => max(0, min(100, (float) ($item['position_y'] ?? 70))),
                    'rotation' => max(-180, min(180, (float) ($item['rotation'] ?? 0))),
                    'scale_value' => max(0.45, min(2.2, (float) ($item['scale_value'] ?? 1))),
                    'layer_order' => max(1, min(999, (int) ($item['layer_order'] ?? (count($validItems) + 1)))),
                ];
            }

            if ($notice === '') {
                $db = appDb();
                $existing = $designId !== '' ? mixLoadDesign($designId, $email) : null;
                $saveId = $existing !== null ? $designId : uniqid('mix_', true);
                $createdAt = (string) (($existing['created_at'] ?? '') !== '' ? $existing['created_at'] : date('c'));

                $db->begin_transaction();
                try {
                    appDbExecute(
                        $db,
                        'INSERT INTO mix_match_designs (id, user_id, user_email, design_name, room_type, total_price, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE design_name = VALUES(design_name), room_type = VALUES(room_type), total_price = VALUES(total_price), updated_at = VALUES(updated_at)',
                        [$saveId, $userId, $email, $designName, $roomType, $total, $createdAt, date('c')]
                    );
                    appDbExecute($db, 'DELETE FROM mix_match_items WHERE design_id = ?', [$saveId]);

                    foreach ($validItems as $item) {
                        appDbExecute(
                            $db,
                            'INSERT INTO mix_match_items (design_id, product_id, selected_color, selected_material, selected_size, quantity, price, position_x, position_y, rotation, scale_value, layer_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                            [
                                $saveId,
                                $item['product_id'],
                                $item['selected_color'],
                                $item['selected_material'],
                                $item['selected_size'],
                                $item['quantity'],
                                $item['price'],
                                $item['position_x'],
                                $item['position_y'],
                                $item['rotation'],
                                $item['scale_value'],
                                $item['layer_order'],
                            ]
                        );
                    }

                    $db->commit();
                    setUserFlashNotice('Mix & Match design saved.');
                    header('Location: user.php?view=designs');
                    exit;
                } catch (Throwable $exception) {
                    $db->rollback();
                    $notice = 'Design could not be saved.';
                    $noticeType = 'error';
                }
            }
        }

        $selectedView = 'mix';
    }

    if ($action === 'delete_mix_design') {
        $designId = trim((string) ($_POST['design_id'] ?? ''));
        $email = (string) ($currentUser['email'] ?? '');
        $design = $designId !== '' ? mixLoadDesign($designId, $email) : null;

        if ($design === null) {
            $notice = 'Saved design not found.';
            $noticeType = 'error';
        } else {
            $db = appDb();
            appDbExecute($db, 'DELETE FROM mix_match_items WHERE design_id = ?', [$designId]);
            appDbExecute($db, 'DELETE FROM mix_match_designs WHERE id = ? AND user_email = ?', [$designId, $email]);
            setUserFlashNotice('Design deleted.');
            header('Location: user.php?view=designs');
            exit;
        }

        $selectedView = 'designs';
    }

    if ($action === 'add_mix_to_cart') {
        $roomType = trim((string) ($_POST['room_type'] ?? ''));
        $items = mixDecodePayload((string) ($_POST['items_payload'] ?? ''));
        $rooms = mixRoomDefinitions();
        $validProducts = [];
        $productQuantities = [];

         if (($roomType !== '' && !isset($rooms[$roomType])) || $items === []) {
            $notice = 'Add valid furniture before adding to cart.';
            $noticeType = 'error';
        } else {
            foreach ($items as $item) {
                $productId = trim((string) ($item['product_id'] ?? ''));
                $quantity = max(1, min(99, (int) ($item['quantity'] ?? 1)));
                [$product] = findProductById($store['products'], $productId);

                if ($product === null || !mixRoomAllowsProduct($roomType, $product) || (int) ($product['stock'] ?? 0) < $quantity) {
                    $notice = 'Some furniture items are out of stock or not valid for this room.';
                    $noticeType = 'error';
                    break;
                }

                $productQuantities[$productId] = ($productQuantities[$productId] ?? 0) + $quantity;
                if ($productQuantities[$productId] > (int) ($product['stock'] ?? 0)) {
                    $notice = 'Some furniture item quantities exceed available stock.';
                    $noticeType = 'error';
                    break;
                }

                $validProducts[] = [$product, $quantity];
            }

            if ($notice === '') {
                foreach ($validProducts as [$product, $quantity]) {
                    if (!mixCartAddItem($store, $currentUser, $product, $quantity)) {
                        $notice = 'Cart quantity exceeds available stock.';
                        $noticeType = 'error';
                        break;
                    }
                }

                if ($notice === '') {
                    appSaveStore($store);
                    setUserFlashNotice('Mix & Match items added to cart.');
                    header('Location: user.php?view=cart');
                    exit;
                }
            }
        }

        $selectedView = 'mix';
    }

    if ($action === 'submit_customization_request') {
        $category = trim((string) ($_POST['customize_category'] ?? ''));
        $preferredSize = substr(trim((string) ($_POST['preferred_size'] ?? '')), 0, 190);
        $selectedColors = is_array($_POST['colors'] ?? null) ? $_POST['colors'] : [];
        $allowedColors = ['White', 'Black', 'Gray', 'Brown', 'Beige', 'Red', 'Orange', 'Yellow', 'Green', 'Blue', 'Purple', 'Pink'];
        $selectedColors = array_values(array_unique(array_filter($selectedColors, static function ($value) use ($allowedColors) {
            return is_string($value) && in_array($value, $allowedColors, true);
        })));
        $otherColor = substr(trim((string) ($_POST['other_color'] ?? '')), 0, 60);
        $color = substr(implode(', ', array_merge($selectedColors, $otherColor !== '' ? [$otherColor] : [])), 0, 120);
        $material = substr(trim((string) ($_POST['material'] ?? '')), 0, 120);
        $quantity = max(1, min(99, (int) ($_POST['quantity'] ?? 1)));
        $validCategories = array_values(array_unique(array_filter(array_merge(
            array_map(static function ($item) { return (string) ($item['name'] ?? ''); }, $store['settings']['categories'] ?? []),
            array_map(static function ($item) { return (string) ($item['category'] ?? ''); }, $store['products'] ?? [])
        ))));

        if (!in_array($category, $validCategories, true)) {
            $notice = 'Select a valid furniture category to customize.';
            $noticeType = 'error';
        } elseif (!userHasCompleteOrderProfile($currentUserRecord)) {
            $notice = 'Complete your profile phone number and address before requesting customization.';
            $noticeType = 'error';
            $showIncompleteOrderModal = true;
        } elseif ($preferredSize === '' || $color === '' || $material === '') {
            $notice = 'Complete all customization fields.';
            $noticeType = 'error';
        } elseif (($referenceUpload = uploadCustomizationImage($_FILES['reference_image'] ?? [], $uploadDir, $uploadWebPath, false, 'cust_ref_'))['error'] !== '') {
            $notice = $referenceUpload['error'];
            $noticeType = 'error';
        } else {
            $store['customization_requests'] = isset($store['customization_requests']) && is_array($store['customization_requests']) ? $store['customization_requests'] : [];
            $request = [
                'id' => uniqid('cust_', true),
                'customer' => (string) ($currentUserRecord['name'] ?? $currentUser['name'] ?? 'User'),
                'customer_email' => (string) ($currentUserRecord['email'] ?? $currentUser['email'] ?? ''),
                'customer_phone' => trim((string) ($currentUserRecord['phone'] ?? '')),
                'delivery_address' => trim((string) ($currentUserRecord['address'] ?? '')),
                'product_id' => '',
                'product_name' => $category,
                'product_image' => '',
                'preferred_size' => $preferredSize,
                'color' => $color,
                'material' => $material,
                'quantity' => $quantity,
                'design_instructions' => '',
                'reference_image' => (string) ($referenceUpload['path'] ?? ''),
                'quotation_price' => '0.00',
                'down_payment' => '0.00',
                'estimated_completion_date' => '',
                'admin_notes' => '',
                'payment_proof' => '',
                'payment_confirmed' => 0,
                'status' => 'Pending',
                'tone' => 'pending',
                'created_at' => date('c'),
                'updated_at' => date('c'),
            ];
            array_unshift($store['customization_requests'], $request);
            $store['messages'] = isset($store['messages']) && is_array($store['messages']) ? $store['messages'] : [];
            array_unshift($store['messages'], appCreateMessageRecord(
                (string) ($request['customer'] ?? 'User'),
                (string) ($request['customer_email'] ?? ''),
                'admin',
                (string) ($request['product_id'] ?? ''),
                'Submitted a customization request: ' . (string) ($request['product_name'] ?? 'Furniture item') . ' (' . (string) ($request['id'] ?? '') . ').',
                0,
                1
            ));
            array_unshift($store['messages'], appCreateMessageRecord(
                'RN Furniture',
                'system',
                (string) ($request['customer_email'] ?? ''),
                (string) ($request['product_id'] ?? ''),
                'Customization request received: ' . (string) ($request['product_name'] ?? 'Furniture item') . ' (' . (string) ($request['id'] ?? '') . '). The admin will review your request and send a quotation.',
                1,
                0
            ));
            appSaveStore($store);
            setUserFlashNotice('Customization request submitted.');
            header('Location: user.php?view=orders&order_type=customization');
            exit;
        }

        $requestedCustomizeCategory = $category;
        $openCustomizeTab = true;
        $selectedView = 'mix';
    }

    if ($action === 'cancel_customization_request' || $action === 'delete_customization_request') {
        $requestId = trim((string) ($_POST['request_id'] ?? ''));
        [$request, $requestIndex] = findCustomizationRequestById($store['customization_requests'] ?? [], $requestId, (string) ($currentUser['email'] ?? ''));
        $status = (string) ($request['status'] ?? '');
        $canCancel = in_array($status, ['Pending', 'Quotation Sent'], true);
        $canDelete = $request !== null;

        if ($request === null || ($action === 'cancel_customization_request' && !$canCancel) || ($action === 'delete_customization_request' && !$canDelete)) {
            $notice = 'Customization request could not be updated.';
            $noticeType = 'error';
        } else {
            if ($action === 'cancel_customization_request') {
                $store['customization_requests'][$requestIndex]['status'] = 'Cancelled';
                $store['customization_requests'][$requestIndex]['tone'] = customizationTone('Cancelled');
                $store['customization_requests'][$requestIndex]['updated_at'] = date('c');
            } else {
                appArchiveDeletedRecord($store, 'customization', $request);
                array_splice($store['customization_requests'], $requestIndex, 1);
            }
            array_unshift($store['messages'], appCreateMessageRecord(
                (string) ($currentUser['name'] ?? 'User'),
                (string) ($currentUser['email'] ?? ''),
                'admin',
                (string) ($request['product_id'] ?? ''),
                'Customization request ' . ($action === 'cancel_customization_request' ? 'cancelled: ' : 'deleted: ') . (string) ($request['product_name'] ?? 'Furniture') . ' (' . $requestId . ').',
                0,
                1
            ));
            appSaveStore($store);
            setUserFlashNotice($action === 'cancel_customization_request' ? 'Customization request cancelled.' : 'Customization request deleted.');
            header('Location: user.php?view=orders&order_type=customization');
            exit;
        }
        $selectedView = 'orders';
    }

    if ($action === 'customer_customization_decision') {
        $requestId = trim((string) ($_POST['request_id'] ?? ''));
        $decision = trim((string) ($_POST['decision'] ?? ''));
        [$request, $requestIndex] = findCustomizationRequestById($store['customization_requests'] ?? [], $requestId, (string) ($currentUser['email'] ?? ''));

        if ($request === null || (string) ($request['status'] ?? '') !== 'Quotation Sent' || !in_array($decision, ['approve', 'reject'], true)
            || ($decision === 'approve' && (float) ($request['down_payment'] ?? 0) <= 0)) {
            $notice = 'Customization request could not be updated.';
            $noticeType = 'error';
        } else {
            $nextStatus = $decision === 'approve' ? 'Approved' : 'Cancelled';
            $store['customization_requests'][$requestIndex]['status'] = $nextStatus;
            $store['customization_requests'][$requestIndex]['tone'] = customizationTone($nextStatus);
            $store['customization_requests'][$requestIndex]['updated_at'] = date('c');
            array_unshift($store['messages'], appCreateMessageRecord(
                (string) ($currentUser['name'] ?? 'User'),
                (string) ($currentUser['email'] ?? ''),
                'admin',
                (string) ($request['product_id'] ?? ''),
                ($decision === 'approve' ? 'Customization order accepted: ' : 'Customization quotation cancelled: ') . (string) ($request['product_name'] ?? 'Furniture item') . ' (' . $requestId . ').',
                0,
                1
            ));
            appSaveStore($store);
            setUserFlashNotice($decision === 'approve'
                ? 'Quotation accepted. Pay P' . number_format((float) ($request['down_payment'] ?? 0), 2) . ' down payment and upload proof before work can begin.'
                : 'Quotation cancelled.');
            header('Location: user.php?view=orders&order_type=customization');
            exit;
        }

        $selectedView = 'orders';
    }

    if ($action === 'upload_customization_payment') {
        $requestId = trim((string) ($_POST['request_id'] ?? ''));
        [$request, $requestIndex] = findCustomizationRequestById($store['customization_requests'] ?? [], $requestId, (string) ($currentUser['email'] ?? ''));
        $proofUpload = uploadCustomizationImage($_FILES['payment_proof'] ?? [], $uploadDir, $uploadWebPath, true, 'cust_pay_');

        if ($request === null || (string) ($request['status'] ?? '') !== 'Approved') {
            $notice = 'Payment proof can only be uploaded for approved quotations.';
            $noticeType = 'error';
        } elseif ($proofUpload['error'] !== '') {
            $notice = $proofUpload['error'];
            $noticeType = 'error';
        } else {
            $store['customization_requests'][$requestIndex]['payment_proof'] = (string) ($proofUpload['path'] ?? '');
            $store['customization_requests'][$requestIndex]['status'] = 'Down Payment Paid';
            $store['customization_requests'][$requestIndex]['payment_confirmed'] = 0;
            $store['customization_requests'][$requestIndex]['tone'] = customizationTone('Down Payment Paid');
            $store['customization_requests'][$requestIndex]['updated_at'] = date('c');
            array_unshift($store['messages'], appCreateMessageRecord(
                (string) ($currentUser['name'] ?? 'User'),
                (string) ($currentUser['email'] ?? ''),
                'admin',
                (string) ($request['product_id'] ?? ''),
                'Down payment proof sent for customization: ' . (string) ($request['product_name'] ?? 'Furniture item') . ' (' . $requestId . ').',
                0,
                1
            ));
            appSaveStore($store);
            setUserFlashNotice('Payment proof sent. Awaiting admin confirmation.');
            header('Location: user.php?view=orders&order_type=customization');
            exit;
        }

        $selectedView = 'orders';
    }

    if ($action === 'add_to_cart') {
        $productId = trim($_POST['product_id'] ?? '');
        $quantity = max(1, (int) ($_POST['quantity'] ?? 1));
        [$selectedProduct] = findProductById($store['products'], $productId);

        if ($selectedProduct === null) {
            $notice = 'Furniture item not found.';
            $noticeType = 'error';
        } elseif (($selectedProduct['stock'] ?? 0) < $quantity) {
            $notice = 'Not enough stock available.';
            $noticeType = 'error';
        } else {
            $store['carts'] = isset($store['carts']) && is_array($store['carts']) ? $store['carts'] : [];
            $updated = false;

            foreach ($store['carts'] as $index => $item) {
                if (($item['customer_email'] ?? '') === ($currentUser['email'] ?? '') && ($item['product_id'] ?? '') === $productId) {
                    $newQuantity = (int) ($item['quantity'] ?? 1) + $quantity;
                    if ($newQuantity > (int) ($selectedProduct['stock'] ?? 0)) {
                        $notice = 'Cart quantity exceeds available stock.';
                        $noticeType = 'error';
                    } else {
                        $store['carts'][$index]['quantity'] = $newQuantity;
                        $store['carts'][$index]['updated_at'] = date('c');
                        appSaveStore($store);
                        setUserFlashNotice('This product is already in your cart. Quantity updated.');
                        header('Location: user.php?view=cart');
                        exit;
                    }
                    $updated = true;
                    break;
                }
            }

            if (!$updated && $notice === '') {
                array_unshift($store['carts'], [
                    'id' => uniqid('cart_', true),
                    'customer_email' => $currentUser['email'] ?? '',
                    'product_id' => $selectedProduct['id'] ?? '',
                    'product_name' => $selectedProduct['name'] ?? '',
                    'price' => number_format((float) ($selectedProduct['price'] ?? 0), 2, '.', ''),
                    'image' => $selectedProduct['image'] ?? '',
                    'quantity' => $quantity,
                    'created_at' => date('c'),
                ]);
                appSaveStore($store);
                setUserFlashNotice('Product added to cart.');
                header('Location: user.php?view=cart');
                exit;
            }
        }
    }

    if ($action === 'add_to_wishlist') {
        $productId = trim($_POST['product_id'] ?? '');
        [$selectedProduct] = findProductById($store['products'], $productId);

        if ($selectedProduct === null) {
            $notice = 'Furniture item not found.';
            $noticeType = 'error';
        } else {
            $store['wishlists'] = isset($store['wishlists']) && is_array($store['wishlists']) ? $store['wishlists'] : [];
            $alreadySaved = false;

            foreach ($store['wishlists'] as $item) {
                if (($item['customer_email'] ?? '') === ($currentUser['email'] ?? '') && ($item['product_id'] ?? '') === $productId) {
                    $alreadySaved = true;
                    break;
                }
            }

            if ($alreadySaved) {
                $notice = 'This product is already in your wishlist.';
                $noticeType = 'success';
            } else {
                array_unshift($store['wishlists'], createWishlistRecord($selectedProduct, $currentUser));
                appSaveStore($store);
                $notice = 'Product added to wishlist.';
                $noticeType = 'success';
            }

            $selectedView = 'home';
        }
    }

    if ($action === 'order_product') {
        $productId = trim($_POST['product_id'] ?? '');
        $quantity = max(1, (int) ($_POST['quantity'] ?? 1));
        [$selectedProduct, $selectedProductIndex] = findProductById($store['products'], $productId);

        if ($selectedProduct === null) {
            $notice = 'Furniture item not found.';
            $noticeType = 'error';
        } elseif (!userHasCompleteOrderProfile($currentUserRecord)) {
            $notice = 'Complete your profile phone number and address before placing an order.';
            $noticeType = 'error';
            $showIncompleteOrderModal = true;
        } elseif (($selectedProduct['stock'] ?? 0) < $quantity) {
            $notice = 'Not enough stock available.';
            $noticeType = 'error';
        } else {
            $newOrder = createOrderRecord($selectedProduct, $currentUserRecord ?? $currentUser, $quantity, $store['orders'] ?? []);
            $store['products'][$selectedProductIndex]['stock'] = (int) ($selectedProduct['stock'] ?? 0) - $quantity;
            array_unshift($store['orders'], $newOrder);
            $store['messages'] = isset($store['messages']) && is_array($store['messages']) ? $store['messages'] : [];
            array_unshift($store['messages'], appCreateMessageRecord(
                (string) ($currentUserRecord['name'] ?? $currentUser['name'] ?? 'User'),
                (string) ($currentUserRecord['email'] ?? $currentUser['email'] ?? ''),
                'admin',
                (string) ($newOrder['product_id'] ?? ''),
                'Placed a new order: ' . (string) ($newOrder['product_name'] ?? 'Furniture item') . ' (' . (string) ($newOrder['id'] ?? '') . ').',
                0,
                1
            ));
            appSaveStore($store);
            header('Location: user.php?view=orders');
            exit;
        }
    }

    if ($action === 'place_cart_order') {
        $cartId = trim($_POST['cart_id'] ?? '');
        $selectedCart = null;
        $selectedCartIndex = null;

        foreach (($store['carts'] ?? []) as $index => $item) {
            if (($item['id'] ?? '') === $cartId && ($item['customer_email'] ?? '') === ($currentUser['email'] ?? '')) {
                $selectedCart = $item;
                $selectedCartIndex = $index;
                break;
            }
        }

        if ($selectedCart === null) {
            $notice = 'Cart item not found.';
            $noticeType = 'error';
        } else {
            [$selectedProduct, $selectedProductIndex] = findProductById($store['products'], (string) ($selectedCart['product_id'] ?? ''));
            $quantity = max(1, (int) ($selectedCart['quantity'] ?? 1));

            if ($selectedProduct === null) {
                $notice = 'Furniture item not found.';
                $noticeType = 'error';
            } elseif (!userHasCompleteOrderProfile($currentUserRecord)) {
                $notice = 'Complete your profile phone number and address before placing an order.';
                $noticeType = 'error';
                $selectedView = 'cart';
                $showIncompleteOrderModal = true;
            } elseif (($selectedProduct['stock'] ?? 0) < $quantity) {
                $notice = 'Not enough stock available.';
                $noticeType = 'error';
            } else {
                $newOrder = createOrderRecord($selectedProduct, $currentUserRecord ?? $currentUser, $quantity, $store['orders'] ?? []);
                $store['products'][$selectedProductIndex]['stock'] = (int) ($selectedProduct['stock'] ?? 0) - $quantity;
                array_unshift($store['orders'], $newOrder);
                $store['messages'] = isset($store['messages']) && is_array($store['messages']) ? $store['messages'] : [];
                array_unshift($store['messages'], appCreateMessageRecord(
                    (string) ($currentUserRecord['name'] ?? $currentUser['name'] ?? 'User'),
                    (string) ($currentUserRecord['email'] ?? $currentUser['email'] ?? ''),
                    'admin',
                    (string) ($newOrder['product_id'] ?? ''),
                    'Placed a new order: ' . (string) ($newOrder['product_name'] ?? 'Furniture item') . ' (' . (string) ($newOrder['id'] ?? '') . ').',
                    0,
                    1
                ));
                array_splice($store['carts'], $selectedCartIndex, 1);
                appSaveStore($store);
                header('Location: user.php?view=orders');
                exit;
            }
        }
    }

    if ($action === 'cancel_order') {
        $orderId = trim($_POST['order_id'] ?? '');
        $updated = false;

        foreach (($store['orders'] ?? []) as $index => $order) {
            if (
                ($order['id'] ?? '') === $orderId &&
                ($order['customer_email'] ?? '') === ($currentUser['email'] ?? '') &&
                in_array((string) ($order['status'] ?? ''), ['New Order', 'Pending', 'Accepted'], true)
            ) {
                $store['orders'][$index]['status'] = 'Declined';
                $store['orders'][$index]['tone'] = 'declined';
                $store['orders'][$index]['updated_at'] = date('c');
                $updated = true;
                break;
            }
        }

        if ($updated) {
            appSaveStore($store);
            setUserFlashNotice('Order cancelled.');
            header('Location: user.php?view=orders&order_type=normal');
            exit;
        }

        $notice = 'Order could not be cancelled.';
        $noticeType = 'error';
        $selectedView = 'orders';
    }

    if ($action === 'delete_completed_order' || $action === 'delete_user_order') {
        $orderId = trim($_POST['order_id'] ?? '');
        $matchedOrder = false;
        foreach (($store['orders'] ?? []) as $order) {
            if (($order['id'] ?? '') === $orderId
                && ($order['customer_email'] ?? '') === ($currentUser['email'] ?? '')) {
                appArchiveDeletedRecord($store, 'order', $order);
                $matchedOrder = true;
                break;
            }
        }
        $beforeCount = count($store['orders'] ?? []);
        $store['orders'] = array_values(array_filter(($store['orders'] ?? []), function ($order) use ($orderId, $currentUser) {
            return !(
                ($order['id'] ?? '') === $orderId &&
                ($order['customer_email'] ?? '') === ($currentUser['email'] ?? '')
            );
        }));

        if ($matchedOrder && count($store['orders']) !== $beforeCount) {
            appSaveStore($store);
            setUserFlashNotice('Order deleted.');
            header('Location: user.php?view=orders');
            exit;
        }

        $notice = 'Order could not be deleted.';
        $noticeType = 'error';
        $selectedView = 'orders';
    }

    if ($action === 'remove_cart_item') {
        $cartId = trim($_POST['cart_id'] ?? '');
        $store['carts'] = array_values(array_filter(($store['carts'] ?? []), function ($item) use ($cartId, $currentUser) {
            return !(($item['id'] ?? '') === $cartId && ($item['customer_email'] ?? '') === ($currentUser['email'] ?? ''));
        }));
        appSaveStore($store);
        header('Location: user.php?view=cart');
        exit;
    }

    if ($action === 'remove_wishlist_item') {
        $wishlistId = trim($_POST['wishlist_id'] ?? '');
        $store['wishlists'] = array_values(array_filter(($store['wishlists'] ?? []), function ($item) use ($wishlistId, $currentUser) {
            return !(($item['id'] ?? '') === $wishlistId && ($item['customer_email'] ?? '') === ($currentUser['email'] ?? ''));
        }));
        appSaveStore($store);
        header('Location: user.php?view=wishlist');
        exit;
    }

    if ($action === 'wishlist_to_cart') {
        $wishlistId = trim($_POST['wishlist_id'] ?? '');
        $selectedWishlist = null;
        $selectedWishlistIndex = null;

        foreach (($store['wishlists'] ?? []) as $index => $item) {
            if (($item['id'] ?? '') === $wishlistId && ($item['customer_email'] ?? '') === ($currentUser['email'] ?? '')) {
                $selectedWishlist = $item;
                $selectedWishlistIndex = $index;
                break;
            }
        }

        if ($selectedWishlist === null) {
            $notice = 'Wishlist item not found.';
            $noticeType = 'error';
        } else {
            [$selectedProduct] = findProductById($store['products'], (string) ($selectedWishlist['product_id'] ?? ''));

            if ($selectedProduct === null) {
                $notice = 'Furniture item not found.';
                $noticeType = 'error';
            } elseif ((int) ($selectedProduct['stock'] ?? 0) < 1) {
                $notice = 'This product is out of stock.';
                $noticeType = 'error';
            } else {
                $store['carts'] = isset($store['carts']) && is_array($store['carts']) ? $store['carts'] : [];
                $cartUpdated = false;

                foreach ($store['carts'] as $index => $cartItem) {
                    if (($cartItem['customer_email'] ?? '') === ($currentUser['email'] ?? '') && ($cartItem['product_id'] ?? '') === ($selectedWishlist['product_id'] ?? '')) {
                        if ((int) ($cartItem['quantity'] ?? 1) >= (int) ($selectedProduct['stock'] ?? 0)) {
                            $notice = 'Cart quantity already matches available stock.';
                            $noticeType = 'error';
                        } else {
                            $store['carts'][$index]['quantity'] = (int) ($cartItem['quantity'] ?? 1) + 1;
                            $store['carts'][$index]['updated_at'] = date('c');
                            array_splice($store['wishlists'], $selectedWishlistIndex, 1);
                            appSaveStore($store);
                            header('Location: user.php?view=cart');
                            exit;
                        }
                        $cartUpdated = true;
                        break;
                    }
                }

                if (!$cartUpdated && $notice === '') {
                    array_unshift($store['carts'], [
                        'id' => uniqid('cart_', true),
                        'customer_email' => $currentUser['email'] ?? '',
                        'product_id' => $selectedWishlist['product_id'] ?? '',
                        'product_name' => $selectedWishlist['product_name'] ?? '',
                        'price' => number_format((float) ($selectedWishlist['price'] ?? 0), 2, '.', ''),
                        'image' => $selectedWishlist['image'] ?? '',
                        'quantity' => 1,
                        'created_at' => date('c'),
                        'updated_at' => date('c'),
                    ]);
                    array_splice($store['wishlists'], $selectedWishlistIndex, 1);
                    appSaveStore($store);
                    header('Location: user.php?view=cart');
                    exit;
                }
            }
        }
    }

    if ($action === 'contact_admin') {
        $productId = trim($_POST['product_id'] ?? '');
        $message = appDisplayChatMessage(trim((string) ($_POST['message'] ?? '')));
        $customizationMessageId = trim((string) ($_POST['customization_id'] ?? ''));
        $customizationMessageValid = true;
        if ($customizationMessageId !== '') {
            [$customizationMessageRequest] = findCustomizationRequestById($store['customization_requests'] ?? [], $customizationMessageId, (string) ($currentUser['email'] ?? ''));
            $customizationMessageValid = $customizationMessageRequest !== null;
        }
        $chatImageUpload = uploadChatImage($_FILES['message_image'] ?? [], $uploadDir, $uploadWebPath);
        $chatImagePath = trim((string) ($chatImageUpload['path'] ?? ''));
        $chatImageError = trim((string) ($chatImageUpload['error'] ?? ''));
        $redirectView = trim($_POST['redirect_view'] ?? 'home');
        if (!in_array($redirectView, ['home', 'messages'], true)) {
            $redirectView = 'home';
        }

        if ($productId !== '') {
            $selectedProductId = $productId;
        }

        if (!$customizationMessageValid) {
            $notice = 'Customization request not found.';
            $noticeType = 'error';
            $selectedView = 'orders';
        } elseif ($chatImageError !== '') {
            $notice = $chatImageError;
            $noticeType = 'error';
            $selectedView = $redirectView;
        } elseif ($message === '' && $chatImagePath === '') {
            $notice = 'Enter your message for admin.';
            $noticeType = 'error';
            $selectedView = $redirectView;
        } else {
            $store['messages'] = isset($store['messages']) && is_array($store['messages']) ? $store['messages'] : [];
            array_unshift($store['messages'], [
                'id' => uniqid('msg_', true),
                'from_name' => $currentUser['name'] ?? 'User',
                'from_email' => $currentUser['email'] ?? '',
                'to' => 'admin',
                'product_id' => $productId,
                'message' => $message . ($customizationMessageId !== '' ? ' (' . $customizationMessageId . ')' : ''),
                'image_path' => $chatImagePath,
                'created_at' => date('c'),
                'read_by_admin' => 0,
                'read_by_user' => 1,
            ]);
            appSaveStore($store);
            $notice = 'Message sent to admin.';
            $noticeType = 'success';
            $selectedView = $customizationMessageId !== '' ? 'orders' : $redirectView;
        }
    }

    if ($action === 'delete_user_chat_thread') {
        $currentUserEmail = strtolower((string) ($currentUser['email'] ?? ''));

        if ($currentUserEmail === '') {
            $notice = 'User conversation not found.';
            $noticeType = 'error';
            $selectedView = 'messages';
        } else {
            foreach (($store['messages'] ?? []) as $index => $message) {
                $fromEmail = strtolower((string) ($message['from_email'] ?? ''));
                $recipient = strtolower((string) ($message['to'] ?? ''));
                if ($fromEmail === $currentUserEmail || $recipient === $currentUserEmail) {
                    $store['messages'][$index]['deleted_by_user'] = 1;
                }
            }
            appSaveStore($store);
            header('Location: user.php?view=messages');
            exit;
        }
    }

    if ($action === 'restore_user_chat_thread') {
        $currentUserEmail = strtolower((string) ($currentUser['email'] ?? ''));
        $restored = false;
        foreach (($store['messages'] ?? []) as $index => $message) {
            if (!empty($message['deleted_by_user']) && (strtolower((string) ($message['from_email'] ?? '')) === $currentUserEmail || strtolower((string) ($message['to'] ?? '')) === $currentUserEmail)) {
                $store['messages'][$index]['deleted_by_user'] = 0;
                $restored = true;
            }
        }
        if ($restored) {
            appSaveStore($store);
            setUserFlashNotice('Chat restored.');
            header('Location: user.php?view=messages');
            exit;
        }
        $notice = 'This chat could not be restored.';
        $noticeType = 'error';
        $selectedView = 'messages';
    }

    if ($action === 'update_profile') {
        $updatedName = trim($_POST['profile_name'] ?? '');
        $updatedEmail = trim($_POST['profile_email'] ?? '');
        $updatedPhone = trim($_POST['profile_phone'] ?? '');
        $updatedAddress = trim($_POST['profile_address'] ?? '');
        $profileImageUpload = uploadProfileImage($_FILES['profile_image'] ?? [], $uploadDir, $uploadWebPath);
        $profileImagePath = $profileImageUpload['path'] ?? null;
        $profileImageError = trim((string) ($profileImageUpload['error'] ?? ''));

        if ($updatedName === '' || $updatedEmail === '') {
            $notice = 'Complete the profile fields.';
            $noticeType = 'error';
            $selectedView = 'profile';
            $showProfileEditModal = true;
        } elseif ($profileImageError !== '') {
            $notice = $profileImageError;
            $noticeType = 'error';
            $selectedView = 'profile';
            $showProfileEditModal = true;
        } elseif (!filter_var($updatedEmail, FILTER_VALIDATE_EMAIL)) {
            $notice = 'Enter a valid email address.';
            $noticeType = 'error';
            $selectedView = 'profile';
            $showProfileEditModal = true;
        } elseif ($currentUserIndex === null) {
            $notice = 'User profile not found.';
            $noticeType = 'error';
            $selectedView = 'profile';
            $showProfileEditModal = true;
        } else {
            foreach ($store['users'] as $index => $user) {
                if (
                    $index !== $currentUserIndex &&
                    strtolower((string) ($user['email'] ?? '')) === strtolower($updatedEmail)
                ) {
                    $notice = 'That email is already registered.';
                    $noticeType = 'error';
                    $selectedView = 'profile';
                    $showProfileEditModal = true;
                    break;
                }
            }

            if ($notice === '') {
                $previousEmail = (string) ($currentUser['email'] ?? '');
                if (strcasecmp($previousEmail, $updatedEmail) !== 0) {
                    foreach ($store['messages'] as $messageIndex => $message) {
                        if (strcasecmp((string) ($message['from_email'] ?? ''), $previousEmail) === 0) {
                            $store['messages'][$messageIndex]['from_email'] = $updatedEmail;
                        }
                        if (strcasecmp((string) ($message['to'] ?? ''), $previousEmail) === 0) {
                            $store['messages'][$messageIndex]['to'] = $updatedEmail;
                        }
                    }
                }
                $store['users'][$currentUserIndex]['name'] = $updatedName;
                $store['users'][$currentUserIndex]['email'] = $updatedEmail;
                $store['users'][$currentUserIndex]['phone'] = $updatedPhone;
                $store['users'][$currentUserIndex]['address'] = $updatedAddress;
                if ($profileImagePath !== null) {
                    $oldProfileImage = trim((string) ($store['users'][$currentUserIndex]['profile_image'] ?? ''));
                    if ($oldProfileImage !== '' && $oldProfileImage !== $profileImagePath) {
                        $oldPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $oldProfileImage);
                        if (is_file($oldPath)) {
                            @unlink($oldPath);
                        }
                    }
                    $store['users'][$currentUserIndex]['profile_image'] = $profileImagePath;
                }

                appSaveStore($store);
                $_SESSION['user'] = [
                    'name' => $updatedName,
                    'email' => $updatedEmail,
                    'role' => $store['users'][$currentUserIndex]['role'] ?? 'user',
                ];
                header('Location: user.php?view=profile');
                exit;
            }
        }
    }

    if ($action === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($currentUserIndex === null) {
            $notice = 'User profile not found.';
            $noticeType = 'error';
            $selectedView = 'profile';
            $showPasswordModal = true;
        } elseif ($newPassword === '' || $confirmPassword === '') {
            $notice = 'Complete the password fields.';
            $noticeType = 'error';
            $selectedView = 'profile';
            $showPasswordModal = true;
        } elseif (strlen($newPassword) < 6) {
            $notice = 'New password must be at least 6 characters.';
            $noticeType = 'error';
            $selectedView = 'profile';
            $showPasswordModal = true;
        } elseif ($newPassword !== $confirmPassword) {
            $notice = 'Passwords do not match.';
            $noticeType = 'error';
            $selectedView = 'profile';
            $showPasswordModal = true;
        } elseif (
            !empty($store['users'][$currentUserIndex]['password']) &&
            !password_verify($currentPassword, (string) ($store['users'][$currentUserIndex]['password'] ?? ''))
        ) {
            $notice = 'Current password is incorrect.';
            $noticeType = 'error';
            $selectedView = 'profile';
            $showPasswordModal = true;
        } else {
            $store['users'][$currentUserIndex]['password'] = password_hash($newPassword, PASSWORD_DEFAULT);
            appSaveStore($store);
            $notice = 'Password updated successfully.';
            $noticeType = 'success';
            $selectedView = 'profile';
        }
    }

    if ($action === 'save_notifications') {
        if ($currentUserIndex === null) {
            $notice = 'User profile not found.';
            $noticeType = 'error';
            $selectedView = 'profile';
            $showNotificationModal = true;
        } else {
            $store['users'][$currentUserIndex]['notifications_enabled'] = isset($_POST['notifications_enabled']) ? 1 : 0;
            appSaveStore($store);
            $notice = 'Notification settings updated.';
            $noticeType = 'success';
            $selectedView = 'profile';
        }
    }
}

$store = appLoadStore();
$sessionUser = is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : [];
$sessionUser += [
    'name' => $currentUser['name'] ?? 'User',
    'email' => $currentUser['email'] ?? '',
    'role' => $currentUser['role'] ?? 'user',
];
[$currentUserIndex, $currentUserRecord] = findUserRecordIndex($store['users'] ?? [], (string) ($sessionUser['email'] ?? ''));
$currentUser = $sessionUser;
if (is_array($currentUserRecord)) {
    $currentUser = [
        'name' => $currentUserRecord['name'] ?? ($currentUser['name'] ?? 'User'),
        'email' => $currentUserRecord['email'] ?? ($currentUser['email'] ?? ''),
        'role' => $currentUserRecord['role'] ?? ($currentUser['role'] ?? 'user'),
    ];
    $_SESSION['user'] = $currentUser;
}
if ($selectedView === 'messages' && markMessagesReadForUser($store, (string) ($currentUser['email'] ?? ''))) {
    appSaveStore($store);
    $store = appLoadStore();
}
if ($selectedView === 'orders' && $selectedOrderType === 'customization') {
    $markedCustomizationRead = false;
    foreach (($store['messages'] ?? []) as $messageIndex => $message) {
        if (appMessageCustomizationId($message) !== '' && strcasecmp((string) ($message['to'] ?? ''), (string) ($currentUser['email'] ?? '')) === 0 && empty($message['read_by_user'])) {
            $store['messages'][$messageIndex]['read_by_user'] = 1;
            $markedCustomizationRead = true;
        }
    }
    if ($markedCustomizationRead) {
        appSaveStore($store);
        $store = appLoadStore();
    }
}
$products = array_values(array_filter($store['products'], 'appCatalogProductIsVisible'));
$storedCategories = $store['settings']['categories'] ?? [];
$cartItems = array_values(array_filter(($store['carts'] ?? []), function ($item) use ($currentUser) {
    return ($item['customer_email'] ?? '') === ($currentUser['email'] ?? '');
}));
$wishlistItems = array_values(array_filter(($store['wishlists'] ?? []), function ($item) use ($currentUser) {
    return ($item['customer_email'] ?? '') === ($currentUser['email'] ?? '');
}));
$orders = array_values(array_filter($store['orders'], function ($order) use ($currentUser) {
    return ($order['customer_email'] ?? '') === ($currentUser['email'] ?? '');
}));
$deletedUserMessages = array_values(array_filter(($store['messages'] ?? []), function ($message) use ($currentUser) {
    return !empty($message['deleted_by_user']) && appMessageCustomizationId($message) === '' && (strcasecmp((string) ($message['from_email'] ?? ''), (string) ($currentUser['email'] ?? '')) === 0
        || strcasecmp((string) ($message['to'] ?? ''), (string) ($currentUser['email'] ?? '')) === 0);
}));
$messageThread = array_values(array_filter(($store['messages'] ?? []), function ($message) use ($currentUser) {
        return empty($message['deleted_by_user']) && (strcasecmp((string) ($message['from_email'] ?? ''), (string) ($currentUser['email'] ?? '')) === 0
            || strcasecmp((string) ($message['to'] ?? ''), (string) ($currentUser['email'] ?? '')) === 0);
    }));
$userUnreadMessageCount = countUnreadMessagesForUser($store['messages'] ?? [], (string) ($currentUser['email'] ?? ''));
$userUnreadAdminMessageCount = countUnreadAdminMessagesForUser($store['messages'] ?? [], (string) ($currentUser['email'] ?? ''));
$userUnreadCustomizationCount = count(array_filter($store['messages'] ?? [], function ($message) use ($currentUser) {
    return appMessageCustomizationId($message) !== '' && strcasecmp((string) ($message['to'] ?? ''), (string) ($currentUser['email'] ?? '')) === 0 && empty($message['read_by_user']);
}));
$userMessageState = buildUserMessageState($store, $currentUser);
$cartTotal = 0;
foreach ($cartItems as $item) {
    $cartTotal += ((float) ($item['price'] ?? 0)) * (int) ($item['quantity'] ?? 1);
}

$categories = [];
$categoryCards = [];
$categoryKeys = [];
foreach ($storedCategories as $category) {
    $categoryName = trim((string) ($category['name'] ?? ''));
    $categoryKey = strtolower($categoryName);
    if ($categoryName !== '' && !isset($categoryKeys[$categoryKey])) {
        $categoryKeys[$categoryKey] = true;
        $categories[] = $categoryName;
        $categoryCards[] = [
            'name' => $categoryName,
            'icon' => trim((string) ($category['icon'] ?? '')),
            'icon_class' => normalizeCategoryIcon($categoryName),
        ];
    }
}

foreach ($products as $product) {
    $categoryName = trim((string) ($product['category'] ?? ''));
    $categoryKey = strtolower($categoryName);
    if ($categoryName !== '' && !isset($categoryKeys[$categoryKey])) {
        $categoryKeys[$categoryKey] = true;
        $categories[] = $categoryName;
        $categoryCards[] = [
            'name' => $categoryName,
            'icon' => categoryIconUrl($categoryName),
            'icon_class' => normalizeCategoryIcon($categoryName),
        ];
    }
}

if ($selectedCategory !== '') {
    $matchingCategory = null;
    foreach ($categories as $categoryName) {
        if (strcasecmp($categoryName, $selectedCategory) === 0) {
            $matchingCategory = $categoryName;
            break;
        }
    }
    $selectedCategory = $matchingCategory ?? '';
}

if ($selectedCategoryPopup !== '' && !in_array($selectedCategoryPopup, $categories, true)) {
    $selectedCategoryPopup = '';
}

$filteredProducts = array_values(array_filter($products, function ($product) use ($search, $selectedCategory) {
    if ($search !== '') {
        $haystack = ($product['name'] ?? '') . ' ' . ($product['category'] ?? '') . ' ' . ($product['material'] ?? '') . ' ' . ($product['description'] ?? '');
        if (stripos($haystack, $search) === false) {
            return false;
        }
    }

    if ($selectedCategory !== '' && strcasecmp((string) ($product['category'] ?? ''), $selectedCategory) !== 0) {
        return false;
    }

    return true;
}));

$showAllProducts = $showAllProducts || $search !== '' || $selectedCategory !== '';
$visibleProducts = $showAllProducts ? $filteredProducts : array_slice($filteredProducts, 0, 6);
$sliderProducts = array_values(array_filter($products, function ($product) {
    return !empty($product['show_in_slider']);
}));
if ($sliderProducts === []) {
    $sliderProducts = array_slice($products, 0, 4);
}
$sliderProducts = $sliderProducts === [] ? [[
    'id' => '',
    'name' => 'RN Furniture',
    'description' => 'Quality furniture for every room.',
    'image' => '',
]] : $sliderProducts;
$sliderProducts = array_slice($sliderProducts, 0, 4);
$heroSlides = array_map(static function ($image) {
    return ['image' => $image, 'alt' => 'RN Furniture'];
}, array_values(array_filter((array) ($store['settings']['slider']['images'] ?? []), static function ($image) {
    return is_string($image) && str_starts_with($image, 'uploads/') && is_file(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $image));
})));
if ($heroSlides === []) {
    $heroSlides = array_values(array_filter(array_map(function ($product) {
        $image = trim((string) ($product['image'] ?? ''));
        if ($image === '') {
            return null;
        }

        return [
            'image' => $image,
            'alt' => 'RN Furniture',
        ];
    }, $sliderProducts)));
}
$sliderSettings = normalizeSliderSettings($store['settings']['slider'] ?? []);
$visibleCategoryCards = array_slice($categoryCards, 0, 3);
$selectedCategoryCard = null;
foreach ($categoryCards as $categoryCard) {
    if (($categoryCard['name'] ?? '') === $selectedCategoryPopup) {
        $selectedCategoryCard = $categoryCard;
        break;
    }
}
$selectedProduct = null;
foreach ($products as $product) {
    if (($product['id'] ?? '') === $selectedProductId) {
        $selectedProduct = $product;
        break;
    }
}

if ($selectedView === 'messages' && $selectedProduct === null) {
    foreach ($messageThread as $messageItem) {
        $messageProductId = trim((string) ($messageItem['product_id'] ?? ''));
        if ($messageProductId === '') {
            continue;
        }

        [$messageProduct] = findProductById($products, $messageProductId);
        if ($messageProduct !== null) {
            $selectedProduct = $messageProduct;
            $selectedProductId = $messageProductId;
            break;
        }
    }
}

$activeMessageProduct = $selectedView === 'messages' ? $selectedProduct : null;

$allowedViews = ['home', 'cart', 'orders', 'messages', 'profile', 'wishlist', 'mix', 'designs'];
if (!in_array($selectedView, $allowedViews, true)) {
    $selectedView = 'home';
}

$mixRooms = mixRoomDefinitions();
 $selectedMixRoom = trim((string) ($_GET['room'] ?? ''));
 if (!isset($mixRooms[$selectedMixRoom])) {
     $selectedMixRoom = '';
 }
$mixProducts = array_values(array_filter($products, function ($product) use ($selectedMixRoom) {
    return mixRoomAllowsProduct($selectedMixRoom, $product);
}));
$savedMixDesigns = mixLoadDesigns((string) ($currentUser['email'] ?? ''));
$selectedMixDesignId = trim((string) ($_GET['design'] ?? ''));
$selectedMixDesign = $selectedMixDesignId !== '' ? mixLoadDesign($selectedMixDesignId, (string) ($currentUser['email'] ?? '')) : null;
if ($selectedMixDesign !== null) {
    $selectedMixRoom = (string) ($selectedMixDesign['room_type'] ?? $selectedMixRoom);
    if (!isset($mixRooms[$selectedMixRoom])) {
         $selectedMixRoom = '';
    }
    $mixProducts = array_values(array_filter($products, function ($product) use ($selectedMixRoom) {
        return mixRoomAllowsProduct($selectedMixRoom, $product);
    }));
}
$mixProductsPayload = array_map(function ($product) {
    return [
        'id' => $product['id'] ?? '',
        'name' => $product['name'] ?? '',
        'category' => $product['category'] ?? '',
        'material' => $product['material'] ?? '',
        'price' => number_format((float) ($product['price'] ?? 0), 2, '.', ''),
        'stock' => (int) ($product['stock'] ?? 0),
        'image' => $product['image'] ?? '',
        'color' => mixProductColor($product),
        'size' => mixProductSize($product),
        'mix_category' => mixProductVisualCategory($product),
        'board_width' => mixProductBoardWidth($product),
    ];
}, $mixProducts);
$selectedMixPayload = $selectedMixDesign !== null ? [
    'id' => $selectedMixDesign['id'] ?? '',
    'design_name' => $selectedMixDesign['design_name'] ?? '',
    'room_type' => $selectedMixDesign['room_type'] ?? $selectedMixRoom,
    'items' => $selectedMixDesign['items'] ?? [],
] : null;
$customerCustomizationRequests = array_values(array_filter($store['customization_requests'] ?? [], function ($request) use ($currentUser) {
    return strtolower((string) ($request['customer_email'] ?? '')) === strtolower((string) ($currentUser['email'] ?? ''));
}));
$displayOrders = $orders;
$displayCustomizationRequests = $customerCustomizationRequests;
if ($selectedOrderId !== '') {
    if ($selectedOrderType === 'customization') {
        $displayCustomizationRequests = array_values(array_filter($customerCustomizationRequests, static function ($request) use ($selectedOrderId) {
            return (string) ($request['id'] ?? '') === $selectedOrderId;
        }));
        if ($displayCustomizationRequests === []) {
            $selectedOrderId = '';
        }
    } else {
        $displayOrders = array_values(array_filter($orders, static function ($order) use ($selectedOrderId) {
            return (string) ($order['id'] ?? '') === $selectedOrderId;
        }));
        if ($displayOrders === []) {
            $selectedOrderId = '';
        }
    }
}
$customizationCategoryOptions = array_values(array_unique(array_filter(array_merge(
    array_map(static function ($category) { return (string) ($category['name'] ?? ''); }, $storedCategories),
    array_map(static function ($product) { return (string) ($product['category'] ?? ''); }, $products)
))));
$customizationFormProduct = null;
if ($customizationFormProductId !== '') {
    [$customizationFormProduct] = findProductById($products, $customizationFormProductId);
}
if (!in_array($requestedCustomizeCategory, $customizationCategoryOptions, true)) {
    $requestedCustomizeCategory = (string) ($customizationFormProduct['category'] ?? $customizationCategoryOptions[0] ?? '');
}

$profileName = (string) (($currentUserRecord['name'] ?? '') !== '' ? $currentUserRecord['name'] : ($currentUser['name'] ?? 'User'));
$profileEmail = (string) (($currentUserRecord['email'] ?? '') !== '' ? $currentUserRecord['email'] : ($currentUser['email'] ?? ''));
$profileRole = 'Member';
$profileImage = trim((string) ($currentUserRecord['profile_image'] ?? ''));
$profilePhone = trim((string) ($currentUserRecord['phone'] ?? '')) !== '' ? (string) $currentUserRecord['phone'] : 'Not provided';
$profileAddress = trim((string) ($currentUserRecord['address'] ?? '')) !== '' ? (string) $currentUserRecord['address'] : 'Not provided';
$profileNotificationsEnabled = !array_key_exists('notifications_enabled', (array) $currentUserRecord) || !empty($currentUserRecord['notifications_enabled']);
$profileInitial = strtoupper(substr($profileName, 0, 1)) ?: 'U';
$adminRecord = findAdminRecord($store['users'] ?? []);
$chatAdminName = trim((string) ($adminRecord['name'] ?? 'Admin Support')) !== '' ? trim((string) ($adminRecord['name'] ?? 'Admin Support')) : 'Admin Support';
$chatAdminImage = trim((string) ($adminRecord['profile_image'] ?? $adminRecord['image'] ?? ''));
$chatAdminSubtitle = 'RN Furniture chat';
$chatAdminInitial = strtoupper(substr($chatAdminName, 0, 1)) ?: 'A';
$adminContactPhone = findAdminPhoneNumber($store['users'] ?? []);
$adminCallHref = appBuildPhoneHref($adminContactPhone);
$canPlaceOrder = userHasCompleteOrderProfile($currentUserRecord);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
    <title>RN Furniture</title>
    <style>
        :root {
            --brand: #12357f;
            --brand-deep: #0e2c70;
            --text: #1f2940;
            --muted: #76829a;
            --line: #e5ebf7;
            --surface: #ffffff;
        }
        * { box-sizing: border-box; }
        html {
            width: 100%;
            overflow-x: hidden;
        }
        img,
        svg,
        video,
        canvas {
            max-width: 100%;
        }
        body {
            margin: 0;
            overflow-x: hidden;
            overflow-y: auto;
            min-height: 100vh;
            font-family: "Poppins", "Segoe UI", sans-serif;
            color: var(--text);
            background: linear-gradient(180deg, #f6f8fe 0%, #eef3fb 100%);
        }
        a { color: inherit; text-decoration: none; }
        button,
        input,
        select,
        textarea {
            max-width: 100%;
        }
        .app {
            width: 100%;
            max-width: 430px;
            min-height: 100vh;
            height: 100dvh;
            margin: 0 auto;
            background: var(--surface);
            box-shadow: 0 0 0 1px rgba(18, 53, 127, 0.06);
            position: relative;
            display: flex;
            flex-direction: column;
            overflow: clip;
        }
        .app.mix-app {
            max-width: 1360px;
        }
        .header {
            flex: 0 0 auto;
            z-index: 120;
            background: linear-gradient(180deg, var(--brand) 0%, var(--brand-deep) 100%);
            color: #fff;
            padding: 14px 18px 16px;
        }
        .header.profile-header {
            padding: 22px 18px 82px;
            border-bottom-left-radius: 28px;
            border-bottom-right-radius: 28px;
            background:
                radial-gradient(circle at top left, rgba(255, 255, 255, 0.12), transparent 34%),
                linear-gradient(135deg, #0d2563 0%, #12357f 58%, #0e2c70 100%);
        }
        .brand-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
        }
        .brand-title {
            margin: 0;
            font-size: 1.18rem;
            line-height: 1.05;
            letter-spacing: -0.02em;
        }
        .brand-block {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .brand-title-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .brand-mark {
            width: 30px;
            height: 30px;
            color: #ffbe1b;
            stroke: currentColor;
            fill: none;
            stroke-width: 1.8;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .brand-subtitle {
            margin: 0;
            color: rgba(255, 255, 255, 0.88);
            font-size: 0.95rem;
            font-weight: 500;
        }
        .logout-link {
            padding: 7px 10px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.12);
            font-size: 0.68rem;
            font-weight: 600;
        }
        .top-profile-link {
            flex: 0 0 auto;
            position: relative;
            display: inline-grid;
            place-items: center;
            width: 42px;
            height: 42px;
            border: 1px solid rgba(255, 255, 255, 0.28);
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.14);
            color: #fff;
            font-size: 0.82rem;
            font-weight: 800;
            box-shadow: 0 10px 24px rgba(7, 24, 72, 0.18);
            overflow: hidden;
            transition: transform 0.16s ease, background 0.16s ease, border-color 0.16s ease;
        }
        .top-profile-link:hover,
        .top-profile-link.active {
            transform: translateY(-1px);
            background: rgba(255, 255, 255, 0.22);
            border-color: rgba(255, 255, 255, 0.46);
        }
        .top-profile-link img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .top-profile-link svg {
            width: 22px;
            height: 22px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2.2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .top-profile-link.has-image span,
        .top-profile-link.has-image svg {
            display: none;
        }
        .content {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            padding: 20px clamp(12px, 4vw, 20px) 104px;
            overflow-x: hidden;
        }
        .mix-app .content {
            padding-bottom: 96px;
        }
        .content.home-content {
            display: flex;
            flex-direction: column;
            overflow: hidden;
            padding-bottom: 0;
        }
        .content.profile-content {
            margin-top: -52px;
            position: relative;
            z-index: 2;
        }
        .notice {
            position: fixed;
            left: 50%;
            bottom: 94px;
            transform: translateX(-50%);
            width: min(calc(100% - 28px), 320px);
            padding: 11px 14px;
            border-radius: 14px;
            border: 1px solid transparent;
            box-shadow: 0 16px 34px rgba(17, 40, 92, 0.18);
            font-size: 0.8rem;
            font-weight: 700;
            line-height: 1.4;
            z-index: 140;
            opacity: 1;
            transition: opacity 0.22s ease, transform 0.22s ease;
        }
        .notice.success {
            background: rgba(237, 249, 239, 0.98);
            border-color: #b6e3c1;
            color: #246c43;
        }
        .notice.error {
            background: rgba(255, 241, 241, 0.98);
            border-color: #f1b4b4;
            color: #972d2d;
        }
        .notice.is-hiding {
            opacity: 0;
            transform: translateX(-50%) translateY(8px);
        }
        .search-form { margin-bottom: 14px; }
        .dashboard-message-alert {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex: 0 0 auto;
            margin-bottom: 12px;
            padding: 10px 12px;
            border-left: 3px solid #21865a;
            border-radius: 4px;
            background: #eaf7f0;
            color: #163d2a;
            text-decoration: none;
            font-size: 12px;
        }
        .dashboard-message-alert[hidden] { display: none; }
        .dashboard-message-alert strong { display: block; font-size: 13px; }
        .dashboard-message-alert span { display: block; }
        .dashboard-message-alert .alert-action { flex: 0 0 auto; font-weight: 700; }
        .home-content .search-form,
        .home-content .hero-card,
        .home-content #categories {
            flex: 0 0 auto;
        }
        .search-shell {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 9px 12px;
            border: 1px solid var(--line);
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 10px 22px rgba(17, 40, 92, 0.05);
        }
        .search-shell input { border: 0; outline: 0; flex: 1; min-width: 0; font: inherit; font-size: 0.82rem; }
        .search-icon { width: 19px; height: 19px; color: var(--brand); }
        .search-shell button { display: none; }
        .hero-card {
            position: relative;
            z-index: 110;
            overflow: hidden;
            border-radius: 18px;
            background: var(--hero-card-color, #ffffff);
            box-shadow: 0 12px 28px rgba(17, 40, 92, 0.08);
            margin-bottom: 14px;
        }
        .hero-slider {
            position: relative;
            min-height: min(var(--hero-height, 145px), 145px);
        }
        .hero-slide {
            position: absolute;
            inset: 0;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.35s ease;
        }
        .hero-slide.is-active {
            opacity: 1;
            pointer-events: auto;
        }
        .hero-grid { display: grid; grid-template-columns: 1fr; min-height: min(var(--hero-height, 145px), 145px); }
        .hero-visual {
            position: relative;
            min-height: min(var(--hero-height, 145px), 145px);
            max-height: min(var(--hero-height, 145px), 145px);
            overflow: hidden;
            background: var(--hero-bg, #f4f6fb);
            border-radius: 14px;
        }
        .hero-visual img {
            width: 100%;
            height: 100%;
            object-fit: var(--hero-fit, contain);
            display: block;
            background: var(--hero-bg, #f4f6fb);
        }
        .hero-brand {
            position: absolute;
            left: 12px;
            bottom: 12px;
            z-index: 2;
            display: inline-flex;
            align-items: center;
            min-height: 34px;
            padding: 7px 11px;
            border-radius: 6px;
            background: rgba(14, 44, 112, 0.9);
            color: #fff;
            font-size: 0.78rem;
            font-weight: 800;
            line-height: 1;
            box-shadow: 0 6px 18px rgba(14, 44, 112, 0.22);
        }
        .hero-dots {
            display: flex;
            justify-content: center;
            gap: 5px;
            padding: 7px 0 9px;
        }
        .hero-dots span {
            width: 6px;
            height: 6px;
            border-radius: 999px;
            background: var(--hero-dot-color, #d7dce8);
        }
        .hero-dots span.active {
            background: var(--hero-dot-active-color, var(--brand));
        }
        .section-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin: 0 0 14px; }
        .section-head h3 { margin: 0; color: var(--brand); font-size: 0.92rem; }
        .section-head span { color: var(--brand); font-size: 0.76rem; font-weight: 600; }
        .section-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--brand);
            font-size: 0.76rem;
            font-weight: 700;
        }
        .mix-designs-button {
            gap: 6px;
            min-height: 34px;
            padding: 7px 10px;
            border: 1px solid var(--brand);
            border-radius: 8px;
            background: var(--brand);
            color: #fff;
            white-space: nowrap;
        }
        .mix-designs-button svg { width: 14px; height: 14px; stroke: currentColor; fill: none; stroke-width: 2; }
        .categories-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 6px; margin-bottom: 14px; }
        .category-card {
            border-radius: 12px;
            border: 1px solid var(--line);
            background: #fff;
            box-shadow: 0 8px 16px rgba(17, 40, 92, 0.04);
            padding: 6px 3px;
            text-align: center;
        }
        .category-icon { width: 24px; height: 24px; margin: 0 auto 5px; color: var(--brand); position: relative; }
        .category-icon::before, .category-icon::after { content: ""; position: absolute; box-sizing: border-box; }
        .category-icon.sofa::before { left: 3px; top: 9px; width: 18px; height: 8px; border: 2px solid currentColor; border-radius: 5px 5px 3px 3px; }
        .category-icon.sofa::after { left: 6px; top: 3px; width: 12px; height: 6px; border: 2px solid currentColor; border-bottom: 0; border-radius: 5px 5px 0 0; }
        .category-icon.chair::before { left: 7px; top: 4px; width: 10px; height: 8px; border: 2px solid currentColor; border-radius: 4px 4px 3px 3px; }
        .category-icon.chair::after { left: 9px; top: 12px; width: 6px; height: 8px; border-left: 2px solid currentColor; border-right: 2px solid currentColor; border-bottom: 2px solid currentColor; }
        .category-icon.bed::before { left: 3px; top: 10px; width: 18px; height: 7px; border: 2px solid currentColor; border-radius: 5px; }
        .category-icon.bed::after { left: 3px; top: 5px; width: 9px; height: 5px; border: 2px solid currentColor; border-bottom: 0; border-radius: 4px 4px 0 0; }
        .category-icon.cabinet::before { left: 6px; top: 4px; width: 12px; height: 16px; border: 2px solid currentColor; border-radius: 3px; }
        .category-icon.cabinet::after { left: 11px; top: 4px; width: 2px; height: 16px; background: currentColor; }
        .category-icon.table::before { left: 4px; top: 7px; width: 16px; height: 6px; border: 2px solid currentColor; border-radius: 5px; }
        .category-icon.table::after { left: 9px; top: 13px; width: 6px; height: 8px; border-left: 2px solid currentColor; border-right: 2px solid currentColor; }
        .category-icon.furniture::before { left: 5px; top: 5px; width: 14px; height: 10px; border: 2px solid currentColor; border-radius: 5px; }
        .category-icon.furniture::after { left: 9px; top: 15px; width: 6px; height: 5px; border-left: 2px solid currentColor; border-right: 2px solid currentColor; }
        .category-svg {
            width: 20px;
            height: 20px;
            margin: 0 auto 4px;
            color: var(--brand);
            display: block;
            stroke: currentColor;
            fill: none;
            stroke-width: 1.9;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .category-card span { display: block; font-size: 0.52rem; font-weight: 600; }
        .shop-scroll {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            padding: 2px 0 104px;
            overscroll-behavior: contain;
            -webkit-overflow-scrolling: touch;
        }
        .products-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
        .product-card {
            overflow: hidden;
            border-radius: 10px;
            border: 1px solid var(--line);
            background: #fff;
            box-shadow: 0 8px 18px rgba(17, 40, 92, 0.05);
        }
        .product-link {
            display: flex;
            flex-direction: column;
            height: 100%;
            color: inherit;
        }
        .product-media {
            position: relative;
            aspect-ratio: 4 / 3;
            min-height: 118px;
            overflow: hidden;
            background: linear-gradient(180deg, #eef2f9 0%, #dfe7f5 100%);
        }
        .product-image-zoom-btn {
            width: 100%;
            height: 100%;
            padding: 0;
            border: 0;
            background: transparent;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: zoom-in;
        }
        .product-media img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            object-position: center;
            display: block;
            background: #fff;
        }
        .product-media.placeholder { display: grid; place-items: center; color: var(--brand); font-size: 0.66rem; font-weight: 700; }
        .product-body {
            display: flex;
            flex: 1;
            flex-direction: column;
            padding: 8px 8px 9px;
        }
        .product-body h4 { margin: 0 0 3px; font-size: 0.6rem; line-height: 1.2; }
        .product-price { margin: 0 0 5px; color: var(--brand); font-size: 0.62rem; font-weight: 700; }
        .product-card-actions {
            display: flex;
            margin-top: auto;
            justify-content: flex-end;
            align-items: center;
            gap: 8px;
        }
        .product-view-btn {
            padding: 5px 7px;
            border-radius: 7px;
            background: #2458e6;
            color: #fff;
            font-size: 0.54rem;
            font-weight: 700;
            box-shadow: 0 8px 18px rgba(36, 88, 230, 0.18);
        }
        .product-link:hover .product-body h4,
        .product-link:hover .product-price {
            color: #0f3ca8;
        }
        .mix-shell {
            display: grid;
            gap: 16px;
        }
        .mix-page-tabs {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
        }
        .mix-page-tab {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 0;
            min-height: 42px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: #fff;
            color: var(--brand);
            font: inherit;
            font-size: 0.74rem;
            font-weight: 800;
            cursor: pointer;
            line-height: 1.2;
            overflow-wrap: anywhere;
            text-align: center;
        }
        .mix-page-tab.active {
            background: var(--brand);
            border-color: var(--brand);
            color: #fff;
        }
        .mix-tab-panel[hidden] {
            display: none;
        }
        .mix-tab-panel {
            display: grid;
            gap: 16px;
        }
        .room-tabs {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
        }
        .mix-category-tabs {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding-bottom: 2px;
            margin-bottom: 10px;
        }
        .mix-category-tab {
            flex: 0 0 auto;
            border: 1px solid var(--line);
            border-radius: 999px;
            background: #fff;
            color: var(--brand);
            padding: 8px 11px;
            font: inherit;
            font-size: 0.66rem;
            font-weight: 800;
            cursor: pointer;
        }
        .mix-category-tab.active {
            background: var(--brand);
            border-color: var(--brand);
            color: #fff;
        }
        .room-tab {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: #fff;
            color: var(--brand);
            font-size: 0.72rem;
            font-weight: 700;
            text-align: center;
        }
        .room-tab.active {
            background: var(--brand);
            color: #fff;
            border-color: var(--brand);
        }
        .mix-panel {
            border: 1px solid var(--line);
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 12px 26px rgba(17, 40, 92, 0.06);
            padding: 12px;
        }
        .mix-panel-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 10px;
        }
        .mix-panel-title h3 {
            margin: 0;
            color: var(--brand);
            font-size: 0.88rem;
        }
        .mix-panel-title span {
            color: var(--muted);
            font-size: 0.68rem;
            font-weight: 700;
        }
        .mix-products {
            display: grid;
            gap: 10px;
            padding-right: 0;
        }
        .mix-product {
            display: grid;
            grid-template-columns: 72px minmax(0, 1fr) auto;
            align-items: center;
            gap: 10px;
            border: 1px solid #edf1f8;
            border-radius: 12px;
            background: #fbfdff;
            padding: 8px;
            transition: transform 0.16s ease, border-color 0.16s ease, box-shadow 0.16s ease;
        }
        .mix-product[hidden],
        .mix-category-empty[hidden] { display: none; }
        .mix-product:hover,
        .mix-product:focus-within {
            transform: translateY(-1px);
            border-color: rgba(18, 53, 127, 0.24);
            box-shadow: 0 10px 20px rgba(17, 40, 92, 0.08);
        }
        .mix-product img,
        .mix-item-thumb {
            width: 72px;
            height: 60px;
            object-fit: cover;
            border-radius: 9px;
            background: #eef3ff;
            pointer-events: none;
        }
        .mix-product-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 4px 8px;
            margin-top: 5px;
        }
        .mix-product-meta span {
            color: var(--muted);
            font-size: 0.6rem;
            font-weight: 700;
        }
        .mix-product strong,
        .mix-selected-copy strong,
        .design-copy strong {
            display: block;
            color: #1f2940;
            font-size: 0.72rem;
            line-height: 1.25;
        }
        .mix-product p,
        .mix-selected-copy p,
        .design-copy p {
            margin: 3px 0 0;
            color: var(--muted);
            font-size: 0.62rem;
            line-height: 1.35;
        }
        .mix-add-btn,
        .mix-mini-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            border: 0;
            border-radius: 9px;
            background: var(--brand);
            color: #fff;
            font: inherit;
            font-size: 0.64rem;
            font-weight: 800;
            cursor: pointer;
        }
        .mix-add-btn {
            min-width: 64px;
            height: 38px;
            gap: 4px;
            padding: 0 10px;
            justify-self: center;
        }
        .mix-add-btn svg,
        .mix-mini-btn svg {
            width: 16px;
            height: 16px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2.3;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .mix-add-btn:disabled {
            opacity: 0.48;
            cursor: not-allowed;
        }
        .mix-mini-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .mix-board {
            position: relative;
            height: clamp(440px, 68vh, 760px);
            min-height: 440px;
            overflow: hidden;
            border: 1px solid #cfd8e8;
            border-radius: 18px;
            background:
                linear-gradient(118deg, rgba(18, 53, 127, 0.13) 0 17%, transparent 17.2%),
                linear-gradient(242deg, rgba(18, 53, 127, 0.12) 0 16%, transparent 16.2%),
                linear-gradient(90deg, rgba(255,255,255,0.68) 0 1px, transparent 1px 100%) 0 54% / 100% 12px,
                linear-gradient(180deg, #f6faff 0 55%, transparent 55%),
                linear-gradient(180deg, transparent 0 54%, rgba(102, 67, 35, 0.22) 54.3%, transparent 54.8%),
                linear-gradient(180deg, #e8ba7d 55%, #b46e34 100%);
            box-shadow: inset 0 0 0 1px rgba(255,255,255,0.75), inset 0 -80px 120px rgba(68, 37, 13, 0.18), 0 16px 34px rgba(17, 40, 92, 0.1);
            isolation: isolate;
            perspective: 900px;
            transform-style: preserve-3d;
        }
        .mix-board::before {
            content: "";
            position: absolute;
            right: clamp(20px, 5vw, 58px);
            top: clamp(22px, 5vw, 44px);
            width: min(26%, 170px);
            height: clamp(82px, 16vw, 126px);
            border: 8px solid #ffffff;
            border-radius: 4px;
            background:
                linear-gradient(90deg, transparent 47%, #ffffff 47% 53%, transparent 53%),
                linear-gradient(180deg, transparent 47%, #ffffff 47% 53%, transparent 53%),
                linear-gradient(135deg, #9fc5e8 0%, #d9ecff 100%);
            box-shadow: 0 12px 28px rgba(17, 40, 92, 0.14);
            z-index: 0;
        }
        .mix-board::after {
            content: "";
            position: absolute;
            left: -18%;
            right: -18%;
            bottom: -24%;
            height: 72%;
            background:
                repeating-linear-gradient(96deg, rgba(82, 48, 22, 0.32) 0 2px, transparent 2px 50px),
                linear-gradient(90deg, rgba(255,255,255,0.22), transparent 24%, transparent 76%, rgba(77, 40, 16, 0.2)),
                linear-gradient(180deg, rgba(255,255,255,0.2), rgba(109,60,24,0.2));
            transform: rotateX(58deg) translateZ(-18px);
            transform-origin: bottom;
            pointer-events: none;
            z-index: 0;
        }
        .mix-board-empty {
            position: absolute;
            inset: 0;
            display: grid;
            place-items: center;
            padding: 20px;
            color: #71809d;
            font-size: 0.76rem;
            text-align: center;
        }
        .mix-board-item {
            position: absolute;
            left: 50%;
            top: 55%;
            width: 138px;
            height: auto;
            border: 0;
            border-radius: 0;
            background: transparent;
            box-shadow: none;
            padding: 0;
            cursor: grab;
            touch-action: none;
            z-index: 3;
            transform-origin: center bottom;
            transition: filter 0.16s ease;
            will-change: left, top, transform;
        }
        .mix-board-item:active {
            cursor: grabbing;
        }
        .mix-board-item img {
            width: 100%;
            height: auto;
            object-fit: contain;
            border-radius: 0;
            display: block;
            user-select: none;
            pointer-events: none;
            filter: drop-shadow(0 20px 14px rgba(28, 18, 8, 0.26));
        }
        .mix-board-item.is-selected img {
            filter: drop-shadow(0 20px 14px rgba(28, 18, 8, 0.26)) drop-shadow(0 0 0 rgba(18, 53, 127, 0));
        }
        .mix-board-item.is-selected::before {
            content: "";
            position: absolute;
            inset: -7px;
            border: 2px dashed rgba(18, 53, 127, 0.72);
            border-radius: 10px;
            pointer-events: none;
        }
        .mix-board-item span {
            position: absolute;
            width: 1px;
            height: 1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
        }
        .mix-board-tools {
            display: none;
            grid-template-columns: repeat(4, minmax(96px, 1fr));
            gap: 7px;
            margin-bottom: 10px;
            justify-content: center;
        }
        .mix-board-tools.is-visible {
            display: grid;
        }
        .mix-room-actions {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 8px;
            margin-top: 10px;
            justify-content: center;
        }
        .mix-selected-list {
            display: grid;
            gap: 8px;
        }
        .mix-selected-item {
            display: grid;
            grid-template-columns: 64px minmax(0, 1fr);
            gap: 10px;
            padding: 8px;
            border: 1px solid #edf1f8;
            border-radius: 12px;
            background: #fbfdff;
            cursor: pointer;
            transition: border-color 0.16s ease, box-shadow 0.16s ease;
        }
        .mix-selected-item.is-selected {
            border-color: rgba(18, 53, 127, 0.42);
            box-shadow: 0 0 0 2px rgba(18, 53, 127, 0.08);
        }
        .mix-unit-price {
            color: var(--brand) !important;
            font-size: 0.72rem !important;
            font-weight: 800;
        }
        .mix-item-controls {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
            margin-top: 8px;
        }
        .mix-item-controls label {
            display: grid;
            gap: 3px;
            color: var(--muted);
            font-size: 0.58rem;
            font-weight: 700;
        }
        .mix-item-controls input,
        .mix-item-controls select,
        .mix-form input {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 7px 8px;
            background: #fff;
            font: inherit;
            font-size: 0.66rem;
        }
        .mix-line-actions {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 6px;
            margin-top: 8px;
        }
        .mix-mini-btn {
            min-height: 32px;
            padding: 0 10px;
            min-width: 0;
            line-height: 1.15;
            white-space: normal;
            text-align: center;
            overflow-wrap: anywhere;
        }
        .mix-mini-btn.soft {
            background: #eef3ff;
            color: var(--brand);
        }
        .mix-warning {
            margin: 8px 0 0;
            color: #9b3b16;
            background: #fff3ec;
            border: 1px solid #ffd6c0;
            border-radius: 10px;
            padding: 8px 10px;
            font-size: 0.66rem;
            font-weight: 700;
            display: none;
        }
        .mix-warning.is-visible {
            display: block;
        }
        .mix-total {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 8px;
            margin: 12px 0;
            padding-top: 10px;
            border-top: 1px solid #edf1f8;
            color: var(--brand);
            font-size: 0.82rem;
            font-weight: 800;
        }
        .mix-total span {
            display: grid;
            gap: 3px;
            padding: 9px;
            border: 1px solid #edf1f8;
            border-radius: 10px;
            background: #f8fbff;
        }
        .mix-total small {
            color: var(--muted);
            font-size: 0.58rem;
            text-transform: uppercase;
        }
        .mix-form {
            display: grid;
            gap: 8px;
        }
        .customization-product-card {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 12px;
            align-items: center;
            padding: 10px;
            border: 1px solid #edf1f8;
            border-radius: 12px;
            background: #fbfdff;
        }
        .customization-proof {
            width: 88px;
            height: 72px;
            object-fit: cover;
            border-radius: 10px;
            background: #eef3ff;
            border: 1px solid #edf1f8;
        }
        .customization-form {
            display: grid;
            gap: 10px;
        }
        .customization-form label {
            display: grid;
            gap: 5px;
            color: var(--muted);
            font-size: 0.68rem;
            font-weight: 700;
        }
        .customization-form input,
        .customization-form select,
        .customization-form textarea {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 9px 10px;
            background: #fff;
            font: inherit;
            font-size: 0.76rem;
        }
        .customization-form textarea {
            min-height: 96px;
            resize: vertical;
        }
        .customization-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
        }
        .customization-colors {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
            border: 0;
            margin: 0;
            padding: 0;
            min-width: 0;
        }
        .customization-colors legend {
            margin-bottom: 7px;
            color: var(--muted);
            font-size: 0.68rem;
            font-weight: 700;
        }
        .customization-form .color-choice {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 7px;
            border: 1px solid var(--line);
            border-radius: 6px;
            color: #27364c;
            background: #fff;
            font-size: 0.7rem;
            cursor: pointer;
        }
        .customization-form .color-choice:has(input:checked) {
            border-color: var(--brand);
            background: #edf3ff;
        }
        .customization-form .color-choice input {
            width: 14px;
            height: 14px;
            margin: 0;
            padding: 0;
            accent-color: var(--brand);
        }
        .color-choice-swatch {
            width: 18px;
            height: 18px;
            flex: 0 0 18px;
            border: 1px solid #aeb8c5;
            border-radius: 50%;
            background: var(--swatch-color);
        }
        .customization-request-card {
            display: grid;
            gap: 10px;
            padding: 12px 0;
            border-bottom: 1px solid #eef2fa;
        }
        .customization-request-card:last-child {
            border-bottom: 0;
        }
        .order-disclosure.customization-request-card { display: block; }
        .order-disclosure-summary { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; cursor: pointer; list-style: none; min-height: 64px; }
        .order-disclosure-summary::-webkit-details-marker { display: none; }
        .order-disclosure-summary::after { content: '⌄'; color: var(--brand); font-size: 1.2rem; margin-left: auto; }
        .order-disclosure[open] > .order-disclosure-summary::after { content: '⌃'; }
        .message-icon-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border: 0;
            border-radius: 10px;
            background: #edf3ff;
            color: var(--brand);
            cursor: pointer;
            position: relative;
            flex-shrink: 0;
        }
        .message-icon-btn:hover {
            background: #dce8ff;
        }
        .message-icon-btn svg {
            width: 20px;
            height: 20px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
        }
        .message-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            min-width: 16px;
            height: 16px;
            padding: 0 4px;
            border-radius: 999px;
            background: #ff8b1f;
            color: #fff;
            font-size: 0.56rem;
            font-weight: 700;
            display: inline-grid;
            place-items: center;
            line-height: 1;
        }
        .customization-chat {
            border: 1px solid var(--line);
            border-radius: 10px;
            margin: 10px 0;
            padding: 10px;
            background: #f8fbff;
        }
        .customization-chat summary {
            cursor: pointer;
            font-weight: 700;
            color: var(--brand);
            padding: 5px 0;
        }
        .order-summary-image { width: 56px; height: 56px; flex: 0 0 56px; border-radius: 8px; object-fit: cover; background: #f3f6fb; }
        .order-summary-main { display: grid; gap: 3px; min-width: 130px; flex: 1; }
        .order-summary-main strong { font-size: .9rem; color: var(--text); }
        .order-summary-main small { font-size: .73rem; color: var(--muted); }
        .order-disclosure-body { display: grid; gap: 8px; padding: 10px 0 2px; }
        .order-kind-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin: 12px 0 16px; }
        .order-kind-tabs a { padding: 9px 13px; border-radius: 999px; background: #edf3ff; color: #244aa4; font-size: .78rem; font-weight: 700; }
        .order-kind-tabs a.active { background: #254fba; color: #fff; }
        .normal-order-steps { display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0; }
        .normal-order-step { display: inline-flex; align-items: center; min-height: 32px; padding: 7px 10px; border: 1px solid #d7dfed; border-radius: 999px; background: #f5f7fb; color: #64748b; font-size: .72rem; font-weight: 700; }
        .normal-order-step.done { border-color: #98d8a4; background: #e3f7e6; color: #24733a; }
        .customization-progress {
            display: grid;
            gap: 5px;
            margin: 2px 0;
            padding: 0;
            list-style: none;
        }
        .customization-progress li {
            display: flex;
            align-items: center;
            gap: 9px;
            color: var(--muted);
            font-size: 0.75rem;
        }
        .customization-progress li::before {
            content: '';
            width: 9px;
            height: 9px;
            flex: 0 0 9px;
            border: 1px solid #aeb9cf;
            border-radius: 50%;
        }
        .customization-progress li.is-done { color: var(--text); }
        .customization-progress li.is-done::before { background: #29a853; border-color: #29a853; }
        .customization-progress li.is-current { font-weight: 700; }
        .customization-chat { margin: 14px 0; padding: 12px; border: 1px solid var(--line); border-radius: 10px; }
        .customization-chat summary { cursor: pointer; font-weight: 700; color: var(--brand); }
        .customization-chat .chat-bubble { margin: 10px 0; }
        .customization-chat .customization-form { margin-top: 12px; }
        .customization-request-head {
            display: grid;
            grid-template-columns: 72px minmax(0, 1fr);
            gap: 10px;
            align-items: center;
        }
        .customization-request-head.no-image { grid-template-columns: minmax(0, 1fr); }
        .customization-request-head img {
            width: 72px;
            height: 62px;
            object-fit: cover;
            border-radius: 10px;
            background: #eef3ff;
        }
        .mix-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }
        .design-list {
            display: grid;
            gap: 10px;
        }
        .design-card {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 10px;
            padding: 12px;
            border: 1px solid var(--line);
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 12px 24px rgba(17, 40, 92, 0.05);
        }
        .design-actions {
            display: grid;
            gap: 7px;
        }
        .stack-panel, .profile-panel {
            border: 1px solid var(--line);
            border-radius: 22px;
            background: #fff;
            box-shadow: 0 16px 32px rgba(17, 40, 92, 0.05);
            padding: 16px;
        }
        .chat-shell {
            display: grid;
            gap: 14px;
            border: 1px solid #e6edf9;
            border-radius: 24px;
            background: linear-gradient(180deg, #f9fbff 0%, #ffffff 100%);
            box-shadow: 0 18px 36px rgba(17, 40, 92, 0.08);
            padding: 14px;
        }
        .chat-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding-bottom: 12px;
            border-bottom: 1px solid #e9eef9;
        }
        .chat-header-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-left: auto;
        }
        .chat-head-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 42px;
            min-height: 42px;
            padding: 0 12px;
            border: 0;
            border-radius: 12px;
            background: #edf3ff;
            color: var(--brand);
            font: inherit;
            font-size: 0.72rem;
            font-weight: 700;
        }
        .chat-head-btn.danger {
            background: #fff1f1;
            color: #bf3e3e;
        }
        .chat-head-btn svg {
            width: 18px;
            height: 18px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .chat-context {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            border: 1px solid #dce7ff;
            border-radius: 18px;
            background: #f3f7ff;
        }
        .chat-context-media {
            width: 46px;
            height: 46px;
            flex: 0 0 46px;
            border-radius: 14px;
            overflow: hidden;
            background: #dfe9ff;
        }
        .chat-context-media img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .chat-context-body {
            min-width: 0;
            flex: 1;
        }
        .chat-context-label {
            display: block;
            margin-bottom: 2px;
            color: #62728e;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }
        .chat-context-body strong {
            display: block;
            color: #12357f;
            font-size: 0.84rem;
            line-height: 1.35;
        }
        .chat-context-body span {
            color: #5c6c88;
            font-size: 0.74rem;
        }
        .chat-avatar {
            width: 48px;
            height: 48px;
            flex: 0 0 48px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: linear-gradient(180deg, #163f97 0%, #102c73 100%);
            color: #fff;
            font-size: 1rem;
            font-weight: 700;
        }
        .chat-avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
            display: block;
        }
        .chat-title {
            min-width: 0;
            flex: 1;
        }
        .chat-title strong {
            display: block;
            margin-bottom: 3px;
            color: #10296c;
            font-size: 0.94rem;
        }
        .chat-title span {
            color: #5b6b88;
            font-size: 0.76rem;
        }
        .chat-thread {
            display: grid;
            gap: 10px;
            min-height: 240px;
            max-height: 420px;
            overflow-y: auto;
            padding-right: 4px;
        }
        .chat-intro {
            justify-self: start;
            max-width: 82%;
            padding: 10px 12px;
            border-radius: 16px 16px 16px 6px;
            background: #edf3ff;
            color: #35518c;
            font-size: 0.78rem;
            line-height: 1.45;
        }
        .chat-bubble {
            max-width: 82%;
            padding: 10px 12px;
            border-radius: 18px;
            font-size: 0.79rem;
            line-height: 1.45;
            box-shadow: 0 8px 18px rgba(17, 40, 92, 0.06);
        }
        .chat-bubble.outgoing {
            justify-self: end;
            border-radius: 18px 18px 6px 18px;
            background: linear-gradient(180deg, #2857d6 0%, #163f97 100%);
            color: #fff;
        }
        .chat-bubble.incoming {
            justify-self: start;
            border-radius: 18px 18px 18px 6px;
            background: #edf3ff;
            color: #24416f;
        }
        .chat-bubble p {
            margin: 0;
        }
        .chat-image-link {
            display: block;
            margin-bottom: 8px;
        }
        .chat-image {
            display: block;
            width: min(220px, 100%);
            max-width: 100%;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.18);
        }
        .chat-bubble.incoming .chat-image {
            background: #dfe9ff;
            border-color: #c8d9ff;
        }
        .chat-meta {
            margin-top: 6px;
            display: flex;
            justify-content: space-between;
            gap: 10px;
            font-size: 0.66rem;
            opacity: 0.82;
        }
        .chat-bubble.outgoing .chat-meta {
            color: rgba(255, 255, 255, 0.9);
        }
        .chat-bubble.incoming .chat-meta {
            color: #637392;
        }
        .chat-product-tag {
            display: inline-flex;
            margin-top: 8px;
            padding: 5px 8px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.18);
            font-size: 0.64rem;
            font-weight: 700;
        }
        .chat-bubble.incoming .chat-product-tag {
            background: #dfe9ff;
            color: #2952c8;
        }
        .chat-quote-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
            margin-top: 10px;
            padding-top: 9px;
            border-top: 1px solid #cbd9f1;
        }
        .chat-bubble .chat-quote-actions p { width: 100%; font-weight: 700; }
        .chat-quote-actions form { flex: 1 1 110px; }
        .chat-quote-actions button { width: 100%; min-height: 34px; }
        .chat-composer {
            display: grid;
            gap: 10px;
            padding-top: 12px;
            border-top: 1px solid #e9eef9;
        }
        .chat-composer textarea {
            width: 100%;
            min-height: 90px;
            border: 1px solid #dbe5f8;
            border-radius: 18px;
            padding: 12px 14px 12px 56px;
            font: inherit;
            font-size: 0.82rem;
            resize: vertical;
            background: #fff;
        }
        .chat-composer-field {
            position: relative;
        }
        .chat-file-input {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }
        .chat-attach-btn {
            position: absolute;
            left: 12px;
            bottom: 12px;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: inline-grid;
            place-items: center;
            background: #edf3ff;
            color: #1f52cc;
            cursor: pointer;
            font-size: 1.3rem;
            font-weight: 600;
            line-height: 1;
            border: 0;
        }
        .chat-attach-btn.has-file {
            background: #1f52cc;
            color: #fff;
        }
        .chat-composer-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }
        .chat-upload-preview {
            display: none;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border: 1px solid #dbe5f8;
            border-radius: 16px;
            background: #f7faff;
        }
        .chat-upload-preview.is-visible {
            display: flex;
        }
        .chat-upload-preview img {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            object-fit: cover;
            background: #fff;
            border: 1px solid #dbe5f8;
            flex-shrink: 0;
        }
        .chat-upload-preview-text {
            min-width: 0;
        }
        .chat-upload-preview-text strong,
        .chat-upload-preview-text p {
            display: block;
            margin: 0;
        }
        .chat-upload-preview-text strong {
            color: var(--brand);
            font-size: 0.74rem;
        }
        .chat-upload-preview-text p {
            color: #6e7c96;
            font-size: 0.68rem;
            word-break: break-word;
        }
        .message-send-preview-modal {
            position: fixed;
            inset: 0;
            z-index: 1600;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(12, 24, 52, 0.52);
        }
        .message-send-preview-modal.is-visible {
            display: flex;
        }
        .message-send-preview-card {
            width: min(100%, 360px);
            padding: 16px;
            border-radius: 22px;
            background: #fff;
            box-shadow: 0 24px 60px rgba(10, 28, 67, 0.24);
        }
        .message-send-preview-card h4 {
            margin: 0 0 6px;
            color: var(--brand);
            font-size: 0.92rem;
        }
        .message-send-preview-card p {
            margin: 0 0 12px;
            color: #637392;
            font-size: 0.76rem;
            line-height: 1.45;
        }
        .message-send-preview-card img {
            display: block;
            width: 100%;
            max-height: 260px;
            object-fit: contain;
            border-radius: 18px;
            background: #f4f7fc;
            border: 1px solid #dbe5f8;
            margin-bottom: 12px;
        }
        .message-send-preview-meta {
            margin: 0 0 14px;
            color: #637392;
            font-size: 0.7rem;
            word-break: break-word;
        }
        .message-send-preview-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        .message-send-preview-cancel,
        .message-send-preview-confirm {
            border: 0;
            border-radius: 12px;
            padding: 10px 14px;
            font: inherit;
            font-size: 0.76rem;
            font-weight: 700;
            cursor: pointer;
        }
        .message-send-preview-cancel {
            background: #eaf0fb;
            color: #36507f;
        }
        .message-send-preview-confirm {
            background: linear-gradient(180deg, #2857d6 0%, #163f97 100%);
            color: #fff;
        }
        .chat-composer-tip {
            color: #6e7c96;
            font-size: 0.7rem;
        }
        .chat-send-btn {
            border: 0;
            border-radius: 14px;
            padding: 11px 16px;
            background: linear-gradient(180deg, #2857d6 0%, #163f97 100%);
            color: #fff;
            font: inherit;
            font-size: 0.78rem;
            font-weight: 700;
            cursor: pointer;
        }
        .order-card { padding: 14px 0; border-bottom: 1px solid #edf1f8; }
        .order-card:first-child { padding-top: 0; }
        .order-card:last-child { border-bottom: 0; padding-bottom: 0; }
        .order-top { display: flex; justify-content: space-between; gap: 12px; margin-bottom: 8px; }
        .order-top strong { display: block; font-size: 0.92rem; }
        .order-card p { margin: 0 0 6px; color: var(--muted); font-size: 0.8rem; }
        .status-pill { display: inline-flex; padding: 7px 10px; border-radius: 999px; font-size: 0.72rem; font-weight: 700; }
        .status-pill.pending { background: #ffe8b8; color: #9d7004; }
        .status-pill.processing { background: #ffe1c2; color: #a25d04; }
        .status-pill.completed { background: #daf4d6; color: #3f8d43; }
        .empty-state { margin: 0; color: var(--muted); font-size: 0.84rem; }
        .profile-form { display: grid; gap: 12px; }
        .profile-form label { display: block; margin-bottom: 5px; color: var(--muted); font-size: 0.78rem; font-weight: 600; }
        .profile-form input,
        .profile-form textarea {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 12px 14px;
            background: #fff;
            font: inherit;
        }
        .profile-form textarea {
            min-height: 88px;
            resize: vertical;
        }
        .primary-btn {
            border: 0;
            border-radius: 14px;
            padding: 13px 16px;
            background: var(--brand);
            color: #fff;
            font: inherit;
            font-size: 0.86rem;
            font-weight: 700;
            cursor: pointer;
        }
        .profile-shell {
            display: grid;
            gap: 14px;
        }
        .profile-hero {
            display: grid;
            gap: 14px;
            padding: 14px;
            border: 1px solid #e6edf9;
            border-radius: 22px;
            background: linear-gradient(135deg, rgba(255,255,255,0.98) 0%, rgba(247,250,255,0.98) 100%);
            box-shadow: 0 22px 44px rgba(17, 40, 92, 0.10);
        }
        .profile-hero-top {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .profile-avatar {
            width: 74px;
            height: 74px;
            flex: 0 0 74px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: linear-gradient(180deg, #eef3ff 0%, #dce7ff 100%);
            color: var(--brand);
            box-shadow: inset 0 0 0 1px rgba(18, 53, 127, 0.06);
        }
        .profile-avatar.is-clickable {
            overflow: hidden;
            cursor: pointer;
        }
        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .profile-avatar span {
            font-size: 1.7rem;
            font-weight: 700;
            letter-spacing: -0.04em;
        }
        .profile-summary {
            min-width: 0;
            flex: 1;
        }
        .profile-summary h2 {
            margin: 0 0 6px;
            color: #10296c;
            font-size: 1.45rem;
            line-height: 1.05;
            letter-spacing: -0.04em;
        }
        .profile-email {
            margin: 8px 0 0;
            color: #667590;
            font-size: 0.9rem;
            font-weight: 500;
            word-break: break-word;
        }
        .member-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 12px;
            border-radius: 999px;
            background: #eef3ff;
            color: #2857d6;
            font-size: 0.82rem;
            font-weight: 700;
        }
        .member-badge svg,
        .profile-row-icon svg,
        .profile-settings-icon svg {
            width: 18px;
            height: 18px;
            stroke: currentColor;
            fill: none;
            stroke-width: 1.9;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .profile-edit-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            align-self: flex-start;
            padding: 10px 14px;
            border: 2px solid #4d78ff;
            border-radius: 14px;
            background: #fff;
            color: #2450dd;
            font-size: 0.88rem;
            font-weight: 700;
            box-shadow: 0 12px 24px rgba(37, 80, 221, 0.08);
        }
        .profile-edit-btn svg {
            width: 18px;
            height: 18px;
            flex: 0 0 18px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .profile-section-title {
            margin: 0 0 10px;
            color: #10296c;
            font-size: 0.96rem;
            letter-spacing: -0.03em;
        }
        .profile-list,
        .profile-settings-list {
            overflow: hidden;
            border: 1px solid #e6edf9;
            border-radius: 18px;
            background: #fff;
            box-shadow: 0 18px 36px rgba(17, 40, 92, 0.08);
        }
        .language-setting {
            display: grid;
            gap: 8px;
            padding: 12px;
            border-bottom: 1px solid var(--line);
            font-size: 0.78rem;
        }
        .language-choices { display: flex; gap: 6px; }
        .language-choices button {
            flex: 1;
            min-height: 34px;
            border: 1px solid var(--line);
            border-radius: 6px;
            background: #fff;
            color: var(--text);
            font: inherit;
            cursor: pointer;
        }
        .language-choices button.active { border-color: var(--brand); background: #e8f0ff; color: var(--brand); font-weight: 700; }
        .profile-row,
        .profile-settings-row {
            display: flex;
            align-items: center;
            gap: 14px;
            width: 100%;
            padding: 12px 14px;
            border-bottom: 1px solid #edf2fb;
        }
        button.profile-row,
        button.profile-settings-row {
            border: 0;
            background: #fff;
            text-align: left;
            cursor: pointer;
        }
        button.profile-row:hover,
        button.profile-settings-row:hover,
        a.profile-settings-row:hover {
            background: #f8fbff;
        }
        .profile-row:last-child,
        .profile-settings-row:last-child {
            border-bottom: 0;
        }
        .profile-row-icon,
        .profile-settings-icon {
            width: 44px;
            height: 44px;
            flex: 0 0 44px;
            display: grid;
            place-items: center;
            border-radius: 14px;
            background: linear-gradient(180deg, #f2f6ff 0%, #e7efff 100%);
            color: #1e4fd3;
        }
        .profile-settings-icon.logout-tone {
            background: linear-gradient(180deg, #fff1f1 0%, #ffe7e7 100%);
            color: #e23d3d;
        }
        .profile-row-copy,
        .profile-settings-copy {
            min-width: 0;
            flex: 1;
        }
        .profile-row-label {
            margin: 0 0 4px;
            color: #6d7b94;
            font-size: 0.72rem;
            font-weight: 600;
        }
        .profile-row-value {
            margin: 0;
            color: #10296c;
            font-size: 0.82rem;
            font-weight: 700;
            word-break: break-word;
        }
        .profile-settings-copy strong {
            display: block;
            margin-bottom: 4px;
            color: #10296c;
            font-size: 0.86rem;
        }
        .profile-settings-copy p {
            margin: 0;
            color: #6d7b94;
            font-size: 0.74rem;
            line-height: 1.4;
        }
        .profile-chevron {
            color: #6d7b94;
            font-size: 1.35rem;
            line-height: 1;
        }
        .profile-edit-card {
            border: 1px solid #e6edf9;
            border-radius: 22px;
            background: #fff;
            box-shadow: 0 18px 36px rgba(17, 40, 92, 0.08);
            padding: 18px;
        }
        .profile-edit-card .profile-form input {
            border-radius: 16px;
            padding: 14px 15px;
            box-shadow: inset 0 1px 0 rgba(18, 53, 127, 0.02);
        }
        .profile-settings-row.logout-row {
            color: inherit;
        }
        .modal-overlay.profile-modal {
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px 16px 120px;
            z-index: 95;
        }
        .modal-overlay.profile-modal.is-open {
            display: flex;
        }
        .profile-modal-card {
            width: min(100%, 360px);
            position: relative;
            border: 1px solid #e6edf9;
            border-radius: 24px;
            background: #fff;
            box-shadow: 0 28px 56px rgba(17, 40, 92, 0.22);
            padding: 22px 18px 18px;
        }
        .profile-image-viewer {
            width: min(100%, 420px);
            border-radius: 24px;
            overflow: hidden;
            background: #fff;
            box-shadow: 0 28px 56px rgba(17, 40, 92, 0.22);
        }
        .profile-image-viewer img {
            width: 100%;
            height: auto;
            display: block;
            background: #eef3ff;
        }
        .profile-image-viewer-copy {
            padding: 14px 16px 16px;
        }
        .profile-image-viewer-copy strong {
            display: block;
            color: #10296c;
            font-size: 0.92rem;
        }
        .profile-image-viewer-copy p {
            margin: 6px 0 0;
            color: #6d7b94;
            font-size: 0.76rem;
        }
        .profile-modal-card .profile-section-title {
            margin-bottom: 16px;
            padding-right: 34px;
        }
        .profile-modal-close {
            position: absolute;
            top: 14px;
            right: 14px;
            width: 34px;
            height: 34px;
            border: 0;
            border-radius: 50%;
            background: #eff4ff;
            color: var(--brand);
            font-size: 1.3rem;
            line-height: 1;
            cursor: pointer;
        }
        .toggle-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 14px 16px;
            background: #f8fbff;
        }
        .toggle-copy strong {
            display: block;
            margin-bottom: 4px;
            color: #10296c;
            font-size: 0.9rem;
        }
        .toggle-copy p {
            margin: 0;
            color: #6d7b94;
            font-size: 0.78rem;
        }
        .toggle-switch {
            position: relative;
            display: inline-flex;
            align-items: center;
        }
        .toggle-switch input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }
        .toggle-slider {
            width: 52px;
            height: 30px;
            border-radius: 999px;
            background: #cbd8f5;
            position: relative;
            transition: background 0.2s ease;
        }
        .toggle-slider::after {
            content: "";
            position: absolute;
            top: 4px;
            left: 4px;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: #fff;
            box-shadow: 0 3px 8px rgba(16, 41, 108, 0.18);
            transition: transform 0.2s ease;
        }
        .toggle-switch input:checked + .toggle-slider {
            background: #2a5df1;
        }
        .toggle-switch input:checked + .toggle-slider::after {
            transform: translateX(22px);
        }
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(10, 26, 64, 0.45);
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 18px 18px 120px;
            z-index: 180;
            overflow-y: auto;
        }
        .detail-panel {
            width: min(100%, 360px);
            border: 1px solid var(--line);
            border-radius: 18px;
            background: #fff;
            box-shadow: 0 24px 48px rgba(17, 40, 92, 0.18);
            overflow: hidden;
            max-height: calc(100vh - 36px);
            overflow-y: auto;
            margin: auto 0;
        }
        .detail-image-wrap { position: relative; }
        .detail-close {
            position: absolute;
            top: 10px;
            right: 10px;
            width: 28px;
            height: 28px;
            display: grid;
            place-items: center;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.94);
            color: var(--brand);
            font-size: 0.9rem;
            font-weight: 700;
        }
        .detail-image { width: 100%; height: 170px; object-fit: cover; display: block; background: linear-gradient(180deg, #eef2f9 0%, #dfe7f5 100%); }
        .detail-body { padding: 12px; }
        .detail-body h4 { margin: 0 0 4px; font-size: 0.92rem; color: var(--brand); }
        .detail-price { margin: 0 0 8px; color: #f26c0d; font-size: 0.95rem; font-weight: 700; }
        .detail-body p, .detail-field label {
            margin: 0 0 6px;
            color: var(--muted);
            font-size: 0.72rem;
            line-height: 1.45;
        }
        .detail-description {
            max-height: 260px;
            overflow-y: auto;
            padding: 10px;
            border: 1px solid #edf1f8;
            border-radius: 10px;
            background: #fbfdff;
        }
        .detail-head-row {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 10px;
        }
        .detail-title-block {
            min-width: 0;
        }
        .detail-title-block h4 {
            overflow-wrap: anywhere;
        }
        .detail-stock-pill {
            flex: 0 0 auto;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 28px;
            padding: 6px 9px;
            border-radius: 999px;
            background: #edf3ff;
            color: var(--brand);
            font-size: 0.64rem;
            font-weight: 800;
            white-space: nowrap;
        }
        .detail-section-label {
            display: block;
            margin: 12px 0 7px;
            color: #10296c;
            font-size: 0.72rem;
            font-weight: 800;
        }
        .detail-description-text {
            max-height: 116px;
            overflow-y: auto;
        }
        .detail-qty-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 10px;
            border: 1px solid #edf1f8;
            border-radius: 14px;
            background: #fbfdff;
        }
        .detail-qty-row span {
            color: #10296c;
            font-size: 0.76rem;
            font-weight: 800;
        }
        .detail-actions-title {
            display: block;
            color: var(--muted);
            font-size: 0.68rem;
            font-weight: 700;
        }
        .detail-field label { display: block; margin-bottom: 4px; font-weight: 600; }
        .detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin: 10px 0; }
        .detail-field select, .detail-field input {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 7px 8px;
            font: inherit;
            font-size: 0.72rem;
            background: #fff;
        }
        .detail-order { display: grid; gap: 10px; margin-top: 12px; }
        .qty-stepper {
            display: inline-flex;
            align-items: center;
            border: 1px solid var(--line);
            border-radius: 12px;
            overflow: hidden;
            background: #fff;
        }
        .qty-btn {
            width: 34px;
            height: 38px;
            border: 0;
            background: #f3f7ff;
            color: var(--brand);
            font: inherit;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
        }
        .qty-input {
            width: 62px;
            border: 0;
            border-left: 1px solid var(--line);
            border-right: 1px solid var(--line);
            border-radius: 0;
            padding: 9px 8px;
            font: inherit;
            font-size: 0.74rem;
            text-align: center;
        }
        .detail-actions { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
        .detail-panel .detail-actions {
            justify-items: stretch;
        }
        .detail-panel .detail-actions > * {
            width: 100%;
            max-width: none;
        }
        .message-btn {
            border: 1px solid #ffd5b4;
            border-radius: 9px;
            padding: 7px 10px;
            background: #fff4ea;
            color: #f26c0d;
            font: inherit;
            font-size: 0.68rem;
            font-weight: 700;
            cursor: pointer;
            text-align: center;
        }
        .detail-panel {
            border-radius: 26px;
        }
        .detail-image-wrap {
            background: #f4f7fc;
        }
        .detail-image {
            height: min(52vh, 360px);
            object-fit: contain;
            background: #f4f7fc;
        }
        .detail-body {
            padding: 14px;
        }
        .detail-body h4 {
            color: #10296c;
            font-size: 1.04rem;
            line-height: 1.2;
        }
        .detail-price {
            color: #f26c0d;
            font-size: 1.12rem;
        }
        .detail-field select {
            appearance: none;
            color: #10296c;
            font-weight: 800;
            pointer-events: none;
        }
        .detail-order {
            padding-top: 2px;
        }
        .detail-actions {
            grid-template-columns: 1fr 1fr;
        }
        .detail-actions .mini-btn {
            background: #12357f;
        }
        .detail-actions .message-btn,
        .detail-actions .mini-btn,
        .detail-actions .icon-action-btn {
            border-radius: 14px;
            min-height: 44px;
            font-size: 0.72rem;
        }
        .icon-action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 36px;
            border: 1px solid #d9e4ff;
            border-radius: 10px;
            background: #f7faff;
            color: var(--brand);
            cursor: pointer;
        }
        .icon-action-btn svg {
            width: 17px;
            height: 17px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .icon-action-btn.is-disabled {
            opacity: 0.65;
        }
        .mini-btn {
            border: 0;
            border-radius: 9px;
            padding: 7px 10px;
            background: var(--brand);
            color: #fff;
            font: inherit;
            font-size: 0.68rem;
            font-weight: 700;
            cursor: pointer;
            text-align: center;
        }
        .category-popup {
            width: min(100%, 240px);
            border: 1px solid var(--line);
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 24px 48px rgba(17, 40, 92, 0.18);
            padding: 16px;
            text-align: center;
        }
        .category-popup .category-icon {
            width: 30px;
            height: 30px;
            margin-bottom: 8px;
        }
        .category-popup .category-svg {
            width: 30px;
            height: 30px;
            margin-bottom: 8px;
        }
        .category-popup h4 {
            margin: 0 0 6px;
            color: var(--brand);
            font-size: 0.9rem;
        }
        .category-popup p {
            margin: 0 0 12px;
            color: var(--muted);
            font-size: 0.72rem;
        }
        .category-popup .actions {
            display: flex;
            gap: 8px;
            justify-content: center;
        }
        .category-popup .ghost-btn {
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 8px 10px;
            background: #f7f9ff;
            color: var(--brand);
            font-size: 0.72rem;
            font-weight: 700;
        }
        .filter-popup {
            width: min(calc(100% - 28px), 360px);
            max-height: min(78vh, 560px);
            overflow: auto;
            padding: 22px 18px 18px;
            border-radius: 26px;
            background: linear-gradient(180deg, rgba(255,255,255,0.98) 0%, rgba(245,248,255,0.98) 100%);
            border: 1px solid rgba(18, 53, 127, 0.12);
            box-shadow: 0 28px 60px rgba(9, 24, 61, 0.24), 0 10px 20px rgba(18, 53, 127, 0.12), inset 0 1px 0 rgba(255,255,255,0.75);
            transform: perspective(1200px) rotateX(8deg);
            animation: filterPopupIn 0.22s ease;
        }
        .filter-overlay {
            z-index: 180;
            align-items: flex-start;
            padding-top: 86px;
        }
        .filter-popup-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
        }
        .filter-popup-head h4 {
            margin: 0;
            color: var(--brand);
            font-size: 1rem;
        }
        .filter-popup-head p {
            margin: 4px 0 0;
            color: #66748e;
            font-size: 0.74rem;
        }
        .filter-popup-close {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            background: #eef2ff;
            color: var(--brand);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            line-height: 1;
            box-shadow: 0 8px 18px rgba(18, 53, 127, 0.12);
        }
        .filter-popup-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }
        .filter-popup-card {
            display: grid;
            justify-items: center;
            gap: 8px;
            min-height: 92px;
            padding: 14px 10px;
            border-radius: 18px;
            border: 1px solid rgba(18, 53, 127, 0.1);
            background: linear-gradient(180deg, #ffffff 0%, #f3f7ff 100%);
            box-shadow: 0 14px 24px rgba(18, 53, 127, 0.08), inset 0 1px 0 rgba(255,255,255,0.7);
            transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
            text-align: center;
        }
        .filter-popup-card:hover,
        .filter-popup-card:focus-visible {
            transform: translateY(-3px) scale(1.01);
            border-color: rgba(18, 53, 127, 0.2);
            box-shadow: 0 18px 28px rgba(18, 53, 127, 0.12), inset 0 1px 0 rgba(255,255,255,0.8);
        }
        .filter-popup-card span {
            font-size: 0.72rem;
            font-weight: 700;
            color: #16336f;
            line-height: 1.25;
        }
        .filter-popup-card.is-default {
            background: linear-gradient(180deg, #12357f 0%, #0f2e70 100%);
            border-color: rgba(18, 53, 127, 0.28);
            box-shadow: 0 18px 30px rgba(18, 53, 127, 0.2), inset 0 1px 0 rgba(255,255,255,0.16);
        }
        .filter-popup-card.is-default span,
        .filter-popup-card.is-default .filter-popup-default-icon {
            color: #fff;
        }
        .filter-popup-default-icon {
            width: 28px;
            height: 28px;
            display: grid;
            place-items: center;
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--brand);
        }
        .zoomable-image-btn {
            padding: 0;
            border: 0;
            background: transparent;
            cursor: zoom-in;
        }
        .image-lightbox {
            position: relative;
            width: min(calc(100% - 24px), 380px);
            padding: 12px;
            border-radius: 22px;
            background: rgba(255, 255, 255, 0.98);
            box-shadow: 0 28px 60px rgba(9, 24, 61, 0.28);
        }
        #imageLightboxModal {
            z-index: 2200;
            align-items: center;
            justify-content: center;
            padding: 18px;
            background: rgba(5, 15, 38, 0.78);
        }
        #imageLightboxModal.is-open {
            display: flex;
        }
        #imageLightboxModal .image-lightbox {
            width: min(calc(100vw - 24px), 390px);
            max-height: calc(100dvh - 36px);
            overflow: auto;
        }
        .image-lightbox-close {
            position: absolute;
            top: 10px;
            right: 10px;
            width: 34px;
            height: 34px;
            border: 0;
            border-radius: 10px;
            background: rgba(18, 53, 127, 0.9);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            line-height: 1;
            cursor: pointer;
            box-shadow: 0 10px 18px rgba(9, 24, 61, 0.22);
        }
        .image-lightbox img {
            width: 100%;
            max-height: 72vh;
            object-fit: contain;
            display: block;
            border-radius: 16px;
            background: #eef2f9;
        }
        .image-lightbox-copy {
            padding: 10px 4px 2px;
            color: var(--text);
            font-size: 0.76rem;
            font-weight: 700;
            text-align: center;
        }
        @keyframes filterPopupIn {
            from {
                opacity: 0;
                transform: perspective(1200px) rotateX(14deg) translateY(14px) scale(0.97);
            }
            to {
                opacity: 1;
                transform: perspective(1200px) rotateX(8deg) translateY(0) scale(1);
            }
        }
        .mini-btn {
            border: 0;
            border-radius: 9px;
            padding: 7px 10px;
            background: var(--brand);
            color: #fff;
            font: inherit;
            font-size: 0.68rem;
            font-weight: 700;
            cursor: pointer;
        }
        .bottom-nav {
            position: fixed;
            left: 50%;
            bottom: 0;
            transform: translateX(-50%);
            width: min(100%, 430px);
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            align-items: center;
            padding: 4px 4px 6px;
            background: linear-gradient(180deg, var(--brand) 0%, var(--brand-deep) 100%);
            color: rgba(255,255,255,0.84);
            z-index: 120;
        }
        .nav-item {
            display: grid;
            justify-items: center;
            align-content: center;
            gap: 3px;
            min-width: 0;
            min-height: 42px;
            font-size: 0.5rem;
            font-weight: 600;
            line-height: 1.1;
            text-align: center;
            position: relative;
        }
        .nav-item span:last-child {
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .nav-item.active { color: #fff; }
        .nav-badge {
            position: absolute;
            top: -2px;
            left: calc(50% + 7px);
            right: auto;
            min-width: 16px;
            height: 16px;
            padding: 0 4px;
            border-radius: 999px;
            background: #ff8b1f;
            color: #fff;
            font-size: 0.56rem;
            font-weight: 700;
            display: inline-grid;
            place-items: center;
            line-height: 1;
        }
        .nav-badge.is-hidden {
            display: none;
        }
        .nav-icon {
            width: 19px;
            height: 19px;
            display: grid;
            place-items: center;
        }
        .nav-icon svg {
            width: 19px;
            height: 19px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .nav-toggle-input {
            position: fixed;
            width: 1px;
            height: 1px;
            opacity: 0;
            pointer-events: none;
        }
        .mobile-menu-toggle {
            position: fixed;
            right: 14px;
            bottom: 14px;
            z-index: 180;
            display: none;
            width: 48px;
            height: 48px;
            border-radius: 16px;
            border: 1px solid rgba(255,255,255,0.28);
            background: linear-gradient(180deg, var(--brand) 0%, var(--brand-deep) 100%);
            color: #fff;
            box-shadow: 0 14px 28px rgba(10, 26, 64, 0.24);
            cursor: pointer;
        }
        .mobile-menu-toggle span,
        .mobile-menu-toggle::before,
        .mobile-menu-toggle::after {
            content: "";
            position: absolute;
            left: 14px;
            right: 14px;
            height: 2px;
            border-radius: 999px;
            background: currentColor;
        }
        .mobile-menu-toggle::before { top: 15px; }
        .mobile-menu-toggle span { top: 23px; }
        .mobile-menu-toggle::after { top: 31px; }
        .app,
        .header,
        .content,
        .bottom-nav,
        .modal-overlay,
        .detail-panel,
        .profile-modal-card,
        .mix-panel,
        .chat-shell {
            min-width: 0;
        }
        .stack-panel,
        .profile-panel,
        .chat-shell,
        .mix-panel,
        .detail-panel,
        .profile-modal-card,
        .filter-popup,
        .image-lightbox {
            max-width: 100%;
        }
        .chat-composer-actions,
        .message-send-preview-actions,
        .order-top,
        .section-head,
        .brand-row {
            flex-wrap: wrap;
        }
        .primary-btn,
        .mini-btn,
        .message-btn,
        .icon-action-btn,
        .product-view-btn,
        .chat-send-btn,
        .message-send-preview-cancel,
        .message-send-preview-confirm {
            min-height: 44px;
            white-space: normal;
            overflow-wrap: anywhere;
        }
        table {
            display: block;
            max-width: 100%;
            overflow-x: auto;
            border-collapse: collapse;
            -webkit-overflow-scrolling: touch;
        }
        th,
        td {
            white-space: nowrap;
        }
        @media (min-width: 560px) {
            .app:not(.mix-app) {
                max-width: min(100%, 720px);
            }
            .products-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 12px;
            }
            .categories-grid {
                grid-template-columns: repeat(6, minmax(0, 1fr));
                gap: 10px;
            }
            .product-body h4 {
                font-size: 0.74rem;
            }
            .product-price {
                font-size: 0.74rem;
            }
            .product-view-btn {
                font-size: 0.66rem;
                padding-inline: 10px;
            }
            .detail-panel,
            .profile-modal-card,
            .message-send-preview-card {
                width: min(100%, 520px);
            }
        }
        @media (min-width: 768px) {
            .app,
            .app.mix-app {
                max-width: min(100%, 1180px);
            }
            .header,
            .content {
                width: 100%;
            }
            .content:not(.home-content) {
                padding-left: clamp(20px, 4vw, 40px);
                padding-right: clamp(20px, 4vw, 40px);
            }
            .home-content .search-form,
            .home-content .hero-card,
            .home-content #categories,
            .home-content .shop-scroll {
                width: min(100%, 1040px);
                margin-left: auto;
                margin-right: auto;
            }
            .hero-slider,
            .hero-grid,
            .hero-visual {
                min-height: min(var(--hero-height, 220px), 260px);
                max-height: min(var(--hero-height, 220px), 260px);
            }
            .products-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
            .profile-shell,
            .design-list {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                align-items: start;
            }
            .profile-hero,
            .profile-edit-card {
                grid-column: 1 / -1;
            }
            .chat-shell {
                width: min(100%, 860px);
                margin: 0 auto;
            }
            .detail-panel {
                width: min(100%, 680px);
            }
            .detail-image {
                height: clamp(220px, 38vw, 340px);
            }
        }
        @media (min-width: 1024px) {
            .app,
            .app.mix-app {
                max-width: none;
                min-height: 100vh;
                height: 100vh;
                margin: 0;
                display: grid;
                grid-template-columns: 232px minmax(0, 1fr);
                grid-template-rows: auto minmax(0, 1fr);
            }
            .header {
                grid-column: 2;
                position: sticky;
                top: 0;
            }
            .content {
                grid-column: 2;
                padding: clamp(22px, 3vw, 38px);
                padding-bottom: clamp(22px, 3vw, 38px);
            }
            .bottom-nav {
                left: 0;
                top: 0;
                bottom: 0;
                transform: none;
                width: 232px;
                grid-template-columns: 1fr;
                grid-auto-rows: min-content;
                align-content: start;
                gap: 6px;
                padding: 22px 14px;
                border-right: 1px solid rgba(255,255,255,0.16);
            }
            .nav-item {
                grid-template-columns: 24px minmax(0, 1fr);
                justify-items: start;
                align-content: center;
                min-height: 50px;
                padding: 0 12px;
                border-radius: 14px;
                font-size: 0.82rem;
                text-align: left;
            }
            .nav-item:hover,
            .nav-item.active {
                background: rgba(255,255,255,0.12);
            }
            .nav-item span:last-child {
                white-space: normal;
            }
            .nav-badge {
                top: 5px;
                left: 28px;
            }
            .mobile-menu-toggle {
                display: none;
            }
            .products-grid {
                grid-template-columns: repeat(5, minmax(0, 1fr));
            }
            .categories-grid {
                grid-template-columns: repeat(8, minmax(0, 1fr));
            }
        }
        @media (min-width: 1366px) {
            .content {
                padding-left: clamp(36px, 5vw, 72px);
                padding-right: clamp(36px, 5vw, 72px);
            }
            .products-grid {
                grid-template-columns: repeat(6, minmax(0, 1fr));
            }
        }
        @media (max-width: 1023px) {
            .mobile-menu-toggle {
                display: block;
            }
            .bottom-nav {
                left: auto;
                right: 14px;
                bottom: 72px;
                transform: translateY(12px);
                width: min(calc(100vw - 28px), 320px);
                grid-template-columns: 1fr;
                gap: 4px;
                padding: 10px;
                border-radius: 18px;
                opacity: 0;
                pointer-events: none;
                box-shadow: 0 22px 44px rgba(10, 26, 64, 0.24);
                transition: opacity 0.18s ease, transform 0.18s ease;
            }
            .nav-toggle-input:checked ~ .bottom-nav {
                opacity: 1;
                pointer-events: auto;
                transform: translateY(0);
            }
            .nav-item {
                grid-template-columns: 24px minmax(0, 1fr) auto;
                justify-items: start;
                min-height: 46px;
                padding: 8px 12px;
                border-radius: 12px;
                font-size: 0.78rem;
                text-align: left;
            }
            .nav-item:hover,
            .nav-item.active {
                background: rgba(255,255,255,0.12);
            }
            .nav-item span:last-child {
                white-space: normal;
            }
            .nav-badge {
                position: static;
                margin-left: auto;
            }
        }
        @media (max-width: 374px) {
            .content {
                padding-left: 10px;
                padding-right: 10px;
            }
            .categories-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .products-grid,
            .detail-grid,
            .filter-popup-grid,
            .customization-product-card,
            .customization-request-head,
            .mix-selected-item {
                grid-template-columns: 1fr;
            }
            .product-media {
                min-height: 96px;
            }
            .detail-actions,
            .message-send-preview-actions,
            .chat-composer-actions {
                grid-template-columns: 1fr;
                display: grid;
            }
        }
        @media (min-width: 1100px) {
            .mix-app .bottom-nav {
                width: 232px;
            }
            .mix-shell {
                grid-template-columns: clamp(260px, 22vw, 320px) minmax(480px, 1fr) clamp(290px, 24vw, 340px);
                align-items: start;
            }
            .mix-shell > .section-head,
            .mix-shell > .mix-page-tabs,
            .mix-shell > [data-mix-page-panel="builder"],
            .mix-shell > [data-mix-page-panel="customize"] {
                grid-column: 1 / -1;
            }
            [data-mix-page-panel="builder"] {
                grid-template-columns: clamp(260px, 22vw, 320px) minmax(480px, 1fr) clamp(290px, 24vw, 340px);
                align-items: start;
            }
            [data-mix-page-panel="builder"] > .room-tabs {
                grid-column: 1 / -1;
            }
            .mix-picker-panel {
                grid-column: 1;
            }
            .mix-preview-panel {
                grid-column: 2;
            }
            .mix-selected-panel {
                grid-column: 3;
            }
            .room-tabs {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
        }
        @media (min-width: 760px) and (max-width: 1099px) {
            .app.mix-app {
                width: 100%;
            }
            .mix-app .content {
                padding-left: 18px;
                padding-right: 18px;
            }
            .mix-shell {
                grid-template-columns: minmax(260px, 0.82fr) minmax(420px, 1.18fr);
                align-items: start;
            }
            .mix-shell > .section-head,
            .mix-shell > .mix-page-tabs,
            .mix-shell > [data-mix-page-panel="builder"],
            .mix-shell > [data-mix-page-panel="customize"] {
                grid-column: 1 / -1;
            }
            [data-mix-page-panel="builder"] {
                grid-template-columns: minmax(260px, 0.82fr) minmax(420px, 1.18fr);
                align-items: start;
            }
            [data-mix-page-panel="builder"] > .room-tabs {
                grid-column: 1 / -1;
            }
            .mix-picker-panel {
                grid-column: 1;
            }
            .mix-preview-panel {
                grid-column: 2;
            }
            .mix-selected-panel {
                grid-column: 1 / -1;
            }
            .room-tabs {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
            .mix-board {
                height: clamp(480px, 62vh, 660px);
            }
        }
        @media (max-width: 640px) {
            .app.mix-app {
                width: 100%;
            }
            .mix-product {
                grid-template-columns: 64px minmax(0, 1fr);
            }
            .mix-product .mix-add-btn {
                grid-column: 1 / -1;
                width: 100%;
            }
            .mix-board {
                height: clamp(300px, 43vh, 380px);
                min-height: 300px;
                border-radius: 14px;
            }
            .mix-board-tools {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .mix-mini-btn {
                min-height: 40px;
                width: 100%;
                padding: 8px 10px;
            }
            .mix-room-actions,
            .mix-actions,
            .mix-total,
            .customization-grid {
                grid-template-columns: 1fr;
            }
            .mix-line-actions {
                display: flex;
                justify-content: flex-end;
            }
            .mix-line-actions .mix-mini-btn {
                width: auto;
                min-height: 34px;
                padding: 6px 8px;
            }
        }
        @media (max-width: 420px) {
            .hero-slider { min-height: min(var(--hero-mobile-height, 110px), 110px); }
            .hero-grid { grid-template-columns: 1fr; min-height: min(var(--hero-mobile-height, 110px), 110px); }
            .hero-visual { min-height: min(var(--hero-mobile-height, 110px), 110px); max-height: min(var(--hero-mobile-height, 110px), 110px); }
            .products-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .header.profile-header {
                padding-bottom: 74px;
            }
            .profile-hero-top {
                align-items: flex-start;
            }
            .profile-avatar {
                width: 68px;
                height: 68px;
                flex-basis: 68px;
            }
            .profile-summary h2 {
                font-size: 1.28rem;
            }
            .nav-item {
                font-size: 0.76rem;
            }
            .nav-icon svg {
                width: 17px;
                height: 17px;
            }
            .mix-panel {
                padding: 10px;
            }
            .mix-board-tools {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .mix-page-tab {
                min-height: 40px;
                padding: 8px;
                font-size: 0.7rem;
            }
        }
        @media (max-width: 360px) {
            .app,
            .app.mix-app {
                max-width: none;
                width: 100vw;
            }
            .header,
            .content {
                padding-left: 10px;
                padding-right: 10px;
            }
            .brand-row,
            .section-head,
            .chat-header,
            .chat-context,
            .profile-hero-top,
            .order-top {
                align-items: flex-start;
            }
            .brand-title {
                font-size: 1rem;
                overflow-wrap: anywhere;
            }
            .products-grid,
            .categories-grid,
            .detail-grid,
            .detail-actions,
            .filter-popup-grid,
            .customization-grid,
            .customization-product-card,
            .customization-request-head,
            .mix-selected-item,
            .mix-total,
            .mix-actions,
            .mix-room-actions {
                grid-template-columns: 1fr;
            }
            .product-media {
                min-height: 150px;
            }
            .product-body h4,
            .product-price {
                font-size: 0.78rem;
            }
            .product-view-btn,
            .primary-btn,
            .mini-btn,
            .message-btn,
            .chat-send-btn {
                width: 100%;
            }
            .chat-bubble,
            .chat-intro {
                max-width: 100%;
            }
            .modal-overlay {
                padding-left: 10px;
                padding-right: 10px;
            }
            .bottom-nav {
                right: 8px;
                width: calc(100vw - 16px);
            }
            .mobile-menu-toggle {
                right: 8px;
            }
        }
        @media (max-width: 1023px) {
            .mobile-menu-toggle {
                display: none;
            }
            .bottom-nav {
                left: 0;
                right: 0;
                bottom: 0;
                transform: none;
                width: auto;
                max-width: none;
                display: grid;
                grid-template-columns: repeat(5, minmax(0, 1fr));
                gap: 0;
                padding: 5px 3px calc(6px + env(safe-area-inset-bottom));
                border-radius: 0;
                opacity: 1;
                pointer-events: auto;
                box-shadow: none;
            }
            .nav-toggle-input:checked ~ .bottom-nav {
                transform: none;
            }
            .nav-item {
                display: grid;
                grid-template-columns: 1fr;
                justify-items: center;
                align-content: center;
                min-height: 48px;
                padding: 6px 1px;
                border-radius: 10px;
                font-size: clamp(0.48rem, 2.1vw, 0.6rem);
                text-align: center;
                gap: 2px;
            }
            .nav-item span:last-child {
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .nav-badge {
                position: absolute;
                top: 1px;
                left: calc(50% + 8px);
                margin-left: 0;
            }
        }
        .orders-back-link {
            display: inline-flex;
            align-items: center;
            min-height: 36px;
            margin: 0 0 12px;
            color: var(--brand);
            font-size: 0.78rem;
            font-weight: 800;
        }
        @media (min-width: 361px) and (max-width: 767px) {
            .app,
            .app.mix-app {
                max-width: none;
                width: 100%;
                margin-left: 0;
                margin-right: 0;
            }
            .content {
                padding-left: 14px;
                padding-right: 14px;
            }
            .products-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 10px;
            }
            .categories-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 8px;
            }
            .product-media {
                min-height: 118px;
            }
            .product-body h4 {
                font-size: 0.64rem;
            }
            .product-price {
                font-size: 0.64rem;
            }
            .product-view-btn {
                min-height: 36px;
                padding: 7px 8px;
                font-size: 0.56rem;
            }
            .hero-slider,
            .hero-grid,
            .hero-visual {
                min-height: min(var(--hero-mobile-height, 120px), 140px);
                max-height: min(var(--hero-mobile-height, 120px), 140px);
            }
            .bottom-nav {
                left: 0;
                right: 0;
                width: auto;
                transform: none;
            }
            .nav-toggle-input:checked ~ .bottom-nav {
                transform: none;
            }
        }
        @media (max-width: 767px) {
            html,
            body {
                width: 100%;
                max-width: 100%;
                overflow-x: hidden;
            }
            .app,
            .app.mix-app,
            .header,
            .content,
            .shop-scroll,
            .home-content .search-form,
            .home-content .hero-card,
            .home-content #categories,
            .home-content .shop-scroll {
                width: 100%;
                max-width: none;
            }
            .content {
                padding-left: clamp(10px, 3.5vw, 16px);
                padding-right: clamp(10px, 3.5vw, 16px);
            }
            .products-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: clamp(8px, 2.5vw, 12px);
            }
            .product-card,
            .category-card,
            .hero-card,
            .search-shell {
                min-width: 0;
            }
            .product-media {
                min-height: clamp(104px, 30vw, 150px);
            }
            .product-body {
                padding: 8px;
            }
            .product-body h4,
            .product-price {
                overflow-wrap: anywhere;
            }
            .product-card-actions {
                justify-content: stretch;
            }
            .product-view-btn {
                width: 100%;
                min-height: 34px;
                padding: 7px 6px;
                font-size: clamp(0.52rem, 2.2vw, 0.62rem);
                line-height: 1.15;
            }
            .categories-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: clamp(6px, 2vw, 9px);
            }
            .category-card span,
            .nav-item span:last-child {
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .bottom-nav {
                left: 0;
                right: 0;
                width: auto;
                max-width: none;
                transform: none;
            }
            .nav-toggle-input:checked ~ .bottom-nav {
                transform: none;
            }
            .nav-item {
                min-width: 0;
                overflow: hidden;
            }
        }
        @media (max-width: 330px) {
            .products-grid,
            .categories-grid {
                grid-template-columns: 1fr;
            }
            .product-media {
                min-height: 150px;
            }
        }
        @media (max-width: 767px) {
            .app,
            .app.mix-app {
                width: 100%;
                max-width: none;
                height: 100dvh;
                margin: 0;
                display: flex;
            }
            .header {
                grid-column: auto;
                position: relative;
                padding: 14px 14px 16px;
            }
            .content {
                grid-column: auto;
                padding: 16px clamp(10px, 3.5vw, 16px) calc(84px + env(safe-area-inset-bottom));
            }
            .content.home-content {
                padding-bottom: 0;
            }
            .shop-scroll {
                padding-bottom: calc(92px + env(safe-area-inset-bottom));
            }
            .products-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: clamp(8px, 2.6vw, 12px);
            }
            .categories-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
            .bottom-nav {
                left: 0;
                right: 0;
                top: auto;
                bottom: 0;
                transform: none;
                width: auto;
                max-width: none;
                grid-template-columns: repeat(5, minmax(0, 1fr));
                padding: 5px 3px calc(6px + env(safe-area-inset-bottom));
                border-radius: 0;
            }
            .nav-toggle-input:checked ~ .bottom-nav {
                transform: none;
            }
            .nav-item {
                grid-template-columns: 1fr;
                justify-items: center;
                min-height: 48px;
                padding: 6px 1px;
                font-size: clamp(0.48rem, 2.1vw, 0.6rem);
                text-align: center;
            }
        }
        @media (min-width: 768px) and (max-width: 1023px) {
            .app,
            .app.mix-app {
                width: 100%;
                max-width: none;
                margin: 0;
            }
            .content {
                padding-left: clamp(22px, 4vw, 36px);
                padding-right: clamp(22px, 4vw, 36px);
            }
            .products-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
            .categories-grid {
                grid-template-columns: repeat(6, minmax(0, 1fr));
            }
        }
        @media (min-width: 1024px) {
            .app,
            .app.mix-app {
                width: 100%;
                max-width: none;
                height: 100vh;
                margin: 0;
                display: grid;
                grid-template-columns: 248px minmax(0, 1fr);
                grid-template-rows: auto minmax(0, 1fr);
            }
            .header {
                grid-column: 2;
                padding: 18px clamp(28px, 4vw, 56px);
            }
            .content {
                grid-column: 2;
                padding: clamp(24px, 3vw, 42px) clamp(28px, 4vw, 64px);
            }
            .content.home-content {
                overflow-y: auto;
                padding-bottom: clamp(24px, 3vw, 42px);
            }
            .home-content .search-form,
            .home-content .hero-card,
            .home-content #categories,
            .home-content .shop-scroll {
                width: 100%;
                max-width: none;
            }
            .shop-scroll {
                overflow: visible;
                padding-bottom: 0;
            }
            .products-grid {
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                gap: 18px;
            }
            .categories-grid {
                grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
                gap: 12px;
            }
            .bottom-nav {
                left: 0;
                top: 0;
                bottom: 0;
                right: auto;
                transform: none;
                width: 248px;
                max-width: none;
                grid-template-columns: 1fr;
                align-content: start;
                gap: 8px;
                padding: 24px 14px;
                border-radius: 0;
            }
            .nav-item {
                grid-template-columns: 24px minmax(0, 1fr);
                justify-items: start;
                min-height: 52px;
                padding: 0 14px;
                font-size: 0.86rem;
                text-align: left;
            }
            .nav-item span:last-child {
                white-space: normal;
            }
        }
        @media (max-width: 767px) {
            body {
                background: #fff;
            }
            .app,
            .app.mix-app {
                width: min(100%, 430px);
                max-width: 430px;
                height: 100dvh;
                min-height: 100vh;
                margin: 0 auto;
                display: flex;
                flex-direction: column;
            }
            .header {
                grid-column: auto;
                position: relative;
                padding: 14px 18px 16px;
            }
            .content {
                grid-column: auto;
                padding: 20px 16px 104px;
            }
            .content.home-content {
                display: flex;
                flex-direction: column;
                overflow: hidden;
                padding-bottom: 0;
            }
            .home-content .search-form,
            .home-content .hero-card,
            .home-content #categories,
            .home-content .shop-scroll {
                width: 100%;
                max-width: none;
            }
            .shop-scroll {
                flex: 1;
                min-height: 0;
                overflow-y: auto;
                padding-bottom: 104px;
            }
            .products-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
            }
            .categories-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 6px;
            }
            .product-media {
                min-height: 118px;
            }
            .product-body h4 {
                font-size: 0.6rem;
            }
            .product-price {
                font-size: 0.62rem;
            }
            .product-view-btn {
                min-height: 36px;
                font-size: 0.54rem;
                padding: 6px 7px;
            }
            .bottom-nav {
                left: 50%;
                right: auto;
                top: auto;
                bottom: 0;
                transform: translateX(-50%);
                width: min(100%, 430px);
                max-width: 430px;
                display: grid;
                grid-template-columns: repeat(5, minmax(0, 1fr));
                align-content: normal;
                gap: 0;
                padding: 5px 4px calc(6px + env(safe-area-inset-bottom));
                border-radius: 0;
            }
            .nav-toggle-input:checked ~ .bottom-nav {
                transform: translateX(-50%);
            }
            .nav-item {
                display: grid;
                grid-template-columns: 1fr;
                justify-items: center;
                align-content: center;
                min-height: 48px;
                padding: 6px 2px;
                border-radius: 10px;
                font-size: 0.54rem;
                text-align: center;
            }
            .nav-item span:last-child {
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .nav-badge {
                position: absolute;
                top: 1px;
                left: calc(50% + 8px);
                margin-left: 0;
            }
        }
    </style>
    <link rel="stylesheet" href="responsive.css">
    <script src="responsive.js" defer></script>
</head>
<body data-language-role="user">
    <div class="app <?= $selectedView === 'mix' ? 'mix-app' : '' ?>">
        <header class="header <?= $selectedView === 'profile' ? 'profile-header' : '' ?>">
            <div class="brand-row">
                <?php if ($selectedView === 'profile'): ?>
                    <div class="brand-block">
                        <div class="brand-title-wrap">
                            <h1 class="brand-title">RN Furniture</h1>
                            <svg class="brand-mark" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M5 12a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v4H5v-4Z"></path>
                                <path d="M7 10V8a2 2 0 0 1 2-2h1v4"></path>
                                <path d="M14 6h1a2 2 0 0 1 2 2v2"></path>
                                <path d="M4 16h16"></path>
                                <path d="M6 16v2"></path>
                                <path d="M18 16v2"></path>
                            </svg>
                        </div>
                        <p class="brand-subtitle">Quality furniture for every home.</p>
                    </div>
                <?php else: ?>
                    <h1 class="brand-title">RN Furniture</h1>
                <?php endif; ?>
                <a class="top-profile-link <?= in_array($selectedView, ['profile', 'wishlist'], true) ? 'active' : '' ?> <?= $profileImage !== '' ? 'has-image' : '' ?>" href="user.php?view=profile" aria-label="Open profile">
                    <?php if ($profileImage !== ''): ?>
                        <img src="<?= htmlspecialchars($profileImage, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($profileName, ENT_QUOTES, 'UTF-8') ?>">
                    <?php else: ?>
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <circle cx="12" cy="8" r="4"></circle>
                            <path d="M4 21a8 8 0 0 1 16 0"></path>
                        </svg>
                    <?php endif; ?>
                </a>
            </div>
        </header>

        <main class="content <?= $selectedView === 'profile' ? 'profile-content' : '' ?> <?= $selectedView === 'home' ? 'home-content' : '' ?>">
            <?php if ($notice !== ''): ?>
                <div class="notice <?= htmlspecialchars($noticeType, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if ($selectedView === 'home'): ?>
                <a class="dashboard-message-alert" data-admin-message-alert href="user.php?view=messages" <?= $userUnreadAdminMessageCount > 0 ? '' : 'hidden' ?>>
                    <span><strong>New message from admin</strong><span data-admin-message-count><?= $userUnreadAdminMessageCount ?> unread <?= $userUnreadAdminMessageCount === 1 ? 'message' : 'messages' ?></span></span>
                    <span class="alert-action">View messages</span>
                </a>
                <form class="search-form" method="get">
                    <input type="hidden" name="view" value="home">
                    <input type="hidden" name="category" value="<?= htmlspecialchars($selectedCategory, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="search-shell">
                        <svg class="search-icon" viewBox="0 0 24 24" aria-hidden="true">
                            <circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2" fill="none"></circle>
                            <path d="M16.5 16.5L21 21" stroke="currentColor" stroke-width="2" stroke-linecap="round"></path>
                        </svg>
                        <input type="text" name="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="Search furniture...">
                        <button type="submit">Search</button>
                    </div>
                </form>

                <section class="hero-card" style="--hero-height: <?= htmlspecialchars($sliderSettings['height'], ENT_QUOTES, 'UTF-8') ?>px; --hero-mobile-height: <?= htmlspecialchars($sliderSettings['mobile_height'], ENT_QUOTES, 'UTF-8') ?>px; --hero-fit: <?= htmlspecialchars($sliderSettings['image_fit'], ENT_QUOTES, 'UTF-8') ?>; --hero-bg: <?= htmlspecialchars($sliderSettings['background_color'], ENT_QUOTES, 'UTF-8') ?>; --hero-card-color: <?= htmlspecialchars($sliderSettings['card_color'], ENT_QUOTES, 'UTF-8') ?>; --hero-dot-color: <?= htmlspecialchars($sliderSettings['dot_color'], ENT_QUOTES, 'UTF-8') ?>; --hero-dot-active-color: <?= htmlspecialchars($sliderSettings['dot_active_color'], ENT_QUOTES, 'UTF-8') ?>;">
                    <div class="hero-slider" data-hero-slider>
                        <?php foreach ($heroSlides as $index => $heroSlide): ?>
                            <article class="hero-slide <?= $index === 0 ? 'is-active' : '' ?>">
                                <div class="hero-grid">
                                    <div class="hero-visual">
                                        <span class="hero-brand">RN Furniture</span>
                                        <img src="<?= htmlspecialchars((string) ($heroSlide['image'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string) ($heroSlide['alt'] ?? 'Furniture banner'), ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <div class="hero-dots">
                        <?php foreach ($heroSlides as $index => $heroSlide): ?>
                            <span class="<?= $index === 0 ? 'active' : '' ?>"></span>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section id="categories">
                    <div class="section-head">
                        <h3>Categories</h3>
                        <a class="section-link" href="user.php?view=home&amp;filter=categories">More</a>
                    </div>
                    <div class="categories-grid">
                        <a class="category-card" href="user.php?view=home&amp;show=all#popularProducts">
                            <div class="category-icon furniture"></div>
                            <span>All</span>
                        </a>
                        <?php if ($categoryCards === []): ?>
                            <?php foreach (['Sofa', 'Chair', 'Bed'] as $fallback): ?>
                                <a class="category-card" href="user.php?view=home&amp;category=<?= urlencode($fallback) ?>&amp;show=all#popularProducts">
                                    <div class="category-icon <?= htmlspecialchars(normalizeCategoryIcon($fallback), ENT_QUOTES, 'UTF-8') ?>"></div>
                                    <span><?= htmlspecialchars($fallback, ENT_QUOTES, 'UTF-8') ?></span>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <?php foreach ($visibleCategoryCards as $categoryCard): ?>
                                <a class="category-card" href="user.php?view=home&amp;category=<?= urlencode($categoryCard['name']) ?>&amp;show=all#popularProducts">
                                    <?= renderCategoryIcon((string) ($categoryCard['name'] ?? '')) ?>
                                    <span><?= htmlspecialchars($categoryCard['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>

                <div class="shop-scroll">
                    <section id="popularProducts">
                        <div class="section-head">
                            <h3>Popular Products</h3>
                            <?php if ($filteredProducts !== []): ?>
                                <a class="section-link" href="user.php?view=home&amp;category=<?= urlencode($selectedCategory) ?>&amp;search=<?= urlencode($search) ?>&amp;show=<?= $showAllProducts ? 'top' : 'all' ?>#popularProducts">
                                    <?= $showAllProducts ? 'Show less' : 'View all' ?>
                                </a>
                            <?php else: ?>
                                <span>No items</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($visibleProducts === []): ?>
                            <p class="empty-state">No furniture available yet.</p>
                        <?php else: ?>
                            <div class="products-grid">
                                <?php foreach ($visibleProducts as $product): ?>
                                    <article class="product-card">
                                        <div class="product-link">
                                            <?php if (($product['image'] ?? '') !== ''): ?>
                                                <div class="product-media">
                                                    <button class="product-image-zoom-btn" type="button" data-zoom-image="<?= htmlspecialchars($product['image'], ENT_QUOTES, 'UTF-8') ?>" data-zoom-alt="<?= htmlspecialchars($product['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" aria-label="Zoom <?= htmlspecialchars($product['name'] ?? 'product image', ENT_QUOTES, 'UTF-8') ?>">
                                                        <img src="<?= htmlspecialchars($product['image'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($product['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                                    </button>
                                                </div>
                                            <?php else: ?>
                                                <div class="product-media placeholder">No Image</div>
                                            <?php endif; ?>
                                            <div class="product-body">
                                                <h4><?= htmlspecialchars($product['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></h4>
                                                <p class="product-price">P<?= number_format((float) ($product['price'] ?? 0), 2) ?></p>
                                                <div class="product-card-actions">
                                                    <a class="product-view-btn" href="user.php?view=home&amp;category=<?= urlencode($selectedCategory) ?>&amp;search=<?= urlencode($search) ?>&amp;show=<?= $showAllProducts ? 'all' : 'top' ?>&amp;product=<?= urlencode($product['id'] ?? '') ?>#popularProducts">View details</a>
                                                </div>
                                            </div>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>
            <?php endif; ?>

            <?php if ($selectedView === 'mix'): ?>
                <section class="mix-shell" data-mix-builder data-products='<?= htmlspecialchars(json_encode($mixProductsPayload), ENT_QUOTES, 'UTF-8') ?>' data-design='<?= htmlspecialchars(json_encode($selectedMixPayload), ENT_QUOTES, 'UTF-8') ?>'>
                    <div class="section-head">
                        <h3>Mix & Match</h3>
                        <a class="section-link mix-designs-button" href="user.php?view=designs"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"></rect><rect x="14" y="3" width="7" height="7" rx="1"></rect><rect x="3" y="14" width="7" height="7" rx="1"></rect><rect x="14" y="14" width="7" height="7" rx="1"></rect></svg>My Designs</a>
                    </div>

                    <div class="mix-page-tabs" role="tablist" aria-label="Mix and customization options">
                        <button class="mix-page-tab active" type="button" data-mix-page-tab="builder">Mix and Match</button>
                        <button class="mix-page-tab" type="button" data-mix-page-tab="customize">Customize Furniture</button>
                    </div>

                    <div class="mix-tab-panel" data-mix-page-panel="builder">
                        <?php if ($mixRooms !== []): ?>
                        <div class="room-tabs" role="list" aria-label="Room categories">
                            <?php foreach (array_keys($mixRooms) as $roomName): ?>
                                <a class="room-tab <?= $selectedMixRoom === $roomName ? 'active' : '' ?>" href="user.php?view=mix&amp;room=<?= urlencode($roomName) ?>"><?= htmlspecialchars($roomName, ENT_QUOTES, 'UTF-8') ?></a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                    <section class="mix-panel mix-picker-panel">
                        <div class="mix-panel-title">
                            <h3>Furniture</h3>
                            <span data-mix-category-count><?= count($mixProducts) ?> available</span>
                        </div>
                        <div class="mix-category-tabs" aria-label="Furniture categories">
                            <?php foreach (['Bed', 'Cabinet', 'Chair', 'Dining Set', 'Door', 'Sofa'] as $mixCategory): ?>
                                <button class="mix-category-tab <?= $mixCategory === 'Bed' ? 'active' : '' ?>" type="button" data-mix-category="<?= htmlspecialchars($mixCategory, ENT_QUOTES, 'UTF-8') ?>" aria-pressed="<?= $mixCategory === 'Bed' ? 'true' : 'false' ?>"><?= htmlspecialchars($mixCategory, ENT_QUOTES, 'UTF-8') ?></button>
                            <?php endforeach; ?>
                        </div>
                        <div class="mix-products">
                            <p class="empty-state mix-category-empty" data-mix-category-empty hidden></p>
                            <?php if ($mixProducts === []): ?>
                                <p class="empty-state">No matching furniture for this room yet.</p>
                            <?php else: ?>
                                <?php foreach ($mixProductsPayload as $product): ?>
                                    <article class="mix-product" data-mix-product-category="<?= htmlspecialchars((string) ($product['mix_category'] ?? 'Furniture'), ENT_QUOTES, 'UTF-8') ?>" <?= ($product['mix_category'] ?? '') === 'Bed' ? '' : 'hidden' ?>>
                                        <img src="<?= htmlspecialchars((string) ($product['image'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string) ($product['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                        <div>
                                            <strong><?= htmlspecialchars((string) ($product['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                                            <p><?= htmlspecialchars((string) ($product['category'] ?? ''), ENT_QUOTES, 'UTF-8') ?> &middot; P<?= number_format((float) ($product['price'] ?? 0), 2) ?></p>
                                            <div class="mix-product-meta">
                                                <span><?= htmlspecialchars((string) ($product['material'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                                <span><?= htmlspecialchars((string) ($product['color'] ?? 'Natural Brown'), ENT_QUOTES, 'UTF-8') ?></span>
                                                <span><?= (int) ($product['stock'] ?? 0) ?> stock</span>
                                            </div>
                                        </div>
                                        <button class="mix-add-btn" type="button" data-mix-add="<?= htmlspecialchars((string) ($product['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" <?= (int) ($product['stock'] ?? 0) < 1 ? 'disabled' : '' ?> aria-label="Add <?= htmlspecialchars((string) ($product['name'] ?? 'furniture'), ENT_QUOTES, 'UTF-8') ?>">
                                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14"></path><path d="M5 12h14"></path></svg>
                                            <span>Add</span>
                                        </button>
                                    </article>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="mix-panel mix-preview-panel">
                        <div class="mix-panel-title">
                            <h3><?= htmlspecialchars($selectedMixRoom, ENT_QUOTES, 'UTF-8') ?> Preview</h3>
                            <span>Editable room</span>
                        </div>
                        <div class="mix-board-tools" data-mix-selection-tools>
                            <button class="mix-mini-btn soft" type="button" data-mix-tool="move">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2v20"></path><path d="M2 12h20"></path><path d="m15 5-3-3-3 3"></path><path d="m15 19-3 3-3-3"></path><path d="m5 9-3 3 3 3"></path><path d="m19 9 3 3-3 3"></path></svg>
                                Move
                            </button>
                            <button class="mix-mini-btn soft" type="button" data-mix-tool="rotate_left">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7v6h6"></path><path d="M21 17a9 9 0 0 0-15-6.7L3 13"></path></svg>
                                Left
                            </button>
                            <button class="mix-mini-btn soft" type="button" data-mix-tool="rotate_right">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 7v6h-6"></path><path d="M3 17a9 9 0 0 1 15-6.7L21 13"></path></svg>
                                Right
                            </button>
                            <button class="mix-mini-btn soft" type="button" data-mix-tool="resize">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 3h6v6"></path><path d="M9 21H3v-6"></path><path d="M21 3 14 10"></path><path d="M3 21l7-7"></path></svg>
                                Resize
                            </button>
                            <button class="mix-mini-btn soft" type="button" data-mix-tool="forward">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 7 7h-4v11H9V10H5l7-7z"></path></svg>
                                Front
                            </button>
                            <button class="mix-mini-btn soft" type="button" data-mix-tool="backward">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 21-7-7h4V3h6v11h4l-7 7z"></path></svg>
                                Back
                            </button>
                            <button class="mix-mini-btn" type="button" data-mix-tool="delete">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18"></path><path d="M8 6V4h8v2"></path><path d="M6 6l1 15h10l1-15"></path></svg>
                                Delete
                            </button>
                        </div>
                        <div class="mix-board" data-mix-board>
                            <div class="mix-board-empty" data-mix-empty>Select furniture to build your room setup.</div>
                        </div>
                        <div class="mix-room-actions">
                            <button class="mix-mini-btn soft" type="button" data-mix-action="clear">Clear All</button>
                            <button class="mix-mini-btn soft" type="button" data-mix-action="undo">Undo</button>
                            <button class="mix-mini-btn soft" type="button" data-mix-action="redo">Redo</button>
                        </div>
                    </section>

                    <section class="mix-panel mix-selected-panel">
                        <div class="mix-panel-title">
                            <h3>Selected Furniture</h3>
                            <span data-mix-count>0 item</span>
                        </div>
                        <div class="mix-selected-list" data-mix-selected></div>
                        <p class="mix-warning" data-mix-warning></p>
                        <div class="mix-total">
                            <span><small>Items</small><strong data-mix-total-items>0</strong></span>
                            <span><small>Qty</small><strong data-mix-total-qty>0</strong></span>
                            <span><small>Total</small><strong data-mix-total>P0.00</strong></span>
                        </div>
                        <form class="mix-form" method="post" data-mix-save-form>
                            <input type="hidden" name="action" value="save_mix_design">
                            <input type="hidden" name="design_id" value="<?= htmlspecialchars((string) ($selectedMixDesign['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="room_type" value="<?= htmlspecialchars($selectedMixRoom, ENT_QUOTES, 'UTF-8') ?>" data-mix-room>
                            <input type="hidden" name="items_payload" value="" data-mix-payload>
                            <input type="text" name="design_name" value="<?= htmlspecialchars((string) ($selectedMixDesign['design_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Design name" required>
                            <div class="mix-actions">
                                <button class="mix-mini-btn" type="submit">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><path d="M17 21v-8H7v8"></path><path d="M7 3v5h8"></path></svg>
                                    Save Design
                                </button>
                                <button class="mix-mini-btn soft" type="submit" form="mixAddAllCartForm">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="20" r="1.5"></circle><circle cx="18" cy="20" r="1.5"></circle><path d="M3 4h2l2.2 10.2a1 1 0 0 0 1 .8h8.8a1 1 0 0 0 1-.8L20 7H7"></path></svg>
                                    Add All to Cart
                                </button>
                            </div>
                        </form>
                        <form id="mixAddAllCartForm" method="post" data-mix-cart-form>
                            <input type="hidden" name="action" value="add_mix_to_cart">
                            <input type="hidden" name="room_type" value="<?= htmlspecialchars($selectedMixRoom, ENT_QUOTES, 'UTF-8') ?>" data-mix-room>
                            <input type="hidden" name="items_payload" value="" data-mix-payload>
                        </form>
                    </section>
                    </div>

                    <section class="mix-tab-panel mix-panel" data-mix-page-panel="customize" hidden>
                        <div class="mix-panel-title">
                            <h3>Customize Furniture</h3>
                            <span>Request only</span>
                        </div>
                        <?php if ($customizationCategoryOptions === []): ?>
                            <p class="empty-state">No furniture categories are available yet.</p>
                        <?php else: ?>
                            <form class="customization-form" method="post" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="submit_customization_request">
                                <label>Categories
                                    <select name="customize_category" data-customize-category-select required>
                                        <?php foreach ($customizationCategoryOptions as $categoryName): ?>
                                            <option value="<?= htmlspecialchars($categoryName, ENT_QUOTES, 'UTF-8') ?>" <?= $categoryName === $requestedCustomizeCategory ? 'selected' : '' ?>><?= htmlspecialchars($categoryName, ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <div class="customization-grid">
                                    <label>Preferred size<input type="text" name="preferred_size" placeholder="Example: 6ft x 4ft" required></label>
                                    <label>Material<input type="text" name="material" placeholder="Enter material" required></label>
                                    <label>Quantity<input type="number" name="quantity" min="1" max="99" value="1" required></label>
                                </div>
                                <fieldset class="customization-colors">
                                    <legend>Colors (select one or more)</legend>
                                    <?php foreach (['White' => '#ffffff', 'Black' => '#212121', 'Gray' => '#9299a1', 'Brown' => '#754c32', 'Beige' => '#d4c2a3', 'Red' => '#ca4040', 'Orange' => '#eb8c35', 'Yellow' => '#eccb4e', 'Green' => '#52a269', 'Blue' => '#518bd1', 'Purple' => '#9364ac', 'Pink' => '#df8eac'] as $colorName => $colorHex): ?>
                                        <label class="color-choice"><input type="checkbox" name="colors[]" value="<?= htmlspecialchars($colorName, ENT_QUOTES, 'UTF-8') ?>"><span class="color-choice-swatch" style="--swatch-color:<?= $colorHex ?>" aria-hidden="true"></span><?= htmlspecialchars($colorName, ENT_QUOTES, 'UTF-8') ?></label>
                                    <?php endforeach; ?>
                                </fieldset>
                                <label>Other color or combination<input type="text" name="other_color" maxlength="60" placeholder="Optional"></label>
                                <label>Design photo (optional)<input type="file" name="reference_image" accept=".jpg,.jpeg,.jfif,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif"></label>
                                <button class="mix-mini-btn" type="submit">Submit Customization Request</button>
                            </form>
                        <?php endif; ?>
                    </section>
                </section>
            <?php endif; ?>

            <?php if ($selectedView === 'designs'): ?>
                <section class="stack-panel">
                    <div class="section-head">
                        <h3>My Designs</h3>
                        <a class="section-link" href="user.php?view=mix">Create New</a>
                    </div>
                    <?php if ($savedMixDesigns === []): ?>
                        <p class="empty-state">No saved Mix & Match designs yet.</p>
                    <?php else: ?>
                        <div class="design-list">
                            <?php foreach ($savedMixDesigns as $design): ?>
                                <article class="design-card">
                                    <div class="design-copy">
                                        <strong><?= htmlspecialchars((string) ($design['design_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                                        <p><?= htmlspecialchars((string) ($design['room_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?> &middot; <?= count($design['items'] ?? []) ?> items</p>
                                        <p>Total: P<?= number_format((float) ($design['total_price'] ?? 0), 2) ?></p>
                                    </div>
                                    <div class="design-actions">
                                        <a class="mix-mini-btn" href="user.php?view=mix&amp;design=<?= urlencode((string) ($design['id'] ?? '')) ?>">Edit</a>
                                        <a class="mix-mini-btn soft" href="user.php?view=home">Shop</a>
                                        <form method="post" onsubmit="return confirm('Delete this design?');">
                                            <input type="hidden" name="action" value="delete_mix_design">
                                            <input type="hidden" name="design_id" value="<?= htmlspecialchars((string) ($design['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                            <button class="mix-mini-btn soft" type="submit">Delete</button>
                                        </form>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($selectedView === 'cart'): ?>
                <section class="stack-panel">
                    <div class="section-head">
                        <h3>Add to Cart</h3>
                        <span>P<?= number_format($cartTotal, 2) ?></span>
                    </div>
                    <?php if ($cartItems === []): ?>
                        <p class="empty-state">No items in your cart yet.</p>
                    <?php else: ?>
                        <?php foreach ($cartItems as $item): ?>
                            <article class="order-card">
                                <div class="order-top">
                                    <div>
                                        <strong><?= htmlspecialchars($item['product_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong>
                                        <p>Qty <?= htmlspecialchars((string) ($item['quantity'] ?? 1), ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                    <span class="status-pill pending">Cart</span>
                                </div>
                                <p>Total: P<?= number_format(((float) ($item['price'] ?? 0)) * (int) ($item['quantity'] ?? 1), 2) ?></p>
                                <div class="detail-actions">
                                    <form method="post" onsubmit="return confirm('Delete this item from your cart?');">
                                        <input type="hidden" name="action" value="remove_cart_item">
                                        <input type="hidden" name="cart_id" value="<?= htmlspecialchars($item['id'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                        <button class="message-btn" type="submit">Delete</button>
                                    </form>
                                    <form method="post">
                                        <input type="hidden" name="action" value="place_cart_order">
                                        <input type="hidden" name="cart_id" value="<?= htmlspecialchars($item['id'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                        <button class="mini-btn" type="submit" <?= !$canPlaceOrder ? 'disabled' : '' ?>>Place Order</button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($selectedView === 'orders'): ?>
                <section class="stack-panel">
                    <div class="section-head">
                        <h3>My Orders</h3>
                        <span><?= count($orders) + count($customerCustomizationRequests) ?> total</span>
                    </div>
                    <div class="order-kind-tabs" aria-label="Order type">
                        <a class="<?= $selectedOrderType === 'normal' ? 'active' : '' ?>" href="user.php?view=orders&order_type=normal">Normal Orders (<?= count($orders) ?>)</a>
                        <a class="<?= $selectedOrderType === 'customization' ? 'active' : '' ?>" href="user.php?view=orders&order_type=customization">Customization Orders (<?= count($customerCustomizationRequests) ?>)<?= $userUnreadCustomizationCount > 0 ? ' · ' . $userUnreadCustomizationCount . ' new' : '' ?></a>
                    </div>
                    <?php if ($selectedOrderId !== ''): ?>
                        <a class="orders-back-link" href="user.php?view=orders&amp;order_type=<?= urlencode($selectedOrderType) ?>">&larr; Back to Orders</a>
                    <?php endif; ?>
                    <?php if (($selectedOrderType === 'normal' && $orders === []) || ($selectedOrderType === 'customization' && $customerCustomizationRequests === [])): ?>
                        <p class="empty-state">No orders yet.</p>
                    <?php else: ?>
                        <?php if ($selectedOrderType === 'customization'): ?>
                        <?php foreach ($displayCustomizationRequests as $request): ?>
                            <details class="customization-request-card order-disclosure" name="user-orders" data-order-id="<?= htmlspecialchars((string) ($request['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-order-type="customization" <?= (string) ($request['id'] ?? '') === $selectedOrderId ? 'open' : '' ?>>
                                <?php $customImage = trim((string) ($request['product_image'] ?? '')) !== '' ? trim((string) $request['product_image']) : trim((string) ($request['reference_image'] ?? '')); ?>
                                <?php $awaitingPayment = ($request['status'] ?? '') === 'Down Payment Paid' && empty($request['payment_confirmed']); ?>
                                <summary class="order-disclosure-summary">
                                    <?php if ($customImage !== ''): ?><img class="order-summary-image" src="<?= htmlspecialchars($customImage, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string) ($request['product_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
                                    <span class="order-summary-main"><strong><?= htmlspecialchars((string) ($request['product_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong><small>Qty <?= (int) ($request['quantity'] ?? 1) ?> · Tap to view details</small></span>
                                    <span class="status-pill <?= htmlspecialchars($awaitingPayment ? 'processing' : (string) ($request['tone'] ?? customizationTone((string) ($request['status'] ?? 'Pending'))), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($awaitingPayment ? 'Payment pending confirmation' : (string) ($request['status'] ?? 'Pending'), ENT_QUOTES, 'UTF-8') ?></span>
                                    <button type="button" class="message-icon-btn" data-toggle-target="customization-chat-<?= htmlspecialchars((string) ($request['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" aria-label="Toggle messages" title="Messages">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M21 11.5a8.5 8.5 0 0 1-8.5 8.5A8.4 8.4 0 0 1 8 18.7L3 20l1.3-5A8.4 8.4 0 0 1 3 11.5 8.5 8.5 0 0 1 11.5 3h1A8.5 8.5 0 0 1 21 11.5Z"></path></svg>
                                        <?php if (count($requestMessages) > 0): ?><span class="message-badge"><?= count($requestMessages) ?></span><?php endif; ?>
                                    </button>
                                </summary>
                                <div class="order-disclosure-body">
                                <div class="customization-request-head no-image">
                                    <div>
                                        <p><?= htmlspecialchars((string) ($request['preferred_size'] ?? ''), ENT_QUOTES, 'UTF-8') ?> &middot; <?= htmlspecialchars((string) ($request['color'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                                        <p><?= htmlspecialchars((string) ($request['material'] ?? ''), ENT_QUOTES, 'UTF-8') ?> &middot; Qty <?= (int) ($request['quantity'] ?? 1) ?></p>
                                    </div>
                                </div>
                                <?php if ((float) ($request['quotation_price'] ?? 0) > 0): ?>
                                    <?php $requestPayment = appCustomizationPaymentSummary($request); ?>
                                    <p>Total P<?= number_format($requestPayment['total'], 2) ?> &middot; Down payment P<?= number_format($requestPayment['down_payment'], 2) ?> &middot; Received P<?= number_format($requestPayment['received'], 2) ?> &middot; Balance P<?= number_format($requestPayment['balance'], 2) ?></p>
                                    <?php if ($requestPayment['full_payment_confirmed'] || (!empty($request['payment_confirmed']) && $requestPayment['balance'] <= 0)): ?><p class="status-pill accepted">Full Payment Received</p><?php endif; ?>
                                <?php endif; ?>
                                <?php if (trim((string) ($request['admin_notes'] ?? '')) !== ''): ?>
                                    <p><?= htmlspecialchars((string) ($request['admin_notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                                <?php endif; ?>
                                <?php if (trim((string) ($request['reference_image'] ?? '')) !== ''): ?>
                                    <button class="message-btn" type="button" data-zoom-image="<?= htmlspecialchars((string) ($request['reference_image'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-zoom-alt="Reference Image">View Reference Image / Zoom</button>
                                <?php endif; ?>
                                <?php
                                    $paymentConfirmed = !empty($request['payment_confirmed']);
                                    $progressStep = appCustomizationProgressStep((string) ($request['status'] ?? 'Pending'), $paymentConfirmed);
                                ?>
                                <ol class="customization-progress" aria-label="Customization progress">
                                    <?php foreach (['Request sent', 'Quotation sent', 'Accepted', 'Payment received', 'Ongoing', 'Ready', 'Finished'] as $stepIndex => $stepLabel): ?>
                                        <li class="<?= $progressStep >= 0 && $stepIndex <= $progressStep ? 'is-done' : '' ?>"><?= htmlspecialchars($stepLabel, ENT_QUOTES, 'UTF-8') ?></li>
                                    <?php endforeach; ?>
                                </ol>
                                <?php if ((string) ($request['status'] ?? '') === 'Quotation Sent'): ?>
                                    <div class="detail-actions">
                                        <form method="post">
                                            <input type="hidden" name="action" value="customer_customization_decision">
                                            <input type="hidden" name="request_id" value="<?= htmlspecialchars((string) ($request['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="decision" value="approve">
                                            <button class="mini-btn" type="submit">Accept Quotation</button>
                                        </form>
                                        <form method="post" onsubmit="return confirm('Cancel this quotation?');">
                                            <input type="hidden" name="action" value="customer_customization_decision">
                                            <input type="hidden" name="request_id" value="<?= htmlspecialchars((string) ($request['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="decision" value="reject">
                                            <button class="message-btn" type="submit">Cancel</button>
                                        </form>
                                    </div>
                                <?php elseif ((string) ($request['status'] ?? '') === 'Approved'): ?>
                                    <p><strong>Pay P<?= number_format((float) ($request['down_payment'] ?? 0), 2) ?> before work begins.</strong></p>
                                    <form class="customization-form" method="post" enctype="multipart/form-data">
                                        <input type="hidden" name="action" value="upload_customization_payment">
                                        <input type="hidden" name="request_id" value="<?= htmlspecialchars((string) ($request['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                        <label>Proof of down payment<input type="file" name="payment_proof" accept=".jpg,.jpeg,.jfif,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif" required></label>
                                        <button class="mini-btn" type="submit">Upload Payment Proof</button>
                                    </form>
                                <?php elseif ((string) ($request['status'] ?? '') === 'Down Payment Paid'): ?>
                                    <p><?= $paymentConfirmed ? 'Payment received and confirmed by admin.' : 'Payment proof submitted. Awaiting admin confirmation.' ?></p>
                                <?php endif; ?>
                                <?php $requestMessages = array_reverse(array_values(array_filter($store['messages'] ?? [], function ($message) use ($request, $currentUser) {
                                    return appMessageCustomizationId($message) === (string) ($request['id'] ?? '') && (strcasecmp((string) ($message['from_email'] ?? ''), (string) ($currentUser['email'] ?? '')) === 0 || strcasecmp((string) ($message['to'] ?? ''), (string) ($currentUser['email'] ?? '')) === 0);
                                }))); ?>
                                <details class="customization-chat" id="customization-chat-<?= htmlspecialchars((string) ($request['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                    <summary>Customization Messages (<?= count($requestMessages) ?>)</summary>
                                    <?php foreach ($requestMessages as $requestMessage): ?>
                                        <article class="chat-bubble <?= strcasecmp((string) ($requestMessage['from_email'] ?? ''), (string) ($currentUser['email'] ?? '')) === 0 ? 'outgoing' : 'incoming' ?>">
                                            <?php if (trim((string) ($requestMessage['image_path'] ?? '')) !== ''): ?><a class="chat-image-link" href="<?= htmlspecialchars((string) $requestMessage['image_path'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><img class="chat-image" src="<?= htmlspecialchars((string) $requestMessage['image_path'], ENT_QUOTES, 'UTF-8') ?>" alt="Customization attachment"></a><?php endif; ?>
                                            <?php if (appDisplayChatMessage((string) ($requestMessage['message'] ?? '')) !== ''): ?><p><?= nl2br(htmlspecialchars(appDisplayChatMessage((string) $requestMessage['message']), ENT_QUOTES, 'UTF-8')) ?></p><?php endif; ?>
                                        </article>
                                    <?php endforeach; ?>
                                    <form class="customization-form" method="post" enctype="multipart/form-data">
                                        <input type="hidden" name="action" value="contact_admin">
                                        <input type="hidden" name="customization_id" value="<?= htmlspecialchars((string) ($request['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="product_id" value="<?= htmlspecialchars((string) ($request['product_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                        <label>Message to admin<textarea name="message" placeholder="Ask about this customization..."></textarea></label>
                                        <label>Attach image<input type="file" name="message_image" accept="image/*"></label>
                                        <button class="mini-btn" type="submit">Send Message</button>
                                    </form>
                                </details>
                                <?php if ((string) ($request['status'] ?? '') === 'Pending'): ?>
                                    <form method="post" onsubmit="return confirm('Cancel this customization request?');">
                                        <input type="hidden" name="action" value="cancel_customization_request">
                                        <input type="hidden" name="request_id" value="<?= htmlspecialchars((string) ($request['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                        <button class="message-btn" type="submit">Cancel Request</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" onsubmit="return confirm('Delete this customization request? It will be moved to the archive.');">
                                    <input type="hidden" name="action" value="delete_customization_request">
                                    <input type="hidden" name="request_id" value="<?= htmlspecialchars((string) ($request['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                    <button class="message-btn" type="submit">Delete</button>
                                </form>
                                </div>
                            </details>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <?php foreach ($displayOrders as $order): ?>
                            <?php $orderImage = appOrderImagePath($order, $store['products'] ?? [], $store['deleted_records'] ?? []); ?>
                            <details class="order-card order-disclosure" name="user-orders" data-order-id="<?= htmlspecialchars((string) ($order['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-order-type="normal" <?= (string) ($order['id'] ?? '') === $selectedOrderId ? 'open' : '' ?>>
                                <summary class="order-disclosure-summary">
                                    <?php if ($orderImage !== ''): ?><img class="order-summary-image" src="<?= htmlspecialchars($orderImage, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string) ($order['product_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
                                    <span class="order-summary-main"><strong><?= htmlspecialchars((string) ($order['product_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong><small>Qty <?= (int) ($order['quantity'] ?? 1) ?> · Tap to view details</small></span>
                                    <span class="status-pill <?= htmlspecialchars($order['tone'] ?? 'pending', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($order['status'] ?? 'New Order', ENT_QUOTES, 'UTF-8') ?></span>
                                </summary>
                                <div class="order-disclosure-body">
                                <p>Order ID: <?= htmlspecialchars((string) ($order['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                                <p>Date: <?= htmlspecialchars($order['date'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
                                <?php $normalOrderStage = ['New Order' => 0, 'Pending' => 0, 'Accepted' => 1, 'Payment Received' => 1, 'Complete' => 2][(string) ($order['status'] ?? 'New Order')] ?? 0; ?>
                                <div class="normal-order-steps" aria-label="Order progress">
                                    <?php foreach (['Order Placed', 'Accepted', 'Complete'] as $stepIndex => $stepLabel): ?>
                                        <span class="normal-order-step <?= ($order['status'] ?? '') === 'Declined' ? ($stepIndex === 0 ? 'done' : '') : ($stepIndex <= $normalOrderStage ? 'done' : '') ?>"><?= htmlspecialchars($stepLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <p>TN: <?= htmlspecialchars((string) (($order['customer_phone'] ?? '') !== '' ? $order['customer_phone'] : ($currentUserRecord['phone'] ?? 'Not provided')), ENT_QUOTES, 'UTF-8') ?></p>
                                <?php $orderStatus = (string) ($order['status'] ?? 'New Order'); ?>
                                <?php if (in_array($orderStatus, ['New Order', 'Pending', 'Accepted', 'Payment Received'], true)): ?>
                                    <div class="detail-actions" style="margin-top:8px;">
                                        <?php if (in_array($orderStatus, ['Accepted', 'Payment Received'], true) && trim((string) ($order['product_id'] ?? '')) !== ''): ?>
                                            <a class="message-btn" href="user.php?view=messages&product=<?= urlencode((string) ($order['product_id'] ?? '')) ?>">Message Admin</a>
                                        <?php endif; ?>
                                        <?php if ($orderStatus !== 'Payment Received'): ?>
                                            <form method="post" onsubmit="return confirm('Cancel this order?');">
                                                <input type="hidden" name="action" value="cancel_order">
                                                <input type="hidden" name="order_id" value="<?= htmlspecialchars((string) ($order['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                                <button class="message-btn" type="submit">Cancel Order</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="post" onsubmit="return confirm('Delete this order? It will be moved to the archive.');">
                                            <input type="hidden" name="action" value="delete_user_order">
                                            <input type="hidden" name="order_id" value="<?= htmlspecialchars((string) ($order['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                            <button class="message-btn" type="submit">Delete</button>
                                        </form>
                                    </div>
                                <?php elseif (in_array($orderStatus, ['Complete', 'Declined'], true)): ?>
                                    <div class="detail-actions" style="margin-top:8px;">
                                        <form method="post" onsubmit="return confirm('Delete this order?');">
                                            <input type="hidden" name="action" value="delete_user_order">
                                            <input type="hidden" name="order_id" value="<?= htmlspecialchars((string) ($order['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                            <button class="message-btn" type="submit">Delete</button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                                <p>Quantity: <?= htmlspecialchars((string) ($order['quantity'] ?? 1), ENT_QUOTES, 'UTF-8') ?> &middot; Total: P<?= number_format((float) ($order['total'] ?? 0), 2) ?></p>
                                </div>
                            </details>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($selectedView === 'messages'): ?>
                <section class="chat-shell">
                    <div class="chat-header">
                        <div class="chat-avatar">
                            <?php if ($chatAdminImage !== ''): ?>
                                <img src="<?= htmlspecialchars($chatAdminImage, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($chatAdminName, ENT_QUOTES, 'UTF-8') ?>">
                            <?php else: ?>
                                <?= htmlspecialchars($chatAdminInitial, ENT_QUOTES, 'UTF-8') ?>
                            <?php endif; ?>
                        </div>
                        <div class="chat-title">
                            <strong><?= htmlspecialchars($chatAdminName, ENT_QUOTES, 'UTF-8') ?></strong>
                            <span><?= htmlspecialchars($chatAdminSubtitle, ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="chat-header-actions">
                            <?php if ($messageThread !== []): ?>
                                <form method="post" onsubmit="return confirm('Move this chat to Deleted Chats?');">
                                    <input type="hidden" name="action" value="delete_user_chat_thread">
                                    <button class="chat-head-btn danger" type="submit" aria-label="Delete chat">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                                    </button>
                                </form>
                            <?php endif; ?>
                            <?php if ($deletedUserMessages !== []): ?>
                                <form method="post">
                                    <input type="hidden" name="action" value="restore_user_chat_thread">
                                    <button class="chat-head-btn" type="submit" aria-label="Restore deleted chat">Restore</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($activeMessageProduct !== null): ?>
                        <div class="chat-context">
                            <div class="chat-context-media">
                                <?php if (($activeMessageProduct['image'] ?? '') !== ''): ?>
                                    <img src="<?= htmlspecialchars($activeMessageProduct['image'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($activeMessageProduct['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                <?php endif; ?>
                            </div>
                            <div class="chat-context-body">
                                <span class="chat-context-label">Chatting about</span>
                                <strong><?= htmlspecialchars((string) ($activeMessageProduct['name'] ?? 'Furniture item'), ENT_QUOTES, 'UTF-8') ?></strong>
                                <span>Send your message here just like a full Messenger thread.</span>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="chat-thread">
                        <?php if ($messageThread !== []): ?>
                            <?php
                                $latestQuoteMessageIds = [];
                                foreach ($messageThread as $threadItem) {
                                    if (!str_starts_with((string) ($threadItem['message'] ?? ''), 'Quotation sent for customization:')) {
                                        continue;
                                    }
                                    $threadRequestId = '';
                                    foreach ($customerCustomizationRequests as $candidateRequest) {
                                        $candidateId = (string) ($candidateRequest['id'] ?? '');
                                        if ($candidateId !== '' && str_contains((string) ($threadItem['message'] ?? ''), '(' . $candidateId . ')')) {
                                            $threadRequestId = $candidateId;
                                            break;
                                        }
                                    }
                                    if ($threadRequestId !== '' && (!isset($latestQuoteMessageIds[$threadRequestId])
                                        || strcmp((string) ($threadItem['id'] ?? ''), $latestQuoteMessageIds[$threadRequestId]) > 0)) {
                                        $latestQuoteMessageIds[$threadRequestId] = (string) ($threadItem['id'] ?? '');
                                    }
                                }
                            ?>
                            <?php foreach (array_reverse($messageThread) as $messageItem): ?>
                                <?php
                                    $isOutgoing = strcasecmp((string) ($messageItem['from_email'] ?? ''), (string) ($currentUser['email'] ?? '')) === 0;
                                    [$messageProduct] = findProductById($products, (string) ($messageItem['product_id'] ?? ''));
                                    $messageProductName = trim((string) ($messageProduct['name'] ?? ''));
                                    $messageImagePath = trim((string) ($messageItem['image_path'] ?? ''));
                                    $quoteRequest = null;
                                    if (!$isOutgoing && str_starts_with((string) ($messageItem['message'] ?? ''), 'Quotation sent for customization:')) {
                                        foreach ($customerCustomizationRequests as $candidateRequest) {
                                            $candidateId = (string) ($candidateRequest['id'] ?? '');
                                            if ($candidateId !== '' && (string) ($candidateRequest['status'] ?? '') === 'Quotation Sent'
                                                && ($latestQuoteMessageIds[$candidateId] ?? '') === (string) ($messageItem['id'] ?? '')
                                                && str_contains((string) ($messageItem['message'] ?? ''), '(' . $candidateId . ')')) {
                                                $quoteRequest = $candidateRequest;
                                                break;
                                            }
                                        }
                                    }
                                ?>
                                <article class="chat-bubble <?= $isOutgoing ? 'outgoing' : 'incoming' ?>">
                                    <?php if ($messageImagePath !== ''): ?>
                                        <a class="chat-image-link" href="<?= htmlspecialchars($messageImagePath, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">
                                            <img class="chat-image" src="<?= htmlspecialchars($messageImagePath, ENT_QUOTES, 'UTF-8') ?>" alt="Chat attachment">
                                        </a>
                                    <?php endif; ?>
                                    <?php if (trim((string) ($messageItem['message'] ?? '')) !== ''): ?>
                                        <p><?= nl2br(htmlspecialchars(appDisplayChatMessage((string) ($messageItem['message'] ?? '')), ENT_QUOTES, 'UTF-8')) ?></p>
                                    <?php endif; ?>
                                    <?php if ($messageProductName !== ''): ?>
                                        <span class="chat-product-tag">About: <?= htmlspecialchars($messageProductName, ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                    <?php if ($quoteRequest !== null): ?>
                                        <div class="chat-quote-actions">
                                            <p>Down payment before work: P<?= number_format((float) ($quoteRequest['down_payment'] ?? 0), 2) ?></p>
                                            <form method="post">
                                                <input type="hidden" name="action" value="customer_customization_decision">
                                                <input type="hidden" name="request_id" value="<?= htmlspecialchars((string) ($quoteRequest['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="decision" value="approve">
                                                <button class="mini-btn" type="submit">Accept</button>
                                            </form>
                                            <form method="post" onsubmit="return confirm('Cancel this quotation?');">
                                                <input type="hidden" name="action" value="customer_customization_decision">
                                                <input type="hidden" name="request_id" value="<?= htmlspecialchars((string) ($quoteRequest['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="decision" value="reject">
                                                <button class="message-btn" type="submit">Cancel</button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                    <div class="chat-meta">
                                        <span><?= $isOutgoing ? 'You' : htmlspecialchars((string) ($messageItem['from_name'] ?? 'Admin'), ENT_QUOTES, 'UTF-8') ?></span>
                                        <span><?= htmlspecialchars(formatChatTimestamp((string) ($messageItem['created_at'] ?? '')), ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <form class="chat-composer" id="userChatComposer" method="post" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="contact_admin">
                        <input type="hidden" name="product_id" value="<?= htmlspecialchars((string) ($activeMessageProduct['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="redirect_view" value="messages">
                        <div class="chat-composer-field">
                            <textarea name="message" placeholder="<?= $activeMessageProduct !== null ? 'Write your message about this furniture...' : 'Write a message to admin...' ?>"></textarea>
                            <input class="chat-file-input" id="userMessageImage" type="file" name="message_image" accept="image/*">
                            <button class="chat-attach-btn" id="userMessageImageTrigger" type="button" aria-label="Attach image">+</button>
                        </div>
                        <div class="chat-upload-preview" id="userMessageImagePreviewCard">
                            <img id="userMessageImagePreview" src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==" alt="Selected image preview">
                            <div class="chat-upload-preview-text">
                                <strong>Image preview</strong>
                                <p id="userMessageImagePreviewMeta">Choose an image to preview before sending.</p>
                            </div>
                        </div>
                        <div class="chat-composer-actions">
                            <span class="chat-composer-tip" id="userMessageImageStatus">Send text, image, or both.</span>
                            <button class="chat-send-btn" type="submit">Send</button>
                        </div>
                    </form>
                    <div class="message-send-preview-modal" id="userMessageSendPreviewModal" aria-hidden="true">
                        <div class="message-send-preview-card" role="dialog" aria-modal="true" aria-labelledby="userMessageSendPreviewTitle">
                            <h4 id="userMessageSendPreviewTitle">Preview image before send</h4>
                            <p>Check the selected image first. Tap send here to continue.</p>
                            <img id="userMessageSendPreviewImage" src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==" alt="Message image preview">
                            <div class="message-send-preview-meta" id="userMessageSendPreviewMeta">No image selected.</div>
                            <div class="message-send-preview-actions">
                                <button class="message-send-preview-cancel" id="userMessageSendPreviewCancel" type="button">Cancel</button>
                                <button class="message-send-preview-confirm" id="userMessageSendPreviewConfirm" type="button">Send</button>
                            </div>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($selectedView === 'wishlist'): ?>
                <section class="stack-panel">
                    <div class="section-head">
                        <h3>Wishlist</h3>
                        <span><?= count($wishlistItems) ?> saved</span>
                    </div>
                    <?php if ($wishlistItems === []): ?>
                        <p class="empty-state">No wishlist items yet. Open a product and tap Wishlist.</p>
                    <?php else: ?>
                        <?php foreach ($wishlistItems as $item): ?>
                            <article class="order-card">
                                <div class="order-top">
                                    <div>
                                        <strong><?= htmlspecialchars($item['product_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong>
                                        <p>P<?= number_format((float) ($item['price'] ?? 0), 2) ?></p>
                                    </div>
                                    <span class="status-pill completed">Saved</span>
                                </div>
                                <div class="detail-actions">
                                    <form method="post">
                                        <input type="hidden" name="action" value="remove_wishlist_item">
                                        <input type="hidden" name="wishlist_id" value="<?= htmlspecialchars($item['id'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                        <button class="message-btn" type="submit">Remove</button>
                                    </form>
                                    <form method="post">
                                        <input type="hidden" name="action" value="wishlist_to_cart">
                                        <input type="hidden" name="wishlist_id" value="<?= htmlspecialchars($item['id'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                        <button class="mini-btn" type="submit">Add to Cart</button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($selectedView === 'profile'): ?>
                <section class="profile-shell">
                    <article class="profile-hero">
                        <div class="profile-hero-top">
                            <?php if ($profileImage !== ''): ?>
                                <button class="profile-avatar is-clickable" type="button" data-open-modal="profileImageModal" aria-label="Open profile picture">
                                    <img src="<?= htmlspecialchars($profileImage, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($profileName, ENT_QUOTES, 'UTF-8') ?>">
                                </button>
                            <?php else: ?>
                                <div class="profile-avatar" aria-hidden="true">
                                    <span><?= htmlspecialchars($profileInitial, ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            <?php endif; ?>
                            <div class="profile-summary">
                                <h2><?= htmlspecialchars($profileName, ENT_QUOTES, 'UTF-8') ?></h2>
                                <span class="member-badge">
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"></path>
                                        <circle cx="9.5" cy="7" r="4"></circle>
                                        <path d="M19 8v6"></path>
                                        <path d="M22 11h-6"></path>
                                    </svg>
                                    <?= htmlspecialchars($profileRole, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                                <p class="profile-email"><?= htmlspecialchars($profileEmail, ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                        </div>
                        <button class="profile-edit-btn" type="button" id="openProfileEdit" data-open-modal="profileEditModal">
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M12 20h9"></path>
                                <path d="M16.5 3.5a2.1 2.1 0 1 1 3 3L7 19l-4 1 1-4 12.5-12.5Z"></path>
                            </svg>
                            <span>Edit Profile</span>
                        </button>
                    </article>

                    <section>
                        <h3 class="profile-section-title">Account Information</h3>
                        <div class="profile-list">
                            <button class="profile-row" type="button" data-open-modal="profileEditModal">
                                <div class="profile-row-icon">
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M20 21a8 8 0 0 0-16 0"></path>
                                        <circle cx="12" cy="8" r="4"></circle>
                                    </svg>
                                </div>
                                <div class="profile-row-copy">
                                    <p class="profile-row-label">Full Name</p>
                                    <p class="profile-row-value"><?= htmlspecialchars($profileName, ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                                <span class="profile-chevron">›</span>
                            </button>
                            <button class="profile-row" type="button" data-open-modal="profileEditModal">
                                <div class="profile-row-icon">
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M4 5h16v14H4z"></path>
                                        <path d="m4 7 8 6 8-6"></path>
                                    </svg>
                                </div>
                                <div class="profile-row-copy">
                                    <p class="profile-row-label">Email Address</p>
                                    <p class="profile-row-value"><?= htmlspecialchars($profileEmail, ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                                <span class="profile-chevron">›</span>
                            </button>
                            <button class="profile-row" type="button" data-open-modal="profileEditModal">
                                <div class="profile-row-icon">
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.86 19.86 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.86 19.86 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72l.36 2.57a2 2 0 0 1-.57 1.69L7.1 9.79a16 16 0 0 0 7.11 7.11l1.81-1.8a2 2 0 0 1 1.69-.57l2.57.36A2 2 0 0 1 22 16.92Z"></path>
                                    </svg>
                                </div>
                                <div class="profile-row-copy">
                                    <p class="profile-row-label">Phone Number</p>
                                    <p class="profile-row-value"><?= htmlspecialchars($profilePhone, ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                                <span class="profile-chevron">›</span>
                            </button>
                            <button class="profile-row" type="button" data-open-modal="profileEditModal">
                                <div class="profile-row-icon">
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M21 10c0 6-9 12-9 12S3 16 3 10a9 9 0 1 1 18 0Z"></path>
                                        <circle cx="12" cy="10" r="3"></circle>
                                    </svg>
                                </div>
                                <div class="profile-row-copy">
                                    <p class="profile-row-label">Address</p>
                                    <p class="profile-row-value"><?= htmlspecialchars($profileAddress, ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                                <span class="profile-chevron">›</span>
                            </button>
                        </div>
                    </section>

                    <section>
                        <h3 class="profile-section-title">Account Settings</h3>
                        <div class="profile-settings-list">
                            <button class="profile-settings-row" type="button" data-open-modal="passwordModal">
                                <div class="profile-settings-icon">
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <rect x="4" y="11" width="16" height="10" rx="2"></rect>
                                        <path d="M8 11V8a4 4 0 1 1 8 0v3"></path>
                                    </svg>
                                </div>
                                <div class="profile-settings-copy">
                                    <strong>Change Password</strong>
                                    <p>Update your password to keep your account secure.</p>
                                </div>
                                <span class="profile-chevron">›</span>
                            </button>
                            <button class="profile-settings-row" type="button" data-open-modal="profileEditModal">
                                <div class="profile-settings-icon">
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M21 10c0 6-9 12-9 12S3 16 3 10a9 9 0 1 1 18 0Z"></path>
                                        <circle cx="12" cy="10" r="3"></circle>
                                    </svg>
                                </div>
                                <div class="profile-settings-copy">
                                    <strong>Manage Address</strong>
                                    <p>View and manage your delivery addresses.</p>
                                </div>
                                <span class="profile-chevron">›</span>
                            </button>
                            <button class="profile-settings-row" type="button" data-open-modal="notificationModal">
                                <div class="profile-settings-icon">
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M18 8a6 6 0 1 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path>
                                        <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                                    </svg>
                                </div>
                                <div class="profile-settings-copy">
                                    <strong>Notification Settings</strong>
                                    <p>Choose what notifications you want to receive.</p>
                                </div>
                                <span class="profile-chevron">›</span>
                            </button>
                            <div class="language-setting">
                                <strong>Language</strong>
                                <div class="language-choices" role="group" aria-label="Language">
                                    <button type="button" data-language-choice="en" aria-pressed="true">English</button>
                                    <button type="button" data-language-choice="tl" aria-pressed="false">Tagalog</button>
                                </div>
                            </div>
                            <a class="profile-settings-row" href="user.php?view=wishlist">
                                <div class="profile-settings-icon">
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="m12 21-1.45-1.32C5.4 15.05 2 11.97 2 8.5A4.5 4.5 0 0 1 6.5 4C8.24 4 9.91 4.81 11 6.08 12.09 4.81 13.76 4 15.5 4A4.5 4.5 0 0 1 20 8.5c0 3.47-3.4 6.55-8.55 11.18Z"></path>
                                    </svg>
                                </div>
                                <div class="profile-settings-copy">
                                    <strong>Wishlist</strong>
                                    <p>View and manage your favorite items.</p>
                                </div>
                                <span class="profile-chevron">›</span>
                            </a>
                            <a class="profile-settings-row logout-row" href="user.php?logout=1">
                                <div class="profile-settings-icon logout-tone">
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M10 17l5-5-5-5"></path>
                                        <path d="M15 12H3"></path>
                                        <path d="M10 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h5"></path>
                                        <path d="M14 4h5a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-5"></path>
                                    </svg>
                                </div>
                                <div class="profile-settings-copy">
                                    <strong>Logout</strong>
                                    <p>Sign out from your account.</p>
                                </div>
                                <span class="profile-chevron">›</span>
                            </a>
                        </div>
                    </section>

                </section>
            <?php endif; ?>
        </main>

        <?php if ($selectedView === 'profile'): ?>
            <div class="modal-overlay profile-modal <?= $showProfileEditModal ? 'is-open' : '' ?>" id="profileEditModal">
                <section class="profile-modal-card" aria-label="Edit profile popup">
                    <button class="profile-modal-close" type="button" data-close-modal="profileEditModal" aria-label="Close edit profile">×</button>
                    <h3 class="profile-section-title">Edit Profile</h3>
                    <form class="profile-form" method="post" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="update_profile">
                        <div>
                            <label for="profileModalName">Full Name</label>
                            <input id="profileModalName" type="text" name="profile_name" value="<?= htmlspecialchars($profileName, ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div>
                            <label for="profileModalEmail">Email Address</label>
                            <input id="profileModalEmail" type="email" name="profile_email" value="<?= htmlspecialchars($profileEmail, ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div>
                            <label for="profileModalPhone">Phone Number</label>
                            <input id="profileModalPhone" type="text" name="profile_phone" value="<?= htmlspecialchars($profilePhone === 'Not provided' ? '' : $profilePhone, ENT_QUOTES, 'UTF-8') ?>" placeholder="Enter phone number">
                        </div>
                        <div>
                            <label for="profileModalAddress">Address</label>
                            <textarea id="profileModalAddress" name="profile_address" placeholder="Enter address"><?= htmlspecialchars($profileAddress === 'Not provided' ? '' : $profileAddress, ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>
                        <div>
                            <label for="profileModalImage">Profile Picture</label>
                            <input id="profileModalImage" type="file" name="profile_image" accept=".jpg,.jpeg,.jfif,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif">
                        </div>
                        <button class="primary-btn" type="submit">Save Profile</button>
                    </form>
                </section>
            </div>

            <?php if ($profileImage !== ''): ?>
                <div class="modal-overlay profile-modal" id="profileImageModal">
                    <section class="profile-image-viewer" aria-label="Profile image viewer">
                        <img src="<?= htmlspecialchars($profileImage, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($profileName, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="profile-image-viewer-copy">
                            <strong><?= htmlspecialchars($profileName, ENT_QUOTES, 'UTF-8') ?></strong>
                            <p>Tap outside this image to close.</p>
                        </div>
                    </section>
                </div>
            <?php endif; ?>

            <div class="modal-overlay profile-modal <?= $showPasswordModal ? 'is-open' : '' ?>" id="passwordModal">
                <section class="profile-modal-card" aria-label="Change password popup">
                    <button class="profile-modal-close" type="button" data-close-modal="passwordModal" aria-label="Close change password">×</button>
                    <h3 class="profile-section-title">Change Password</h3>
                    <form class="profile-form" method="post">
                        <input type="hidden" name="action" value="change_password">
                        <div>
                            <label for="currentPassword">Current Password</label>
                            <input id="currentPassword" type="password" name="current_password" placeholder="Enter current password">
                        </div>
                        <div>
                            <label for="newPassword">New Password</label>
                            <input id="newPassword" type="password" name="new_password" placeholder="Enter new password" required>
                        </div>
                        <div>
                            <label for="confirmPassword">Confirm Password</label>
                            <input id="confirmPassword" type="password" name="confirm_password" placeholder="Confirm new password" required>
                        </div>
                        <button class="primary-btn" type="submit">Update Password</button>
                    </form>
                </section>
            </div>

            <div class="modal-overlay profile-modal <?= $showNotificationModal ? 'is-open' : '' ?>" id="notificationModal">
                <section class="profile-modal-card" aria-label="Notification settings popup">
                    <button class="profile-modal-close" type="button" data-close-modal="notificationModal" aria-label="Close notification settings">×</button>
                    <h3 class="profile-section-title">Notification Settings</h3>
                    <form class="profile-form" method="post">
                        <input type="hidden" name="action" value="save_notifications">
                        <div class="toggle-row">
                            <div class="toggle-copy">
                                <strong>Profile Notifications</strong>
                                <p>Receive updates about orders, messages, and account activity.</p>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="notifications_enabled" <?= $profileNotificationsEnabled ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        <button class="primary-btn" type="submit">Save Settings</button>
                    </form>
                </section>
            </div>
        <?php endif; ?>

        <?php if ($showFilterPopup && $selectedView === 'home'): ?>
            <div class="modal-overlay filter-overlay">
                <section class="filter-popup" aria-label="Filter categories">
                    <div class="filter-popup-head">
                        <div>
                            <h4>More Categories</h4>
                            <p>Choose a category or show all furniture.</p>
                        </div>
                        <a class="filter-popup-close" href="user.php?view=home#categories" aria-label="Close filter popup">×</a>
                    </div>
                    <div class="filter-popup-grid">
                        <a class="filter-popup-card is-default" href="user.php?view=home&amp;show=all#popularProducts">
                            <div class="filter-popup-default-icon" aria-hidden="true">•</div>
                            <span>All</span>
                        </a>
                        <?php foreach (($categoryCards === [] ? [
                            ['name' => 'Sofa'],
                            ['name' => 'Chair'],
                            ['name' => 'Bed'],
                            ['name' => 'Cabinet'],
                        ] : $categoryCards) as $categoryCard): ?>
                            <?php $popupCategoryName = (string) ($categoryCard['name'] ?? ''); ?>
                            <a class="filter-popup-card" href="user.php?view=home&amp;category=<?= urlencode($popupCategoryName) ?>&amp;show=all#popularProducts">
                                <?= renderCategoryIcon($popupCategoryName) ?>
                                <span><?= htmlspecialchars($popupCategoryName, ENT_QUOTES, 'UTF-8') ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>
        <?php endif; ?>

        <?php if ($selectedCategoryPopup !== '' && $selectedView === 'home'): ?>
            <div class="modal-overlay">
                <section class="category-popup" aria-label="Category popup">
                    <?= renderCategoryIcon($selectedCategoryPopup) ?>
                    <h4><?= htmlspecialchars($selectedCategoryPopup, ENT_QUOTES, 'UTF-8') ?></h4>
                    <p>Open this category and show matching furniture products.</p>
                    <div class="actions">
                        <a class="ghost-btn" href="user.php?view=home">Close</a>
                        <a class="mini-btn" href="user.php?view=home&amp;category=<?= urlencode($selectedCategoryPopup) ?>#popularProducts">Open</a>
                    </div>
                </section>
            </div>
        <?php endif; ?>

        <?php if ($showIncompleteOrderModal): ?>
            <div class="modal-overlay profile-modal is-open" id="incompleteOrderModal">
                <section class="profile-modal-card" aria-label="Incomplete order information popup">
                    <button class="profile-modal-close" type="button" data-close-modal="incompleteOrderModal" aria-label="Close incomplete information popup">×</button>
                    <h3 class="profile-section-title">Complete Information</h3>
                    <p style="margin:0 0 14px; color:var(--muted); font-size:0.82rem; line-height:1.5;">
                        Add your phone number and delivery address first before placing an order.
                    </p>
                    <div class="detail-actions">
                        <a class="message-btn" href="user.php?view=<?= urlencode($selectedView) ?>">Close</a>
                        <a class="mini-btn" href="user.php?view=profile">Open Profile</a>
                    </div>
                </section>
            </div>
        <?php endif; ?>

        <?php if ($selectedProduct !== null && $selectedView === 'home'): ?>
            <div class="modal-overlay">
                <section class="detail-panel" aria-label="Product details">
                    <div class="detail-image-wrap">
                        <a class="detail-close" href="user.php?view=home&amp;category=<?= urlencode($selectedCategory) ?>&amp;search=<?= urlencode($search) ?>&amp;show=<?= $showAllProducts ? 'all' : 'top' ?>#popularProducts">×</a>
                        <?php if (($selectedProduct['image'] ?? '') !== ''): ?>
                            <button class="zoomable-image-btn" type="button" data-zoom-image="<?= htmlspecialchars($selectedProduct['image'], ENT_QUOTES, 'UTF-8') ?>" data-zoom-alt="<?= htmlspecialchars($selectedProduct['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" aria-label="Zoom <?= htmlspecialchars($selectedProduct['name'] ?? 'product image', ENT_QUOTES, 'UTF-8') ?>">
                                <img class="detail-image" src="<?= htmlspecialchars($selectedProduct['image'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($selectedProduct['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            </button>
                        <?php else: ?>
                            <div class="detail-image" style="display:grid;place-items:center;color:#12357f;font-weight:700;">No Image</div>
                        <?php endif; ?>
                    </div>
                    <div class="detail-body">
                        <div class="detail-head-row">
                            <div class="detail-title-block">
                                <h4><?= htmlspecialchars($selectedProduct['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></h4>
                                <p class="detail-price">P<?= number_format((float) ($selectedProduct['price'] ?? 0), 2) ?></p>
                            </div>
                            <span class="detail-stock-pill"><?= htmlspecialchars((string) ($selectedProduct['stock'] ?? 0), ENT_QUOTES, 'UTF-8') ?> left</span>
                        </div>
                        <span class="detail-section-label">Product Info</span>
                        <div class="detail-grid">
                            <div class="detail-field">
                                <label>Category</label>
                                <select><option><?= htmlspecialchars($selectedProduct['category'] ?? 'Furniture', ENT_QUOTES, 'UTF-8') ?></option></select>
                            </div>
                            <div class="detail-field">
                                <label>Material</label>
                                <select><option><?= htmlspecialchars($selectedProduct['material'] ?? 'Wood', ENT_QUOTES, 'UTF-8') ?></option></select>
                            </div>
                            <div class="detail-field">
                                <label>Size</label>
                                <select><option><?= htmlspecialchars(mixProductSize($selectedProduct), ENT_QUOTES, 'UTF-8') ?></option></select>
                            </div>
                            <div class="detail-field">
                                <label>Stock</label>
                                <select><option><?= htmlspecialchars((string) ($selectedProduct['stock'] ?? 0), ENT_QUOTES, 'UTF-8') ?> available</option></select>
                            </div>
                        </div>
                        <span class="detail-section-label">Description</span>
                        <div class="detail-description detail-description-text"><?= nl2br(htmlspecialchars($selectedProduct['description'] ?? 'No description provided.', ENT_QUOTES, 'UTF-8')) ?></div>
                        <?php if (!$canPlaceOrder): ?>
                            <p class="empty-state" style="margin: 0 0 10px;">Complete your profile phone number and address before placing an order.</p>
                        <?php endif; ?>
                        <form class="detail-order" method="post">
                            <input type="hidden" name="product_id" value="<?= htmlspecialchars($selectedProduct['id'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            <div class="detail-qty-row">
                                <div>
                                    <span>Quantity</span>
                                    <small class="detail-actions-title">Choose how many to order</small>
                                </div>
                                <div class="qty-stepper">
                                    <button class="qty-btn" type="button" data-qty-change="-1">-</button>
                                    <input class="qty-input" id="detailQuantity" type="number" name="quantity" min="1" max="<?= max(1, (int) ($selectedProduct['stock'] ?? 0)) ?>" value="1">
                                    <button class="qty-btn" type="button" data-qty-change="1">+</button>
                                </div>
                            </div>
                            <span class="detail-section-label">Actions</span>
                            <div class="detail-actions">
                                <button class="message-btn" type="submit" name="action" value="add_to_cart">Add to Cart</button>
                                <button class="mini-btn" type="submit" name="action" value="order_product" <?= !$canPlaceOrder ? 'disabled' : '' ?>>Place Order</button>
                                <button class="message-btn" type="submit" name="action" value="add_to_wishlist">Wishlist</button>
                                <a class="icon-action-btn" href="user.php?view=messages&amp;product=<?= urlencode((string) ($selectedProduct['id'] ?? '')) ?>" aria-label="Open chat">
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M21 11.5a8.5 8.5 0 0 1-8.5 8.5A8.4 8.4 0 0 1 8 18.7L3 20l1.3-5A8.4 8.4 0 0 1 3 11.5 8.5 8.5 0 0 1 11.5 3h1A8.5 8.5 0 0 1 21 11.5Z"></path>
                                    </svg>
                                </a>
                            </div>
                        </form>
                    </div>
                </section>
            </div>
        <?php endif; ?>

        <div class="modal-overlay profile-modal" id="imageLightboxModal">
            <section class="image-lightbox" aria-label="Product image zoom">
                <button class="image-lightbox-close" type="button" data-close-modal="imageLightboxModal" aria-label="Close image zoom">×</button>
                <img id="imageLightboxPreview" src="" alt="">
                <div class="image-lightbox-copy" id="imageLightboxLabel"></div>
            </section>
        </div>

        <input class="nav-toggle-input" type="checkbox" id="mobileNavToggle" aria-hidden="true">
        <label class="mobile-menu-toggle" for="mobileNavToggle" aria-label="Open navigation menu"><span></span></label>
        <nav class="bottom-nav" aria-label="Main navigation">
            <a class="nav-item <?= $selectedView === 'home' ? 'active' : '' ?>" href="user.php?view=home"><span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5"></path><path d="M5 9.5V21h14V9.5"></path></svg></span><span>Home</span></a>
            <a class="nav-item <?= in_array($selectedView, ['mix', 'designs'], true) ? 'active' : '' ?>" href="user.php?view=mix"><span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 10h16"></path><path d="M6 10V7a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v3"></path><path d="M7 10v8"></path><path d="M17 10v8"></path><path d="M5 18h14"></path></svg></span><span>Mix</span></a>
            <a class="nav-item <?= $selectedView === 'messages' ? 'active' : '' ?>" href="user.php?view=messages"><span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 11.5a8.5 8.5 0 0 1-8.5 8.5A8.4 8.4 0 0 1 8 18.7L3 20l1.3-5A8.4 8.4 0 0 1 3 11.5 8.5 8.5 0 0 1 11.5 3h1A8.5 8.5 0 0 1 21 11.5Z"></path></svg></span><span class="nav-badge message-nav-badge <?= $userUnreadMessageCount > 0 ? '' : 'is-hidden' ?>"><?= $userUnreadMessageCount ?></span><span>Messages</span></a>
            <a class="nav-item <?= $selectedView === 'cart' ? 'active' : '' ?>" href="user.php?view=cart"><span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="20" r="1.5"></circle><circle cx="18" cy="20" r="1.5"></circle><path d="M3 4h2l2.2 10.2a1 1 0 0 0 1 .8h8.8a1 1 0 0 0 1-.8L20 7H7"></path></svg></span><?php if (count($cartItems) > 0): ?><span class="nav-badge"><?= count($cartItems) ?></span><?php endif; ?><span>Cart</span></a>
            <a class="nav-item <?= $selectedView === 'orders' ? 'active' : '' ?>" href="user.php?view=orders"><span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="3" width="14" height="18" rx="2"></rect><path d="M9 7h6"></path><path d="M9 11h6"></path><path d="M9 15h4"></path></svg></span><?php if (count($orders) + count($customerCustomizationRequests) > 0): ?><span class="nav-badge"><?= count($orders) + count($customerCustomizationRequests) ?></span><?php endif; ?><span>Orders</span></a>
        </nav>
    </div>
    <script>
        (function () {
            let hasFocusedField = false;
            let hasPendingChanges = false;
            const fields = Array.from(document.querySelectorAll('input, textarea, select'));
            document.querySelectorAll('form.customization-form').forEach(function (form) {
                form.addEventListener('submit', async function (event) {
                    if (form.dataset.uploadPrepared === '1') return;
                    const input = form.querySelector('input[name="reference_image"], input[name="payment_proof"]');
                    const file = input && input.files ? input.files[0] : null;
                    if (!file || file.size < 30 * 1024 * 1024) return;
                    event.preventDefault();
                    const submitter = event.submitter;
                    try {
                        const bitmap = await createImageBitmap(file);
                        const scale = Math.min(1, 2400 / Math.max(bitmap.width, bitmap.height));
                        const canvas = document.createElement('canvas');
                        canvas.width = Math.max(1, Math.round(bitmap.width * scale));
                        canvas.height = Math.max(1, Math.round(bitmap.height * scale));
                        canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
                        bitmap.close();
                        const blob = await new Promise(function (resolve) { canvas.toBlob(resolve, 'image/jpeg', 0.82); });
                        if (!blob || blob.size >= 30 * 1024 * 1024) throw new Error('Image could not be resized.');
                        const files = new DataTransfer();
                        files.items.add(new File([blob], 'customization-image.jpg', { type: 'image/jpeg' }));
                        input.files = files.files;
                        form.dataset.uploadPrepared = '1';
                        form.requestSubmit(submitter);
                    } catch (error) {
                        window.alert('This image could not be prepared for upload. Try saving it as JPG, then send it again.');
                    }
                });
            });
            const quantityInput = document.getElementById('detailQuantity');
            const quantityButtons = Array.from(document.querySelectorAll('[data-qty-change]'));
            const modalOpeners = Array.from(document.querySelectorAll('[data-open-modal]'));
            const modalClosers = Array.from(document.querySelectorAll('[data-close-modal]'));
            const profileModals = Array.from(document.querySelectorAll('.profile-modal'));
            const zoomImageButtons = Array.from(document.querySelectorAll('[data-zoom-image]'));
            const imageLightboxModal = document.getElementById('imageLightboxModal');
            const imageLightboxPreview = document.getElementById('imageLightboxPreview');
            const imageLightboxLabel = document.getElementById('imageLightboxLabel');
            const notice = document.querySelector('.notice');
            const userMessageImageInput = document.getElementById('userMessageImage');
            const userMessageImageTrigger = document.getElementById('userMessageImageTrigger');
            const userMessageImageStatus = document.getElementById('userMessageImageStatus');
            const userMessageImagePreviewCard = document.getElementById('userMessageImagePreviewCard');
            const userMessageImagePreview = document.getElementById('userMessageImagePreview');
            const userMessageImagePreviewMeta = document.getElementById('userMessageImagePreviewMeta');
            const userChatComposer = document.getElementById('userChatComposer');
            const userMessageSendPreviewModal = document.getElementById('userMessageSendPreviewModal');
            const userMessageSendPreviewImage = document.getElementById('userMessageSendPreviewImage');
            const userMessageSendPreviewMeta = document.getElementById('userMessageSendPreviewMeta');
            const userMessageSendPreviewCancel = document.getElementById('userMessageSendPreviewCancel');
            const userMessageSendPreviewConfirm = document.getElementById('userMessageSendPreviewConfirm');
            const messageNavBadge = document.querySelector('.message-nav-badge');
            const adminMessageAlert = document.querySelector('[data-admin-message-alert]');
            const adminMessageCount = document.querySelector('[data-admin-message-count]');
            const liveMessageState = {
                enabled: true,
                endpoint: 'user.php?ajax=message_status',
                isMessagesView: <?= $selectedView === 'messages' ? 'true' : 'false' ?>,
                signature: <?= json_encode((string) ($userMessageState['signature'] ?? '')) ?>,
                unreadCount: <?= (int) ($userMessageState['unread_count'] ?? 0) ?>,
                baseTitle: document.title
            };
            const shouldAutoRefresh = <?= in_array($selectedView, ['profile', 'wishlist', 'messages', 'mix', 'designs'], true) ? 'false' : 'true' ?>;
            const heroSlider = document.querySelector('[data-hero-slider]');
            const heroSlides = heroSlider ? Array.from(heroSlider.querySelectorAll('.hero-slide')) : [];
            const heroDots = Array.from(document.querySelectorAll('.hero-dots span'));
            let heroIndex = 0;
            const mixBuilder = document.querySelector('[data-mix-builder]');
            if (mixBuilder) {
                const mixProducts = JSON.parse(mixBuilder.dataset.products || '[]');
                const loadedDesign = JSON.parse(mixBuilder.dataset.design || 'null');
                const productMap = new Map(mixProducts.map(function (product) {
                    return [product.id, product];
                }));
                const board = mixBuilder.querySelector('[data-mix-board]');
                const selectedList = mixBuilder.querySelector('[data-mix-selected]');
                const emptyState = mixBuilder.querySelector('[data-mix-empty]');
                const countLabel = mixBuilder.querySelector('[data-mix-count]');
                const totalLabel = mixBuilder.querySelector('[data-mix-total]');
                const totalItemsLabel = mixBuilder.querySelector('[data-mix-total-items]');
                const totalQtyLabel = mixBuilder.querySelector('[data-mix-total-qty]');
                const warning = mixBuilder.querySelector('[data-mix-warning]');
                const payloadInputs = Array.from(mixBuilder.querySelectorAll('[data-mix-payload]'));
                const forms = Array.from(mixBuilder.querySelectorAll('[data-mix-save-form], [data-mix-cart-form]'));
                const categoryTabs = Array.from(mixBuilder.querySelectorAll('[data-mix-category]'));
                const productRows = Array.from(mixBuilder.querySelectorAll('.mix-product'));
                const categoryCountLabel = mixBuilder.querySelector('[data-mix-category-count]');
                const categoryEmpty = mixBuilder.querySelector('[data-mix-category-empty]');
                const selectionTools = mixBuilder.querySelector('[data-mix-selection-tools]');
                const actionButtons = Array.from(mixBuilder.querySelectorAll('[data-mix-action]'));
                const pageTabs = Array.from(mixBuilder.querySelectorAll('[data-mix-page-tab]'));
                const pagePanels = Array.from(mixBuilder.querySelectorAll('[data-mix-page-panel]'));
                const customizeCategorySelect = mixBuilder.querySelector('[data-customize-category-select]');
                let mixItems = [];
                let selectedMixIndex = -1;
                let undoStack = [];
                let redoStack = [];
                let isDraggingMix = false;

                const escapeHtml = function (value) {
                    const div = document.createElement('div');
                    div.textContent = String(value || '');
                    return div.innerHTML;
                };

                const escapeAttr = function (value) {
                    return escapeHtml(value).replace(/"/g, '&quot;').replace(/'/g, '&#039;');
                };

                const money = function (value) {
                    return 'P' + Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                };

                const clampNumber = function (value, min, max) {
                    return Math.max(min, Math.min(max, Number(value || 0)));
                };

                const cloneItems = function () {
                    return JSON.parse(JSON.stringify(mixItems));
                };

                const renderedBaseWidth = function (item) {
                    const product = productMap.get(item.product_id) || {};
                    const baseWidth = Number(item.board_width || product.board_width || 120);
                    const boardWidth = board ? Math.max(320, board.clientWidth || 620) : 620;
                    const responsiveFactor = clampNumber(boardWidth / 620, 0.78, 1.32);
                    return Math.round(baseWidth * responsiveFactor * (boardWidth <= 420 ? 0.72 : 1));
                };

                const itemDimensions = function (item) {
                    const baseWidth = renderedBaseWidth(item);
                    const scale = Number(item.scale_value || 1);
                    const aspect = Number(item.aspect_ratio || 0.78);
                    return {
                        widthPct: board ? ((baseWidth * scale) / Math.max(1, board.clientWidth)) * 100 : 12,
                        heightPct: board ? ((baseWidth * aspect * scale) / Math.max(1, board.clientHeight)) * 100 : 12
                    };
                };

                const constrainItem = function (item) {
                    const dims = itemDimensions(item);
                    const halfWidth = Math.min(36, Math.max(4, dims.widthPct / 2));
                    const itemHeight = Math.min(78, Math.max(8, dims.heightPct));
                    item.position_x = clampNumber(item.position_x || 50, halfWidth, 100 - halfWidth);
                    item.position_y = clampNumber(item.position_y || 70, Math.max(18, itemHeight), 96);
                    item.scale_value = clampNumber(item.scale_value || 1, 0.45, 2.15);
                    item.rotation = clampNumber(item.rotation || 0, -180, 180);
                    return item;
                };

                const constrainAllItems = function () {
                    mixItems.forEach(constrainItem);
                };

                const normalizeLayers = function () {
                    mixItems.forEach(function (item, index) {
                        item.layer_order = index + 1;
                    });
                };

                const updateHistoryButtons = function () {
                    actionButtons.forEach(function (button) {
                        const action = button.dataset.mixAction || '';
                        if (action === 'undo') {
                            button.disabled = undoStack.length === 0;
                        }
                        if (action === 'redo') {
                            button.disabled = redoStack.length === 0;
                        }
                    });
                };

                const rememberMix = function () {
                    undoStack.push(cloneItems());
                    if (undoStack.length > 35) {
                        undoStack.shift();
                    }
                    redoStack = [];
                    updateHistoryButtons();
                };

                const syncPayload = function () {
                    normalizeLayers();
                    constrainAllItems();
                    const payload = JSON.stringify(mixItems.map(function (item) {
                        return {
                            product_id: item.product_id,
                            selected_color: item.selected_color,
                            selected_material: item.selected_material,
                            selected_size: item.selected_size,
                            quantity: item.quantity,
                            price: item.price,
                            position_x: item.position_x,
                            position_y: item.position_y,
                            rotation: item.rotation,
                            scale_value: item.scale_value,
                            layer_order: item.layer_order
                        };
                    }));
                    payloadInputs.forEach(function (input) {
                        input.value = payload;
                    });
                };

                const validateMix = function () {
                    const problems = [];
                    const counts = new Map();

                    mixItems.forEach(function (item) {
                        const product = productMap.get(item.product_id);
                        if (!product) {
                            problems.push('A selected furniture item no longer exists.');
                            return;
                        }

                        const nextCount = (counts.get(item.product_id) || 0) + Number(item.quantity || 1);
                        counts.set(item.product_id, nextCount);
                        if (Number(product.stock || 0) < nextCount) {
                            problems.push(product.name + ' exceeds available stock.');
                        }
                    });

                    warning.textContent = problems[0] || '';
                    warning.classList.toggle('is-visible', problems.length > 0);
                    return problems.length === 0;
                };

                const autoPlacementFor = function (product, categoryIndex) {
                    const category = product.mix_category || 'Furniture';
                    const sameTypeCount = Number(categoryIndex || 0);
                    const slots = {
                        Sofa: [
                            { x: 50, y: 66, scale: 1.08, rotation: 0 },
                            { x: 36, y: 68, scale: 0.86, rotation: 0 },
                            { x: 64, y: 68, scale: 0.86, rotation: 0 }
                        ],
                        Bed: [
                            { x: 50, y: 69, scale: 1.05, rotation: 0 },
                            { x: 36, y: 72, scale: 0.84, rotation: 0 },
                            { x: 64, y: 72, scale: 0.84, rotation: 0 }
                        ],
                        'Dining Set': [
                            { x: 50, y: 78, scale: 0.92, rotation: 0 },
                            { x: 38, y: 80, scale: 0.74, rotation: 0 },
                            { x: 62, y: 80, scale: 0.74, rotation: 0 }
                        ],
                        Cabinet: [
                            { x: 50, y: 59, scale: 0.9, rotation: 0 },
                            { x: 25, y: 60, scale: 0.8, rotation: 0 },
                            { x: 75, y: 60, scale: 0.8, rotation: 0 }
                        ],
                        Chair: [
                            { x: 50, y: 72, scale: 0.8, rotation: 0 },
                            { x: 34, y: 74, scale: 0.74, rotation: 0 },
                            { x: 66, y: 74, scale: 0.74, rotation: 0 }
                        ],
                        Door: [
                            { x: 50, y: 39, scale: 0.72, rotation: 0 },
                            { x: 29, y: 38, scale: 0.72, rotation: 0 },
                            { x: 71, y: 36, scale: 0.68, rotation: 0 }
                        ]
                    };
                    const slotList = slots[category] || slots.Cabinet;
                    const slot = slotList[sameTypeCount % slotList.length];
                    const layerOffset = Math.floor(sameTypeCount / slotList.length) * 5;

                    return {
                        x: Math.max(12, Math.min(88, slot.x + layerOffset)),
                        y: Math.max(28, Math.min(88, slot.y + layerOffset)),
                        scale: slot.scale,
                        rotation: slot.rotation
                    };
                };

                const productQuantityInRoom = function (productId) {
                    return mixItems.reduce(function (sum, item) {
                        return sum + (item.product_id === productId ? Number(item.quantity || 1) : 0);
                    }, 0);
                };

                const updateAddButtons = function () {
                    mixBuilder.querySelectorAll('[data-mix-add]').forEach(function (button) {
                        const product = productMap.get(button.dataset.mixAdd || '');
                        if (!product) {
                            button.disabled = true;
                            return;
                        }
                        const remaining = Number(product.stock || 0) - productQuantityInRoom(product.id);
                        button.disabled = remaining <= 0;
                        button.title = remaining > 0 ? remaining + ' available' : 'Stock limit reached';
                    });
                };

                const placementForNewItem = function (product) {
                    const category = product.mix_category || 'Furniture';
                    const count = mixItems.filter(function (item) {
                        const existingProduct = productMap.get(item.product_id);
                        return existingProduct && (existingProduct.mix_category || 'Furniture') === category;
                    }).length;
                    return autoPlacementFor(product, count);
                };

                const renderMix = function () {
                    selectedList.innerHTML = '';
                    board.querySelectorAll('.mix-board-item').forEach(function (node) {
                        node.remove();
                    });
                    emptyState.style.display = mixItems.length === 0 ? 'grid' : 'none';
                    normalizeLayers();

                    mixItems.forEach(function (item, index) {
                        const product = productMap.get(item.product_id);
                        if (!product) {
                            return;
                        }
                        constrainItem(item);

                        const boardItem = document.createElement('button');
                        boardItem.className = 'mix-board-item';
                        boardItem.type = 'button';
                        boardItem.style.left = item.position_x + '%';
                        boardItem.style.top = item.position_y + '%';
                        boardItem.style.width = renderedBaseWidth(item) + 'px';
                        boardItem.style.transform = 'translate(-50%, -100%) rotate(' + item.rotation + 'deg) scale(' + item.scale_value + ')';
                        boardItem.style.zIndex = String(10 + Number(item.layer_order || index + 1));
                        boardItem.dataset.index = String(index);
                        boardItem.classList.toggle('is-selected', index === selectedMixIndex);
                        boardItem.innerHTML = '<img src="' + escapeAttr(product.image) + '" alt=""><span>' + escapeHtml(product.name) + '</span>';
                        const boardImage = boardItem.querySelector('img');
                        if (boardImage) {
                            boardImage.addEventListener('load', function () {
                                if (boardImage.naturalWidth > 0 && boardImage.naturalHeight > 0) {
                                    item.aspect_ratio = boardImage.naturalHeight / boardImage.naturalWidth;
                                    constrainItem(item);
                                    boardItem.style.left = item.position_x + '%';
                                    boardItem.style.top = item.position_y + '%';
                                    syncPayload();
                                }
                            }, { once: true });
                        }
                        board.appendChild(boardItem);

                        const selectedItem = document.createElement('article');
                        selectedItem.className = 'mix-selected-item';
                        selectedItem.classList.toggle('is-selected', index === selectedMixIndex);
                        selectedItem.dataset.mixSelect = String(index);
                        const subtotal = Number(item.price || 0) * Number(item.quantity || 1);
                        selectedItem.innerHTML =
                            '<img class="mix-item-thumb" src="' + escapeAttr(product.image) + '" alt="">' +
                            '<div class="mix-selected-copy">' +
                                '<strong>' + escapeHtml(product.name) + '</strong>' +
                                '<p>' + escapeHtml(product.category) + '</p>' +
                                '<p class="mix-unit-price">Price each: ' + money(product.price) + '</p>' +
                                '<p>Color: ' + escapeHtml(item.selected_color) + ' - Material: ' + escapeHtml(item.selected_material) + '</p>' +
                                '<p>Qty: ' + Number(item.quantity || 1) + ' - Subtotal: ' + money(subtotal) + '</p>' +
                                '<div class="mix-item-controls">' +
                                    '<label>Color<input type="text" value="' + escapeAttr(item.selected_color) + '" readonly></label>' +
                                    '<label>Material<input type="text" value="' + escapeAttr(item.selected_material) + '" readonly></label>' +
                                    '<label>Stock<input type="text" value="' + Number(product.stock || 0) + ' available" readonly></label>' +
                                    '<label>Qty<input type="number" min="1" max="' + product.stock + '" value="' + item.quantity + '" data-mix-qty="' + index + '"></label>' +
                                '</div>' +
                                '<div class="mix-line-actions">' +
                                    (index === selectedMixIndex ? '<button class="mix-mini-btn soft" type="button" data-customize-selected="' + escapeAttr(product.id) + '">Customize Selected Item</button>' : '') +
                                    '<button class="mix-mini-btn" type="button" data-mix-remove="' + index + '">Remove</button>' +
                                '</div>' +
                            '</div>';
                        selectedList.appendChild(selectedItem);
                    });

                    if (selectionTools) {
                        selectionTools.classList.toggle('is-visible', selectedMixIndex >= 0 && Boolean(mixItems[selectedMixIndex]));
                    }
                    const total = mixItems.reduce(function (sum, item) {
                        return sum + Number(item.price || 0) * Number(item.quantity || 1);
                    }, 0);
                    const totalQty = mixItems.reduce(function (sum, item) {
                        return sum + Number(item.quantity || 1);
                    }, 0);
                    countLabel.textContent = mixItems.length + (mixItems.length === 1 ? ' item' : ' items');
                    if (totalItemsLabel) {
                        totalItemsLabel.textContent = String(mixItems.length);
                    }
                    if (totalQtyLabel) {
                        totalQtyLabel.textContent = String(totalQty);
                    }
                    totalLabel.textContent = money(total);
                    syncPayload();
                    validateMix();
                    updateAddButtons();
                    updateHistoryButtons();
                };

                const addProductToMix = function (product) {
                    if (productQuantityInRoom(product.id) >= Number(product.stock || 0)) {
                        warning.textContent = product.name + ' exceeds available stock.';
                        warning.classList.add('is-visible');
                        return;
                    }
                    rememberMix();
                    const placement = placementForNewItem(product);
                    mixItems.push({
                        product_id: product.id,
                        selected_color: product.color || 'Available',
                        selected_material: product.material || 'Available',
                        selected_size: product.size || 'Confirm with seller',
                        quantity: 1,
                        price: Number(product.price || 0),
                        position_x: placement.x,
                        position_y: placement.y,
                        rotation: placement.rotation,
                        scale_value: placement.scale,
                        board_width: Number(product.board_width || 120),
                        layer_order: mixItems.length + 1
                    });
                    selectedMixIndex = mixItems.length - 1;
                    renderMix();
                };

                mixBuilder.addEventListener('click', function (event) {
                    if (event.target.closest('img') && !event.target.closest('[data-mix-add], [data-mix-remove], [data-mix-tool], [data-mix-action]')) {
                        event.stopPropagation();
                    }
                    const addButton = event.target.closest('[data-mix-add]');
                    const removeButton = event.target.closest('[data-mix-remove]');
                    const boardItemButton = event.target.closest('.mix-board-item');
                    const categoryButton = event.target.closest('[data-mix-category]');
                    const toolButton = event.target.closest('[data-mix-tool]');
                    const actionButton = event.target.closest('[data-mix-action]');
                    const selectButton = event.target.closest('[data-mix-select]');
                    const pageTabButton = event.target.closest('[data-mix-page-tab]');
                    const customizeButton = event.target.closest('[data-customize-selected]');

                    if (pageTabButton) {
                        const nextPanel = pageTabButton.dataset.mixPageTab || 'builder';
                        pageTabs.forEach(function (tab) {
                            tab.classList.toggle('active', tab === pageTabButton);
                        });
                        pagePanels.forEach(function (panel) {
                            panel.hidden = panel.dataset.mixPagePanel !== nextPanel;
                        });
                    }

                    if (customizeButton) {
                        const product = productMap.get(customizeButton.dataset.customizeSelected || '');
                        if (product && customizeCategorySelect) {
                            customizeCategorySelect.value = product.category;
                        }
                        const customizeTab = pageTabs.find(function (tab) {
                            return tab.dataset.mixPageTab === 'customize';
                        });
                        if (customizeTab) {
                            customizeTab.click();
                        }
                        return;
                    }

                    if (categoryButton) {
                        const nextCategory = categoryButton.dataset.mixCategory || 'Bed';
                        categoryTabs.forEach(function (tab) {
                            tab.classList.toggle('active', tab === categoryButton);
                            tab.setAttribute('aria-pressed', tab === categoryButton ? 'true' : 'false');
                        });
                        let visibleCount = 0;
                        productRows.forEach(function (row) {
                            row.hidden = row.dataset.mixProductCategory !== nextCategory;
                            if (!row.hidden) {
                                visibleCount++;
                            }
                        });
                        if (categoryCountLabel) {
                            categoryCountLabel.textContent = visibleCount + ' available';
                        }
                        if (categoryEmpty) {
                            categoryEmpty.hidden = visibleCount > 0;
                            categoryEmpty.textContent = visibleCount === 0 ? 'No ' + nextCategory + ' furniture available.' : '';
                        }
                    }

                    if (selectButton && !event.target.closest('button, input, select')) {
                        selectedMixIndex = Number(selectButton.dataset.mixSelect);
                        renderMix();
                    }

                    if (boardItemButton) {
                        selectedMixIndex = Number(boardItemButton.dataset.index);
                        renderMix();
                    }

                    if (actionButton) {
                        const action = actionButton.dataset.mixAction || '';
                        if (action === 'clear') {
                            if (mixItems.length > 0 && window.confirm('Clear this Mix & Match room?')) {
                                rememberMix();
                                mixItems = [];
                                selectedMixIndex = -1;
                                renderMix();
                            }
                        }
                        if (action === 'undo' && undoStack.length > 0) {
                            redoStack.push(cloneItems());
                            mixItems = undoStack.pop() || [];
                            selectedMixIndex = Math.min(selectedMixIndex, mixItems.length - 1);
                            renderMix();
                        }
                        if (action === 'redo' && redoStack.length > 0) {
                            undoStack.push(cloneItems());
                            mixItems = redoStack.pop() || [];
                            selectedMixIndex = Math.min(selectedMixIndex, mixItems.length - 1);
                            renderMix();
                        }
                    }

                    if (toolButton) {
                        const tool = toolButton.dataset.mixTool || '';
                        if (selectedMixIndex < 0 || !mixItems[selectedMixIndex]) {
                            warning.textContent = 'Select furniture in the preview first.';
                            warning.classList.add('is-visible');
                            return;
                        }

                        if (tool === 'move') {
                            warning.textContent = 'Drag the selected furniture inside the room to move it.';
                            warning.classList.add('is-visible');
                            return;
                        }

                        rememberMix();
                        if (tool === 'rotate_left') {
                            mixItems[selectedMixIndex].rotation = ((Number(mixItems[selectedMixIndex].rotation || 0) - 15 + 180) % 360) - 180;
                        }
                        if (tool === 'rotate_right') {
                            mixItems[selectedMixIndex].rotation = ((Number(mixItems[selectedMixIndex].rotation || 0) + 15 + 180) % 360) - 180;
                        }
                        if (tool === 'resize') {
                            const nextScale = Number(mixItems[selectedMixIndex].scale_value || 1) + 0.15;
                            mixItems[selectedMixIndex].scale_value = nextScale > 1.85 ? 0.65 : nextScale;
                            constrainItem(mixItems[selectedMixIndex]);
                        }
                        if (tool === 'forward' && selectedMixIndex < mixItems.length - 1) {
                            const moved = mixItems.splice(selectedMixIndex, 1)[0];
                            selectedMixIndex += 1;
                            mixItems.splice(selectedMixIndex, 0, moved);
                        }
                        if (tool === 'backward' && selectedMixIndex > 0) {
                            const moved = mixItems.splice(selectedMixIndex, 1)[0];
                            selectedMixIndex -= 1;
                            mixItems.splice(selectedMixIndex, 0, moved);
                        }
                        if (tool === 'delete') {
                            mixItems.splice(selectedMixIndex, 1);
                            selectedMixIndex = Math.min(selectedMixIndex, mixItems.length - 1);
                        }

                        renderMix();
                    }

                    if (addButton) {
                        const product = productMap.get(addButton.dataset.mixAdd || '');
                        if (product && Number(product.stock || 0) > 0) {
                            addProductToMix(product);
                        }
                    }

                    if (removeButton) {
                        rememberMix();
                        mixItems.splice(Number(removeButton.dataset.mixRemove), 1);
                        selectedMixIndex = Math.min(selectedMixIndex, mixItems.length - 1);
                        renderMix();
                    }

                });

                mixBuilder.addEventListener('input', function (event) {
                    const qtyInput = event.target.closest('[data-mix-qty]');
                    if (!qtyInput) {
                        return;
                    }
                    const index = Number(qtyInput.dataset.mixQty);
                    const product = productMap.get(mixItems[index] ? mixItems[index].product_id : '');
                    const usedByOtherRows = product ? mixItems.reduce(function (sum, item, itemIndex) {
                        return sum + (itemIndex !== index && item.product_id === product.id ? Number(item.quantity || 1) : 0);
                    }, 0) : 0;
                    const max = product ? Math.max(1, Number(product.stock || 1) - usedByOtherRows) : 1;
                    rememberMix();
                    mixItems[index].quantity = Math.max(1, Math.min(max, Number(qtyInput.value || 1)));
                    renderMix();
                });

                board.addEventListener('pointerdown', function (event) {
                    const node = event.target.closest('.mix-board-item');
                    if (!node) {
                        return;
                    }
                    const index = Number(node.dataset.index);
                    selectedMixIndex = index;
                    isDraggingMix = true;
                    let hasRememberedDrag = false;
                    board.querySelectorAll('.mix-board-item').forEach(function (itemNode) {
                        itemNode.classList.toggle('is-selected', itemNode === node);
                    });
                    node.setPointerCapture(event.pointerId);
                    const move = function (moveEvent) {
                        if (!hasRememberedDrag) {
                            rememberMix();
                            hasRememberedDrag = true;
                        }
                        const rect = board.getBoundingClientRect();
                        const x = ((moveEvent.clientX - rect.left) / rect.width) * 100;
                        const y = ((moveEvent.clientY - rect.top) / rect.height) * 100;
                        mixItems[index].position_x = x;
                        mixItems[index].position_y = y;
                        constrainItem(mixItems[index]);
                        node.style.left = mixItems[index].position_x + '%';
                        node.style.top = mixItems[index].position_y + '%';
                        syncPayload();
                    };
                    const up = function () {
                        isDraggingMix = false;
                        node.removeEventListener('pointermove', move);
                        node.removeEventListener('pointerup', up);
                        node.removeEventListener('pointercancel', up);
                    };
                    node.addEventListener('pointermove', move);
                    node.addEventListener('pointerup', up);
                    node.addEventListener('pointercancel', up);
                });

                forms.forEach(function (form) {
                    form.addEventListener('submit', function (event) {
                        syncPayload();
                        if (mixItems.length === 0 || !validateMix()) {
                            event.preventDefault();
                            if (warning && warning.textContent === '') {
                                warning.textContent = 'Add at least one available furniture item.';
                                warning.classList.add('is-visible');
                            }
                        }
                    });
                });

                if (loadedDesign && Array.isArray(loadedDesign.items)) {
                    loadedDesign.items.forEach(function (item) {
                        const product = productMap.get(item.product_id);
                        if (product) {
                            mixItems.push({
                                product_id: product.id,
                                selected_color: item.selected_color || product.color || 'Available',
                                selected_material: item.selected_material || product.material || 'Available',
                                selected_size: item.selected_size || product.size || 'Confirm with seller',
                                quantity: Math.max(1, Number(item.quantity || 1)),
                                price: Number(item.price || product.price || 0),
                                position_x: Number(item.position_x || 50),
                                position_y: Number(item.position_y || 70),
                                rotation: Number(item.rotation || 0),
                                scale_value: Number(item.scale_value || 1),
                                board_width: Number(product.board_width || 120),
                                layer_order: Number(item.layer_order || mixItems.length + 1)
                            });
                        }
                    });
                }

                window.addEventListener('resize', function () {
                    constrainAllItems();
                    renderMix();
                });

                renderMix();
                const activeCategory = categoryTabs.find(function (tab) {
                    return productRows.some(function (row) {
                        return row.dataset.mixProductCategory === tab.dataset.mixCategory;
                    });
                }) || categoryTabs[0];
                if (activeCategory) {
                    activeCategory.click();
                }
                <?php if ($openCustomizeTab): ?>
                const customizeTab = pageTabs.find(function (tab) {
                    return tab.dataset.mixPageTab === 'customize';
                });
                if (customizeTab) {
                    customizeTab.click();
                }
                <?php endif; ?>
            }

            const renderMessageBadge = function (count) {
                if (!messageNavBadge) {
                    return;
                }

                messageNavBadge.textContent = String(count);
                messageNavBadge.classList.toggle('is-hidden', count <= 0);
                document.title = count > 0 ? '(' + count + ') ' + liveMessageState.baseTitle : liveMessageState.baseTitle;
            };

            const renderAdminMessageAlert = function (count) {
                if (!adminMessageAlert || !adminMessageCount) {
                    return;
                }
                adminMessageAlert.hidden = count <= 0;
                adminMessageCount.textContent = count + ' unread ' + (count === 1 ? 'message' : 'messages');
            };

            renderMessageBadge(liveMessageState.unreadCount);
            renderAdminMessageAlert(<?= (int) $userUnreadAdminMessageCount ?>);

            let userMessagePreviewConfirmed = false;

            if (userMessageImageInput && userMessageImageTrigger) {
                const resetUserMessagePreview = function () {
                    if (userMessageImagePreviewCard) {
                        userMessageImagePreviewCard.classList.remove('is-visible');
                    }
                    if (userMessageImagePreview) {
                        userMessageImagePreview.src = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';
                    }
                    if (userMessageImagePreviewMeta) {
                        userMessageImagePreviewMeta.textContent = 'Choose an image to preview before sending.';
                    }
                    userMessagePreviewConfirmed = false;
                };

                userMessageImageTrigger.addEventListener('click', function () {
                    userMessageImageInput.click();
                });

                userMessageImageInput.addEventListener('change', function () {
                    const hasFile = userMessageImageInput.files && userMessageImageInput.files.length > 0;
                    userMessageImageTrigger.classList.toggle('has-file', !!hasFile);

                    if (userMessageImageStatus) {
                        userMessageImageStatus.textContent = hasFile ? userMessageImageInput.files[0].name : 'Send text, image, or both.';
                    }

                    userMessagePreviewConfirmed = false;

                    if (!hasFile) {
                        resetUserMessagePreview();
                        return;
                    }

                    const file = userMessageImageInput.files[0];
                    const reader = new FileReader();

                    reader.addEventListener('load', function (event) {
                        if (userMessageImagePreview && typeof event.target?.result === 'string') {
                            userMessageImagePreview.src = event.target.result;
                        }
                        if (userMessageImagePreviewMeta) {
                            userMessageImagePreviewMeta.textContent = file.name + ' • ' + Math.max(1, Math.round(file.size / 1024)) + ' KB';
                        }
                        if (userMessageImagePreviewCard) {
                            userMessageImagePreviewCard.classList.add('is-visible');
                        }
                    });

                    reader.readAsDataURL(file);
                });
            }

            if (userChatComposer && userMessageImageInput && userMessageSendPreviewModal) {
                const hideUserMessageSendPreview = function () {
                    userMessageSendPreviewModal.classList.remove('is-visible');
                    userMessageSendPreviewModal.setAttribute('aria-hidden', 'true');
                };

                const showUserMessageSendPreview = function () {
                    const hasFile = userMessageImageInput.files && userMessageImageInput.files.length > 0;
                    if (!hasFile) {
                        return false;
                    }

                    const file = userMessageImageInput.files[0];
                    if (userMessageSendPreviewImage && userMessageImagePreview) {
                        userMessageSendPreviewImage.src = userMessageImagePreview.src;
                    }
                    if (userMessageSendPreviewMeta) {
                        userMessageSendPreviewMeta.textContent = file.name + ' • ' + Math.max(1, Math.round(file.size / 1024)) + ' KB';
                    }

                    userMessageSendPreviewModal.classList.add('is-visible');
                    userMessageSendPreviewModal.setAttribute('aria-hidden', 'false');
                    return true;
                };

                userChatComposer.addEventListener('submit', function (event) {
                    const hasFile = userMessageImageInput.files && userMessageImageInput.files.length > 0;
                    if (!hasFile || userMessagePreviewConfirmed) {
                        userMessagePreviewConfirmed = false;
                        return;
                    }

                    event.preventDefault();
                    showUserMessageSendPreview();
                });

                if (userMessageSendPreviewCancel) {
                    userMessageSendPreviewCancel.addEventListener('click', function () {
                        hideUserMessageSendPreview();
                    });
                }

                if (userMessageSendPreviewConfirm) {
                    userMessageSendPreviewConfirm.addEventListener('click', function () {
                        userMessagePreviewConfirmed = true;
                        hideUserMessageSendPreview();
                        userChatComposer.requestSubmit();
                    });
                }

                userMessageSendPreviewModal.addEventListener('click', function (event) {
                    if (event.target === userMessageSendPreviewModal) {
                        hideUserMessageSendPreview();
                    }
                });
            }

            fields.forEach(function (field) {
                field.addEventListener('focus', function () { hasFocusedField = true; });
                field.addEventListener('blur', function () { hasFocusedField = false; });
                field.addEventListener('input', function () { hasPendingChanges = true; });
                field.addEventListener('change', function () { hasPendingChanges = true; });
            });

            quantityButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    if (!quantityInput || quantityInput.disabled) {
                        return;
                    }

                    const step = Number(button.getAttribute('data-qty-change') || '0');
                    const min = Number(quantityInput.min || '1');
                    const max = Number(quantityInput.max || quantityInput.value || '1');
                    const nextValue = Math.min(max, Math.max(min, Number(quantityInput.value || min) + step));
                    quantityInput.value = String(nextValue);
                    hasPendingChanges = true;
                });
            });

            if (heroSlides.length > 1) {
                const renderHero = function (index) {
                    heroIndex = (index + heroSlides.length) % heroSlides.length;

                    heroSlides.forEach(function (slide, slideIndex) {
                        slide.classList.toggle('is-active', slideIndex === heroIndex);
                    });

                    heroDots.forEach(function (dot, dotIndex) {
                        dot.classList.toggle('active', dotIndex === heroIndex);
                    });
                };

                window.setInterval(function () {
                    renderHero(heroIndex + 1);
                }, 3000);
            }

            modalOpeners.forEach(function (button) {
                button.addEventListener('click', function () {
                    const modalId = button.getAttribute('data-open-modal');
                    const modal = modalId ? document.getElementById(modalId) : null;
                    if (modal) {
                        modal.classList.add('is-open');
                    }
                });
            });

            modalClosers.forEach(function (button) {
                button.addEventListener('click', function () {
                    const modalId = button.getAttribute('data-close-modal');
                    const modal = modalId ? document.getElementById(modalId) : null;
                    if (modal) {
                        modal.classList.remove('is-open');
                    }
                });
            });

            profileModals.forEach(function (modal) {
                modal.addEventListener('click', function (event) {
                    if (event.target === modal) {
                        modal.classList.remove('is-open');
                    }
                });
            });

            zoomImageButtons.forEach(function (button) {
                button.addEventListener('click', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    if (button.closest('[data-mix-builder]')) {
                        return;
                    }
                    if (!imageLightboxModal || !imageLightboxPreview || !imageLightboxLabel) {
                        return;
                    }

                    imageLightboxPreview.src = button.dataset.zoomImage || '';
                    imageLightboxPreview.alt = button.dataset.zoomAlt || 'Product image';
                    imageLightboxLabel.textContent = button.dataset.zoomAlt || 'Product image';
                    imageLightboxModal.classList.add('is-open');
                });
            });

            const messageIconButtons = Array.from(document.querySelectorAll('.message-icon-btn'));
            messageIconButtons.forEach(function (button) {
                button.addEventListener('click', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    const targetId = button.dataset.toggleTarget;
                    if (!targetId) {
                        return;
                    }
                    const targetDetails = document.getElementById(targetId);
                    if (!targetDetails) {
                        return;
                    }
                    targetDetails.toggleAttribute('open');
                });
            });

            if (notice) {
                window.setTimeout(function () {
                    notice.classList.add('is-hiding');
                    window.setTimeout(function () {
                        if (notice.parentNode) {
                            notice.parentNode.removeChild(notice);
                        }
                    }, 220);
                }, 2400);
            }

            if (shouldAutoRefresh) {
                setInterval(function () {
                    if (!hasFocusedField && !hasPendingChanges) {
                        window.location.reload();
                    }
                }, 8000);
            }

            if (liveMessageState.enabled) {
                window.setInterval(function () {
                    window.fetch(liveMessageState.endpoint, {
                        headers: { 'Accept': 'application/json' },
                        cache: 'no-store'
                    })
                        .then(function (response) { return response.ok ? response.json() : null; })
                        .then(function (payload) {
                            if (!payload) {
                                return;
                            }

                            const nextUnreadCount = Number(payload.unread_count || 0);
                            renderMessageBadge(nextUnreadCount);
                            renderAdminMessageAlert(Number(payload.admin_unread_count || 0));

                            if (payload.signature && payload.signature !== liveMessageState.signature) {
                                liveMessageState.unreadCount = nextUnreadCount;

                                if (liveMessageState.isMessagesView) {
                                    if (!hasFocusedField && !hasPendingChanges) {
                                        window.location.reload();
                                    }
                                } else {
                                    liveMessageState.signature = payload.signature;
                                }
                            }
                        })
                        .catch(function () {});
                }, 2000);
            }
        }());
    </script>
    <script>
        document.querySelectorAll('.order-disclosure[data-order-id]').forEach(function (orderCard) {
            orderCard.addEventListener('toggle', function () {
                if (!orderCard.open) {
                    return;
                }

                const orderId = orderCard.dataset.orderId || '';
                const orderType = orderCard.dataset.orderType || 'normal';
                const currentParams = new URLSearchParams(window.location.search);
                if (currentParams.get('order') === orderId) {
                    return;
                }

                const targetParams = new URLSearchParams({
                    view: 'orders',
                    order_type: orderType,
                    order: orderId
                });
                window.location.href = 'user.php?' + targetParams.toString();
            });
        });
    </script>
    <script src="language.js"></script>
</body>
</html>
