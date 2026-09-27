<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'session.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'db.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'backup.php';

if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header('Location: index.php');
    exit;
}

if (!isset($_SESSION['admin'])) {
    header('Location: index.php');
    exit;
}

$admin = $_SESSION['admin'];
$restoreDone = !empty($_SESSION['admin_restore_done']);
unset($_SESSION['admin_restore_done']);
$_SESSION['backup_csrf'] = $_SESSION['backup_csrf'] ?? bin2hex(random_bytes(16));
$uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
$uploadWebPath = 'uploads';
$notice = (string) ($_SESSION['admin_backup_notice'] ?? '');
unset($_SESSION['admin_backup_notice']);
$noticeType = 'success';
$editingProductId = trim($_GET['edit'] ?? '');
$showAddProductForm = trim($_GET['add'] ?? '') === '1';
$selectedStatPopup = trim($_GET['statpopup'] ?? '');
$selectedChatEmail = trim($_GET['chat'] ?? '');
$selectedOrderId = trim($_GET['order'] ?? '');
$selectedCustomizationId = trim((string) ($_GET['customization'] ?? ''));
$showProfileSettingsPopup = trim($_GET['settings_popup'] ?? '') === '1';
$showAdminEditPopup = trim($_GET['edit_popup'] ?? '') === '1';
$categoryOptions = [];
$materialOptions = [];
$productFormDraft = null;
$adminProfileDraft = null;

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

function categoryIconKey(string $category): string
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
    if (str_contains($category, 'table') || str_contains($category, 'desk') || str_contains($category, 'dining')) {
        return 'table';
    }
    return 'furniture';
}

function renderCategoryIcon(string $categoryName): string
{
    $icon = categoryIconKey($categoryName);

    if ($icon === 'sofa') {
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v4H5v-4Z"/><path d="M7 10V8a2 2 0 0 1 2-2h1v4"/><path d="M14 6h1a2 2 0 0 1 2 2v2"/><path d="M4 16h16"/><path d="M6 16v2"/><path d="M18 16v2"/></svg>';
    }
    if ($icon === 'chair') {
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5h8v6H8z"/><path d="M7 11h10v5H7z"/><path d="M9 16v3"/><path d="M15 16v3"/><path d="M6 16h12"/></svg>';
    }
    if ($icon === 'bed') {
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 11h16v5H4z"/><path d="M4 8h7a2 2 0 0 1 2 2v1H4V8Z"/><path d="M4 16v2"/><path d="M20 16v2"/></svg>';
    }
    if ($icon === 'cabinet') {
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="7" y="4" width="10" height="16" rx="1.5"/><path d="M12 4v16"/><circle cx="10" cy="10" r=".7" fill="currentColor"/><circle cx="14" cy="10" r=".7" fill="currentColor"/></svg>';
    }
    if ($icon === 'table') {
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 7h14v4H5z"/><path d="M9 11v7"/><path d="M15 11v7"/><path d="M7 18h10"/></svg>';
    }

    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 8h14v8H5z"/><path d="M8 16v2"/><path d="M16 16v2"/><path d="M8 8V6h8v2"/></svg>';
}

function findProductById(array $products, string $productId): ?array
{
    foreach ($products as $product) {
        if (($product['id'] ?? '') === $productId) {
            return $product;
        }
    }

    return null;
}

function adminProductSize(array $product): string
{
    if (preg_match('/^\s*(?:size|dimension|dimensions)\s*:?\s*([^\n]+)/im', (string) ($product['description'] ?? ''), $matches)) {
        $size = trim((string) ($matches[1] ?? ''));
        return $size !== '' ? $size : 'No size specified';
    }

    return 'No size specified';
}

function findUserByEmail(array $users, string $email): ?array
{
    foreach ($users as $user) {
        if (strtolower((string) ($user['email'] ?? '')) === strtolower($email)) {
            return $user;
        }
    }

    return null;
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

function findCustomizationRequestById(array $requests, string $requestId): array
{
    foreach ($requests as $index => $request) {
        if (($request['id'] ?? '') === $requestId) {
            return [$request, $index];
        }
    }

    return [null, null];
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

function uploadProductImage(array $file, string $uploadDir, string $uploadWebPath, string $prefix = 'prd_img_'): array
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
    $filename = uniqid($prefix, true) . '.' . $savedExtension;
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
    $filename = uniqid('adm_img_', true) . '.' . $savedExtension;
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

function uploadCategoryIcon(array $file, string $uploadDir, string $uploadWebPath): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return '';
    }

    $tmpName = $file['tmp_name'] ?? '';
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return '';
    }

    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];
    if (!in_array($extension, $allowed, true)) {
        return '';
    }

    if (!ensureUploadDirectory($uploadDir)) {
        return '';
    }

    $filename = uniqid('cat_icon_', true) . '.' . $extension;
    $target = $uploadDir . DIRECTORY_SEPARATOR . $filename;

    if (!persistUploadedFile($tmpName, $target)) {
        return '';
    }

    return $uploadWebPath . '/' . $filename;
}

function deleteUploadedAsset(string $relativePath): void
{
    if ($relativePath === '') {
        return;
    }

    $absolutePath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (is_file($absolutePath)) {
        unlink($absolutePath);
    }
}

function moneyValue(string $value): float
{
    return (float) preg_replace('/[^\d.]/', '', $value);
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

if (isset($_GET['download_saved_backup'])) {
    $backupName = (string) $_GET['download_saved_backup'];
    $backupPath = appBackupPath($backupName);
    if ($backupPath === null) {
        http_response_code(404);
        exit('Backup file not found.');
    }
    $backupStream = fopen($backupPath, 'rb');
    if ($backupStream === false) {
        http_response_code(500);
        exit('Backup file could not be opened.');
    }
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $backupName . '"');
    header('Content-Length: ' . filesize($backupPath));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    fpassthru($backupStream);
    fclose($backupStream);
    exit;
}
$store = appLoadStore();
if (($_GET['download_backup'] ?? '') === '1') {
    $backupJson = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    $temporaryFile = tempnam(sys_get_temp_dir(), 'rn_backup_');
    $archive = new ZipArchive();
    if ($backupJson === false || $temporaryFile === false || $archive->open($temporaryFile, ZipArchive::OVERWRITE) !== true) {
        if ($temporaryFile !== false && is_file($temporaryFile)) {
            unlink($temporaryFile);
        }
        http_response_code(500);
        exit('Could not create the backup file.');
    }

    $archive->addFromString('data.json', $backupJson);
    $archive->addFromString('README.txt', "RN Furniture backup\n\nContains the application source, data.json, and uploaded files. Keep this archive private because data.json contains account and order information. Restore data.json into the application database with a compatible importer.\n");
    foreach (['php', 'css', 'js'] as $extension) {
        foreach (glob(__DIR__ . DIRECTORY_SEPARATOR . '*.' . $extension) ?: [] as $sourceFile) {
            if (is_file($sourceFile) && basename($sourceFile) !== 'config.php') {
                $archive->addFile($sourceFile, 'app/' . basename($sourceFile));
            }
        }
    }
    foreach (glob($uploadDir . DIRECTORY_SEPARATOR . '*') ?: [] as $uploadFile) {
        if (is_file($uploadFile) && !is_link($uploadFile)) {
            $archive->addFile($uploadFile, 'uploads/' . basename($uploadFile));
        }
    }
    $archiveSaved = $archive->close();
    clearstatcache(true, $temporaryFile);
    if (!$archiveSaved || !is_file($temporaryFile) || filesize($temporaryFile) === 0) {
        if (is_file($temporaryFile)) {
            unlink($temporaryFile);
        }
        http_response_code(500);
        exit('Could not finish the backup file.');
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="rn-furniture-backup-' . date('Y-m-d-His') . '.zip"');
    header('Content-Length: ' . filesize($temporaryFile));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($temporaryFile);
    unlink($temporaryFile);
    exit;
}
$categoryOptions = $store['settings']['categories'];
$materialOptions = $store['settings']['materials'];
$sliderSettings = normalizeSliderSettings($store['settings']['slider'] ?? []);
$categoryNames = array_map(function ($category) {
    return (string) ($category['name'] ?? '');
}, $categoryOptions);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_backup' || $action === 'restore_backup') {
        try {
            if (!hash_equals((string) $_SESSION['backup_csrf'], (string) ($_POST['backup_csrf'] ?? ''))) {
                throw new RuntimeException('Backup request expired. Reload Settings and try again.');
            }
            if ($action === 'create_backup') {
                appCreateDatabaseBackup(appDb());
                $_SESSION['admin_backup_notice'] = 'Backup created successfully.';
                header('Location: admin.php?section=profile&settings_popup=1');
                exit;
            }
            $backupName = (string) ($_POST['backup_name'] ?? '');
            if (appBackupPath($backupName) === null) {
                throw new RuntimeException('Backup file not found.');
            }
            if (str_ends_with($backupName, '.sql')) {
                appCreateDatabaseBackup(appDb());
                appRestoreDatabaseBackup(appDb(), $backupName);
            } else {
                appCreateBackupArchive($store, $uploadDir);
                appRestoreBackupArchive($backupName, $uploadDir);
            }
            $_SESSION['admin_restore_done'] = true;
            header('Location: admin.php?section=profile&settings_popup=1');
            exit;
        } catch (Throwable $error) {
            $notice = $error->getMessage();
            $noticeType = 'error';
            $showProfileSettingsPopup = true;
        }
    }

    if ($action === 'delete_backup') {
        $backupName = (string) ($_POST['backup_name'] ?? '');
        $backupPath = appBackupPath($backupName);
        if (!hash_equals((string) $_SESSION['backup_csrf'], (string) ($_POST['backup_csrf'] ?? ''))) {
            $notice = 'Delete request expired. Reload Settings and try again.';
            $noticeType = 'error';
        } elseif ($backupPath === null || !unlink($backupPath)) {
            $notice = 'Backup file could not be deleted.';
            $noticeType = 'error';
        } else {
            $_SESSION['admin_backup_notice'] = 'Backup deleted.';
            header('Location: admin.php?section=profile&settings_popup=1');
            exit;
        }
        $showProfileSettingsPopup = true;
    }

    if ($action === 'restore_deleted_record') {
        if (!hash_equals((string) $_SESSION['backup_csrf'], (string) ($_POST['backup_csrf'] ?? ''))) {
            $notice = 'Restore request expired. Reload Settings and try again.';
            $noticeType = 'error';
            $showProfileSettingsPopup = true;
        } elseif (!appRestoreDeletedRecord($store, trim((string) ($_POST['archive_id'] ?? '')))) {
            $notice = 'This item could not be restored. It may already exist.';
            $noticeType = 'error';
            $showProfileSettingsPopup = true;
        } else {
            appSaveStore($store);
            $_SESSION['admin_restore_done'] = true;
            header('Location: admin.php?section=profile&settings_popup=1');
            exit;
        }
    }

    if ($action === 'admin_send_message') {
        $recipientEmail = trim($_POST['recipient_email'] ?? '');
        $recipientName = trim($_POST['recipient_name'] ?? '');
        $productId = trim($_POST['product_id'] ?? '');
        $message = appDisplayChatMessage(trim((string) ($_POST['message'] ?? '')));
        $customizationMessageId = trim((string) ($_POST['customization_id'] ?? ''));
        $customizationRecipientValid = true;
        if ($customizationMessageId !== '') {
            [$messageRequest] = findCustomizationRequestById($store['customization_requests'] ?? [], $customizationMessageId);
            $customizationRecipientValid = $messageRequest !== null && strcasecmp((string) ($messageRequest['customer_email'] ?? ''), $recipientEmail) === 0;
        }
        $chatImageUpload = ['path' => null, 'error' => ''];
        if (filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            $chatImageUpload = uploadProductImage($_FILES['message_image'] ?? [], $uploadDir, $uploadWebPath, 'chat_img_');
        }
        $chatImagePath = trim((string) ($chatImageUpload['path'] ?? ''));

        if ($recipientEmail === '' || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) || !$customizationRecipientValid) {
            $notice = 'Select a valid user conversation first.';
            $noticeType = 'error';
        } elseif (($chatImageUpload['error'] ?? '') !== '') {
            $notice = (string) $chatImageUpload['error'];
            $noticeType = 'error';
        } elseif ($message === '' && $chatImagePath === '') {
            $notice = 'Enter a message or attach an image.';
            $noticeType = 'error';
        } else {
            $store['messages'] = isset($store['messages']) && is_array($store['messages']) ? $store['messages'] : [];
            $reply = appCreateMessageRecord(
                (string) ($admin['name'] ?? 'Admin'),
                (string) ($admin['email'] ?? 'admin@demo.local'),
                $recipientEmail,
                $productId,
                $message . ($customizationMessageId !== '' ? ' (' . $customizationMessageId . ')' : ''),
                1,
                0
            );
            $reply['image_path'] = $chatImagePath;
            array_unshift($store['messages'], $reply);
            appSaveStore($store);
            header('Location: ' . ($customizationMessageId !== '' ? 'admin.php?section=customizations&customization=' . urlencode($customizationMessageId) : 'admin.php?section=messages&chat=' . urlencode($recipientEmail)));
            exit;
        }
    }

    if ($action === 'delete_chat_thread') {
        $threadEmail = trim($_POST['thread_email'] ?? '');

        if ($threadEmail === '' || !filter_var($threadEmail, FILTER_VALIDATE_EMAIL)) {
            $notice = 'Select a valid chat thread first.';
            $noticeType = 'error';
        } elseif (count(array_filter(($store['messages'] ?? []), function ($message) use ($threadEmail) {
            return ($message['to'] ?? '') === 'admin'
                && strcasecmp((string) ($message['from_email'] ?? ''), $threadEmail) === 0
                && empty($message['read_by_admin']);
        })) > 0) {
            $notice = 'Open and read this conversation before moving it to Deleted Chats.';
            $noticeType = 'error';
        } else {
            foreach (($store['messages'] ?? []) as $index => $message) {
                $fromEmail = strtolower((string) ($message['from_email'] ?? ''));
                $recipient = strtolower((string) ($message['to'] ?? ''));
                $normalizedThreadEmail = strtolower($threadEmail);
                if ($fromEmail === $normalizedThreadEmail || $recipient === $normalizedThreadEmail) {
                    $store['messages'][$index]['deleted_by_admin'] = 1;
                }
            }
            appSaveStore($store);
            header('Location: admin.php?section=messages');
            exit;
        }
    }

    if ($action === 'restore_chat_thread') {
        $threadEmail = strtolower(trim((string) ($_POST['thread_email'] ?? '')));
        if (!filter_var($threadEmail, FILTER_VALIDATE_EMAIL)) {
            $notice = 'Select a valid chat thread first.';
            $noticeType = 'error';
        } else {
            $restored = false;
            foreach (($store['messages'] ?? []) as $index => $message) {
                if (!empty($message['deleted_by_admin']) && (strtolower((string) ($message['from_email'] ?? '')) === $threadEmail || strtolower((string) ($message['to'] ?? '')) === $threadEmail)) {
                    $store['messages'][$index]['deleted_by_admin'] = 0;
                    $restored = true;
                }
            }
            if ($restored) {
                appSaveStore($store);
                $_SESSION['admin_restore_done'] = true;
                header('Location: admin.php?section=messages&chat=' . urlencode($threadEmail));
                exit;
            }
            $notice = 'This conversation could not be restored.';
            $noticeType = 'error';
            $showProfileSettingsPopup = true;
        }
    }

    if ($action === 'save_product') {
        $productId = trim($_POST['product_id'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $material = trim($_POST['material'] ?? '');
        $price = (float) ($_POST['price'] ?? 0);
        $stock = (int) ($_POST['stock'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $existingImage = trim($_POST['existing_image'] ?? '');
        $normalizedName = preg_replace('/\s+/', ' ', $name);
        $duplicateName = false;
        foreach ($store['products'] as $existingProduct) {
            if ((string) ($existingProduct['id'] ?? '') !== $productId
                && strcasecmp((string) preg_replace('/\s+/', ' ', trim((string) ($existingProduct['name'] ?? ''))), (string) $normalizedName) === 0) {
                $duplicateName = true;
                break;
            }
        }
        $imageUpload = uploadProductImage($_FILES['image'] ?? [], $uploadDir, $uploadWebPath);
        $imagePath = $imageUpload['path'] ?? null;
        $imageError = trim((string) ($imageUpload['error'] ?? ''));
        $showAddProductForm = $productId === '';
        $productFormDraft = [
            'id' => $productId,
            'name' => $name,
            'category' => $category,
            'material' => $material,
            'price' => $price > 0 ? number_format($price, 2, '.', '') : '',
            'stock' => (string) $stock,
            'description' => $description,
            'image' => $existingImage,
        ];

        if ($duplicateName) {
            if ($imagePath !== null) {
                @unlink(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $imagePath));
            }
            $notice = 'A product with this name already exists.';
            $noticeType = 'error';
        } elseif ($imageError !== '') {
            $notice = $imageError;
            $noticeType = 'error';
        } elseif ($name === '' || $category === '' || $material === '' || $price <= 0 || $stock < 0) {
            $notice = 'Complete all product fields with valid values.';
            $noticeType = 'error';
        } else {
            $record = [
                'id' => $productId !== '' ? $productId : uniqid('prd_', true),
                'name' => $name,
                'category' => $category,
                'material' => $material,
                'price' => number_format($price, 2, '.', ''),
                'stock' => $stock,
                'description' => $description,
                'image' => $imagePath !== null ? $imagePath : $existingImage,
                'updated_at' => date('c'),
            ];

            $updated = false;
            foreach ($store['products'] as $index => $product) {
                if (($product['id'] ?? '') === $record['id']) {
                    $record['created_at'] = $product['created_at'] ?? date('c');
                    $store['products'][$index] = $record;
                    $updated = true;
                    break;
                }
            }

            if (!$updated) {
                $record['created_at'] = date('c');
                array_unshift($store['products'], $record);
            }

            appSaveStore($store);
            header('Location: admin.php?section=products');
            exit;
        }
    }

    if ($action === 'delete_product') {
        $productId = trim($_POST['product_id'] ?? '');
        foreach ($store['products'] as $product) {
            if (($product['id'] ?? '') === $productId) {
                appArchiveDeletedRecord($store, 'product', $product);
                break;
            }
        }
        $store['products'] = array_values(array_filter($store['products'], function ($product) use ($productId) {
            return ($product['id'] ?? '') !== $productId;
        }));
        appSaveStore($store);
        header('Location: admin.php?section=products');
        exit;
    }

    if ($action === 'update_order_status') {
        $orderId = trim($_POST['order_id'] ?? '');
        $status = trim($_POST['status'] ?? 'New Order');
        $toneMap = [
            'New Order' => 'pending',
            'Accepted' => 'accepted',
            'Payment Received' => 'completed',
            'Complete' => 'completed',
            'Declined' => 'declined',
        ];
        $updated = false;
        foreach ($store['orders'] as $index => $order) {
            if (($order['id'] ?? '') === $orderId) {
                $currentStatus = (string) ($order['status'] ?? 'New Order');
                $allowed = in_array($currentStatus, ['New Order', 'Pending'], true)
                    ? ['Accepted', 'Declined']
                    : (in_array($currentStatus, ['Accepted', 'Payment Received'], true) ? ['Complete'] : []);
                if (!in_array($status, $allowed, true)) {
                    break;
                }
                $store['orders'][$index]['status'] = $status;
                $store['orders'][$index]['tone'] = $toneMap[$status] ?? 'pending';
                $store['orders'][$index]['updated_at'] = date('c');
                $updated = true;
                break;
            }
        }
        if ($updated) {
            appSaveStore($store);
        }
        $redirectFilterMap = [
            'New Order' => 'new_order',
            'Accepted' => 'accepted',
            'Payment Received' => 'payment_received',
            'Complete' => 'completed',
            'Declined' => 'declined',
        ];
        $redirectFilter = $redirectFilterMap[$status] ?? 'new_order';
        header('Location: admin.php?section=orders&order_filter=' . urlencode($redirectFilter) . '&order=' . urlencode($orderId));
        exit;
    }

    if ($action === 'send_customization_quote') {
        $requestId = trim((string) ($_POST['request_id'] ?? ''));
        $quotationPrice = (float) ($_POST['quotation_price'] ?? 0);
        $downPayment = (float) ($_POST['down_payment'] ?? 0);
        $estimatedDate = trim((string) ($_POST['estimated_completion_date'] ?? ''));
        $adminNotes = trim((string) ($_POST['admin_notes'] ?? ''));
        [$request, $requestIndex] = findCustomizationRequestById($store['customization_requests'] ?? [], $requestId);

        if ($request === null || !in_array((string) ($request['status'] ?? ''), ['Pending', 'Quotation Sent'], true)) {
            $notice = 'Customization request could not be quoted.';
            $noticeType = 'error';
        } elseif ($quotationPrice <= 0 || $downPayment <= 0 || $downPayment > $quotationPrice || $estimatedDate === '') {
            $notice = 'Enter a valid quotation, down payment, and estimated completion date.';
            $noticeType = 'error';
        } else {
            $store['customization_requests'][$requestIndex]['quotation_price'] = number_format($quotationPrice, 2, '.', '');
            $store['customization_requests'][$requestIndex]['down_payment'] = number_format($downPayment, 2, '.', '');
            $store['customization_requests'][$requestIndex]['estimated_completion_date'] = $estimatedDate;
            $store['customization_requests'][$requestIndex]['admin_notes'] = $adminNotes;
            $store['customization_requests'][$requestIndex]['status'] = 'Quotation Sent';
            $store['customization_requests'][$requestIndex]['tone'] = customizationTone('Quotation Sent');
            $store['customization_requests'][$requestIndex]['updated_at'] = date('c');
            $quoteMessage = appCreateMessageRecord(
                (string) ($admin['name'] ?? 'Admin'),
                (string) ($admin['email'] ?? 'admin@demo.local'),
                (string) ($request['customer_email'] ?? ''),
                (string) ($request['product_id'] ?? ''),
                'Quotation sent for customization: ' . (string) ($request['product_name'] ?? 'Furniture item') . ' (' . $requestId . '). Total: P' . number_format($quotationPrice, 2) . '. Down payment before work: P' . number_format($downPayment, 2) . '. Accept or cancel in My Orders.' . ($adminNotes !== '' ? ' Admin reply: ' . $adminNotes : ''),
                1,
                0
            );
            array_unshift($store['messages'], $quoteMessage);
            appSaveStore($store);
            header('Location: admin.php?section=customizations&customization=' . urlencode($requestId));
            exit;
        }

        $_GET['section'] = 'customizations';
        $_GET['customization'] = $requestId;
    }

    if ($action === 'update_customization_status') {
        $requestId = trim((string) ($_POST['request_id'] ?? ''));
        $status = trim((string) ($_POST['status'] ?? ''));
        $allowedStatuses = ['Approved', 'Ongoing', 'Ready', 'Completed', 'Cancelled', 'Unclaimed'];
        [$request, $requestIndex] = findCustomizationRequestById($store['customization_requests'] ?? [], $requestId);

        if ($request === null || !in_array($status, $allowedStatuses, true)) {
            $notice = 'Customization status could not be updated.';
            $noticeType = 'error';
        } elseif (in_array((string) ($request['status'] ?? ''), ['Completed', 'Cancelled', 'Unclaimed'], true)) {
            $notice = 'This customization request is already closed.';
            $noticeType = 'error';
        } elseif ($status === (string) ($request['status'] ?? '')) {
            $notice = 'Choose a new progress status.';
            $noticeType = 'error';
        } elseif ($status === 'Approved' && (string) ($request['status'] ?? '') !== 'Quotation Sent') {
            $notice = 'Send a quotation before approving this request.';
            $noticeType = 'error';
        } elseif ($status === 'Ongoing' && ((string) ($request['status'] ?? '') !== 'Down Payment Paid' || empty($request['payment_confirmed']))) {
            $notice = 'Verify the down payment before moving this request to Ongoing.';
            $noticeType = 'error';
        } elseif ($status === 'Ready' && !in_array((string) ($request['status'] ?? ''), ['Ongoing', 'In Production'], true)) {
            $notice = 'This request must be ongoing before it can be marked ready.';
            $noticeType = 'error';
        } elseif ($status === 'Completed' && (string) ($request['status'] ?? '') !== 'Ready') {
            $notice = 'This request is not ready for that status yet.';
            $noticeType = 'error';
        } else {
            $store['customization_requests'][$requestIndex]['status'] = $status;
            $store['customization_requests'][$requestIndex]['tone'] = customizationTone($status);
            $store['customization_requests'][$requestIndex]['updated_at'] = date('c');
            array_unshift($store['messages'], appCreateMessageRecord(
                (string) ($admin['name'] ?? 'Admin'),
                (string) ($admin['email'] ?? 'admin@demo.local'),
                (string) ($request['customer_email'] ?? ''),
                (string) ($request['product_id'] ?? ''),
                'Customization update: ' . (string) ($request['product_name'] ?? 'Furniture item') . ' (' . $requestId . ') is now ' . ($status === 'Completed' ? 'Finished' : $status) . '. Track progress in My Orders.',
                1,
                0
            ));
            appSaveStore($store);
            header('Location: admin.php?section=customizations&customization=' . urlencode($requestId));
            exit;
        }

        $_GET['section'] = 'customizations';
        $_GET['customization'] = $requestId;
    }

    if ($action === 'confirm_customization_payment') {
        $requestId = trim((string) ($_POST['request_id'] ?? ''));
        [$request, $requestIndex] = findCustomizationRequestById($store['customization_requests'] ?? [], $requestId);
        if ($request === null || !in_array((string) ($request['status'] ?? ''), ['Approved', 'Down Payment Paid'], true)
            || !empty($request['payment_confirmed'])) {
            $notice = 'Payment is not ready for confirmation.';
            $noticeType = 'error';
        } else {
            $store['customization_requests'][$requestIndex]['status'] = 'Down Payment Paid';
            $store['customization_requests'][$requestIndex]['payment_confirmed'] = 1;
            $store['customization_requests'][$requestIndex]['tone'] = 'accepted';
            $store['customization_requests'][$requestIndex]['updated_at'] = date('c');
            array_unshift($store['messages'], appCreateMessageRecord(
                (string) ($admin['name'] ?? 'Admin'),
                (string) ($admin['email'] ?? 'admin@demo.local'),
                (string) ($request['customer_email'] ?? ''),
                (string) ($request['product_id'] ?? ''),
                'Payment received for customization: ' . (string) ($request['product_name'] ?? 'Furniture item') . ' (' . $requestId . ').',
                1,
                0
            ));
            appSaveStore($store);
            header('Location: admin.php?section=customizations&customization=' . urlencode($requestId));
            exit;
        }
        $_GET['section'] = 'customizations';
        $_GET['customization'] = $requestId;
    }

    if ($action === 'confirm_customization_full_payment') {
        $requestId = trim((string) ($_POST['request_id'] ?? ''));
        [$request, $requestIndex] = findCustomizationRequestById($store['customization_requests'] ?? [], $requestId);
        $payment = $request !== null ? appCustomizationPaymentSummary($request) : null;
        if ($request === null || empty($request['payment_confirmed']) || !empty($request['full_payment_confirmed'])
            || $payment['balance'] <= 0 || in_array((string) ($request['status'] ?? ''), ['Cancelled', 'Unclaimed'], true)) {
            $notice = 'Full payment is not ready for confirmation.';
            $noticeType = 'error';
        } else {
            $store['customization_requests'][$requestIndex]['full_payment_confirmed'] = 1;
            $store['customization_requests'][$requestIndex]['updated_at'] = date('c');
            array_unshift($store['messages'], appCreateMessageRecord(
                (string) ($admin['name'] ?? 'Admin'),
                (string) ($admin['email'] ?? 'admin@demo.local'),
                (string) ($request['customer_email'] ?? ''),
                (string) ($request['product_id'] ?? ''),
                'Full payment received for customization: ' . (string) ($request['product_name'] ?? 'Furniture item') . ' (' . $requestId . '). Balance: P0.00.',
                1,
                0
            ));
            appSaveStore($store);
            header('Location: admin.php?section=customizations&customization=' . urlencode($requestId));
            exit;
        }
        $_GET['section'] = 'customizations';
        $_GET['customization'] = $requestId;
    }

    if ($action === 'delete_customization_request_admin') {
        $requestId = trim((string) ($_POST['request_id'] ?? ''));
        [$request, $requestIndex] = findCustomizationRequestById($store['customization_requests'] ?? [], $requestId);
        if (!hash_equals((string) $_SESSION['backup_csrf'], (string) ($_POST['backup_csrf'] ?? ''))) {
            $notice = 'Delete request expired. Reload the page and try again.';
            $noticeType = 'error';
        } elseif ($request === null) {
            $notice = 'Customization request not found.';
            $noticeType = 'error';
        } else {
            appArchiveDeletedRecord($store, 'customization', $request);
            array_splice($store['customization_requests'], $requestIndex, 1);
            appSaveStore($store);
            header('Location: admin.php?section=customizations');
            exit;
        }
        $_GET['section'] = 'customizations';
        $_GET['customization'] = $requestId;
    }

    if ($action === 'add_category') {
        $name = trim($_POST['category_name'] ?? '');
        $showProfileSettingsPopup = true;
        $categoryExists = count(array_filter($categoryNames, static function ($existing) use ($name) {
            return strcasecmp($existing, $name) === 0;
        })) > 0;
        if ($name === '') {
            $notice = 'Enter a category name.';
            $noticeType = 'error';
        } elseif (strlen($name) > 120) {
            $notice = 'Category name is too long.';
            $noticeType = 'error';
        } elseif ($categoryExists) {
            $notice = 'Category already exists.';
            $noticeType = 'error';
        } else {
            $store['settings']['categories'][] = [
                'name' => $name,
                'icon' => categoryIconUrl($name),
            ];
            appSaveStore($store);
            header('Location: admin.php?section=profile&settings_popup=1');
            exit;
        }
    }

    if ($action === 'delete_category') {
        $name = trim($_POST['category_name'] ?? '');
        foreach ($store['settings']['categories'] as $item) {
            if (($item['name'] ?? '') === $name) {
                appArchiveDeletedRecord($store, 'category', $item);
                break;
            }
        }
        $store['settings']['categories'] = array_values(array_filter($store['settings']['categories'], function ($item) use ($name) {
            return ($item['name'] ?? '') !== $name;
        }));
        appSaveStore($store);
        header('Location: admin.php?section=profile&settings_popup=1');
        exit;
    }

    if ($action === 'delete_category_icon') {
        $name = trim($_POST['category_name'] ?? '');
        foreach ($store['settings']['categories'] as $index => $item) {
            if (($item['name'] ?? '') === $name) {
                $store['settings']['categories'][$index]['icon'] = '';
                break;
            }
        }
        appSaveStore($store);
        header('Location: admin.php?section=profile&settings_popup=1');
        exit;
    }

    if ($action === 'add_material') {
        $name = trim($_POST['material_name'] ?? '');
        $showProfileSettingsPopup = true;
        $materialExists = count(array_filter($store['settings']['materials'], static function ($existing) use ($name) {
            return strcasecmp((string) $existing, $name) === 0;
        })) > 0;
        if ($name === '') {
            $notice = 'Enter a material name.';
            $noticeType = 'error';
        } elseif (strlen($name) > 120) {
            $notice = 'Material name is too long.';
            $noticeType = 'error';
        } elseif ($materialExists) {
            $notice = 'Material already exists.';
            $noticeType = 'error';
        } else {
            $store['settings']['materials'][] = $name;
            appSaveStore($store);
            header('Location: admin.php?section=profile&settings_popup=1');
            exit;
        }
    }

    if ($action === 'delete_material') {
        $name = trim($_POST['material_name'] ?? '');
        if (in_array($name, $store['settings']['materials'] ?? [], true)) {
            appArchiveDeletedRecord($store, 'material', ['name' => $name]);
        }
        $store['settings']['materials'] = array_values(array_filter($store['settings']['materials'], function ($item) use ($name) {
            return $item !== $name;
        }));
        appSaveStore($store);
        header('Location: admin.php?section=profile&settings_popup=1');
        exit;
    }

    if ($action === 'update_slider_settings') {
        $showProfileSettingsPopup = true;
        $images = array_values(array_filter((array) ($store['settings']['slider']['images'] ?? []), 'is_string'));
        $upload = uploadProductImage($_FILES['slider_image'] ?? [], $uploadDir, $uploadWebPath);
        if ($upload['error'] !== '') {
            $notice = $upload['error'];
            $noticeType = 'error';
        } elseif ($upload['path'] === null) {
            $notice = 'Choose an image to upload.';
            $noticeType = 'error';
        } elseif (count($images) >= 8) {
            deleteUploadedAsset($upload['path']);
            $notice = 'The slider can have up to 8 images.';
            $noticeType = 'error';
        } else {
            $newPath = $upload['path'];
            $newHash = hash_file('sha256', __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $newPath));
            $isDuplicate = false;
            foreach ($images as $imagePath) {
                $existingPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $imagePath);
                if (is_file($existingPath) && hash_file('sha256', $existingPath) === $newHash) {
                    $isDuplicate = true;
                    break;
                }
            }
            if ($isDuplicate) {
                deleteUploadedAsset($newPath);
                $notice = 'This image is already in the slider.';
                $noticeType = 'error';
            } else {
                $images[] = $newPath;
                $store['settings']['slider']['images'] = $images;
                appSaveStore($store);
                header('Location: admin.php?section=profile&settings_popup=1');
                exit;
            }
        }
    }

    if ($action === 'remove_slider_image') {
        $imagePath = (string) ($_POST['slider_image_path'] ?? '');
        $images = (array) ($store['settings']['slider']['images'] ?? []);
        if (in_array($imagePath, $images, true)) {
            $store['settings']['slider']['images'] = array_values(array_filter($images, static function ($image) use ($imagePath) {
                return $image !== $imagePath;
            }));
            appSaveStore($store);
        }
        header('Location: admin.php?section=profile&settings_popup=1');
        exit;
    }

    if ($action === 'update_admin_profile') {
        $updatedName = trim($_POST['admin_name'] ?? '');
        $updatedEmail = trim($_POST['admin_email'] ?? '');
        $updatedPhone = trim($_POST['admin_phone'] ?? '');
        $updatedAddress = trim($_POST['admin_address'] ?? '');
        $adminProfileImageUpload = uploadProfileImage($_FILES['admin_profile_image'] ?? [], $uploadDir, $uploadWebPath);
        $adminProfileImagePath = $adminProfileImageUpload['path'] ?? null;
        $adminProfileImageError = trim((string) ($adminProfileImageUpload['error'] ?? ''));
        $currentAdminRecord = findUserByEmail($store['users'] ?? [], (string) ($admin['email'] ?? ''));
        $adminProfileDraft = [
            'name' => $updatedName,
            'email' => $updatedEmail,
            'phone' => $updatedPhone,
            'address' => $updatedAddress,
        ];
        $showAdminEditPopup = true;

        if ($updatedName === '' || $updatedEmail === '') {
            $notice = 'Complete the admin profile fields.';
            $noticeType = 'error';
        } elseif ($adminProfileImageError !== '') {
            $notice = $adminProfileImageError;
            $noticeType = 'error';
        } elseif (!filter_var($updatedEmail, FILTER_VALIDATE_EMAIL)) {
            $notice = 'Enter a valid admin email address.';
            $noticeType = 'error';
        } elseif ($currentAdminRecord === null) {
            $notice = 'Admin account not found.';
            $noticeType = 'error';
        } else {
            $emailTaken = false;
            foreach (($store['users'] ?? []) as $user) {
                if (
                    strtolower((string) ($user['email'] ?? '')) === strtolower($updatedEmail) &&
                    strtolower((string) ($user['email'] ?? '')) !== strtolower((string) ($currentAdminRecord['email'] ?? ''))
                ) {
                    $emailTaken = true;
                    break;
                }
            }

            if ($emailTaken) {
                $notice = 'That email is already registered.';
                $noticeType = 'error';
            } else {
                foreach ($store['users'] as $index => $user) {
                    if (strtolower((string) ($user['email'] ?? '')) === strtolower((string) ($currentAdminRecord['email'] ?? ''))) {
                        $store['users'][$index]['name'] = $updatedName;
                        $store['users'][$index]['email'] = $updatedEmail;
                        $store['users'][$index]['phone'] = $updatedPhone;
                        $store['users'][$index]['address'] = $updatedAddress;
                        if ($adminProfileImagePath !== null) {
                            $oldProfileImage = trim((string) ($store['users'][$index]['profile_image'] ?? ''));
                            if ($oldProfileImage !== '' && $oldProfileImage !== $adminProfileImagePath) {
                                $oldPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $oldProfileImage);
                                if (is_file($oldPath)) {
                                    @unlink($oldPath);
                                }
                            }
                            $store['users'][$index]['profile_image'] = $adminProfileImagePath;
                        }
                        break;
                    }
                }

                $_SESSION['admin']['name'] = $updatedName;
                $_SESSION['admin']['email'] = $updatedEmail;
                appSaveStore($store);
                header('Location: admin.php?section=profile');
                exit;
            }
        }
    }
}

$section = trim($_GET['section'] ?? 'dashboard');
$orderFilter = trim($_GET['order_filter'] ?? 'new_order');
$selectedCustomizationId = trim((string) ($_GET['customization'] ?? $selectedCustomizationId));
$allowedSections = ['dashboard', 'messages', 'products', 'orders', 'customizations', 'profile', 'settings'];
if (!in_array($section, $allowedSections, true)) {
    $section = 'dashboard';
}
if ($section === 'settings') {
    $section = 'profile';
}

if ($section === 'messages' && $selectedChatEmail !== '') {
    $markedRead = false;
    foreach (($store['messages'] ?? []) as $messageIndex => $message) {
        if (empty($message['deleted_by_admin']) && appMessageCustomizationId($message) === '' && ($message['to'] ?? '') === 'admin' && strcasecmp((string) ($message['from_email'] ?? ''), $selectedChatEmail) === 0 && empty($message['read_by_admin'])) {
            $store['messages'][$messageIndex]['read_by_admin'] = 1;
            $markedRead = true;
        }
    }
    if ($markedRead) {
        appSaveStore($store);
    }
}
if ($section === 'customizations' && $selectedCustomizationId !== '') {
    $markedRead = false;
    foreach (($store['messages'] ?? []) as $messageIndex => $message) {
        if (($message['to'] ?? '') === 'admin' && appMessageCustomizationId($message) === $selectedCustomizationId && empty($message['read_by_admin'])) {
            $store['messages'][$messageIndex]['read_by_admin'] = 1;
            $markedRead = true;
        }
    }
    if ($markedRead) {
        appSaveStore($store);
    }
}
$adminUnreadMessageCount = count(array_filter($store['messages'] ?? [], function ($message) {
    return empty($message['deleted_by_admin']) && appMessageCustomizationId($message) === '' && ($message['to'] ?? '') === 'admin' && empty($message['read_by_admin']);
}));
$adminUnreadCustomizationCount = count(array_filter($store['messages'] ?? [], function ($message) {
    return appMessageCustomizationId($message) !== '' && ($message['to'] ?? '') === 'admin' && empty($message['read_by_admin']);
}));

$products = array_values(array_filter($store['products'], 'appCatalogProductIsVisible'));
$orders = $store['orders'];
$customizationRequests = $store['customization_requests'] ?? [];
$adminRecord = findUserByEmail($store['users'] ?? [], (string) ($admin['email'] ?? ''));
$adminProfileSource = is_array($adminProfileDraft) ? $adminProfileDraft : [];
$adminProfileName = trim((string) ($adminRecord['name'] ?? ($admin['name'] ?? 'Admin'))) !== '' ? trim((string) ($adminRecord['name'] ?? ($admin['name'] ?? 'Admin'))) : 'Admin';
$adminProfileEmail = trim((string) ($adminRecord['email'] ?? ($admin['email'] ?? '')));
$adminProfilePhone = trim((string) ($adminRecord['phone'] ?? ''));
$adminProfileAddress = trim((string) ($adminRecord['address'] ?? ''));
$adminProfileRole = trim((string) ($adminRecord['role'] ?? 'admin'));
$adminProfileImage = trim((string) (($adminRecord['profile_image'] ?? $adminRecord['image'] ?? '')));
$adminProfileInitial = strtoupper(substr($adminProfileName, 0, 1)) ?: 'A';
if ($adminProfileSource !== []) {
    $adminProfileName = trim((string) ($adminProfileSource['name'] ?? $adminProfileName)) !== '' ? trim((string) ($adminProfileSource['name'] ?? $adminProfileName)) : $adminProfileName;
    $adminProfileEmail = trim((string) ($adminProfileSource['email'] ?? $adminProfileEmail));
    $adminProfilePhone = trim((string) ($adminProfileSource['phone'] ?? $adminProfilePhone));
    $adminProfileAddress = trim((string) ($adminProfileSource['address'] ?? $adminProfileAddress));
    $adminProfileInitial = strtoupper(substr($adminProfileName, 0, 1)) ?: 'A';
}
$messages = array_values(array_filter($store['messages'] ?? [], function ($message) {
    return ($message['to'] ?? '') === 'admin' && empty($message['deleted_by_admin']);
}));
$adminEmail = (string) ($admin['email'] ?? 'admin@demo.local');
$allAdminMessages = array_values(array_filter($store['messages'] ?? [], function ($message) use ($adminEmail) {
    return appMessageCustomizationId($message) === '' && (($message['to'] ?? '') === 'admin' || ($message['from_email'] ?? '') === $adminEmail);
}));
$chatThreads = [];
$deletedChatThreads = [];
foreach ($allAdminMessages as $message) {
    $isOutgoing = ($message['from_email'] ?? '') === $adminEmail;
    $threadEmail = $isOutgoing ? (string) ($message['to'] ?? '') : (string) ($message['from_email'] ?? '');
    if ($threadEmail === '' || $threadEmail === 'admin') {
        continue;
    }

    if (!empty($message['deleted_by_admin'])) {
        $targetThreads = &$deletedChatThreads;
    } else {
        $targetThreads = &$chatThreads;
    }

    if (!isset($targetThreads[$threadEmail])) {
        $targetThreads[$threadEmail] = [
            'email' => $threadEmail,
            'name' => $isOutgoing ? ((string) ($message['to'] ?? $threadEmail)) : ((string) ($message['from_name'] ?? 'User')),
            'last_message' => '',
            'last_at' => '',
            'messages' => [],
        ];
    }

    if (!$isOutgoing && trim((string) ($message['from_name'] ?? '')) !== '') {
        $targetThreads[$threadEmail]['name'] = (string) ($message['from_name'] ?? 'User');
    }

    $targetThreads[$threadEmail]['messages'][] = $message;
    $targetThreads[$threadEmail]['last_message'] = trim((string) ($message['message'] ?? '')) !== '' ? (string) ($message['message'] ?? '') : (!empty($message['image_path']) ? '[Image]' : '');
    $targetThreads[$threadEmail]['last_at'] = (string) ($message['created_at'] ?? '');
}
unset($targetThreads);

uasort($chatThreads, function ($left, $right) {
    return strcmp((string) ($right['last_at'] ?? ''), (string) ($left['last_at'] ?? ''));
});
uasort($deletedChatThreads, function ($left, $right) {
    return strcmp((string) ($right['last_at'] ?? ''), (string) ($left['last_at'] ?? ''));
});

$activeChat = ($selectedChatEmail !== '' && isset($chatThreads[$selectedChatEmail])) ? $chatThreads[$selectedChatEmail] : null;
$activeChatMessages = $activeChat !== null ? array_reverse($activeChat['messages']) : [];
$activeChatProduct = null;
if ($activeChat !== null) {
    foreach ($activeChatMessages as $message) {
        $messageProductId = trim((string) ($message['product_id'] ?? ''));
        if ($messageProductId === '') {
            continue;
        }

        $activeChatProduct = findProductById($products, $messageProductId);
        if ($activeChatProduct !== null) {
            break;
        }
    }
}

// Filter orders for the orders section
$filteredOrders = $orders;
if ($section === 'orders') {
    $statusMap = [
        'new' => 'New Order',
        'new_order' => 'New Order',
        'pending' => 'New Order',
        'accepted' => 'Accepted',
        'payment_received' => 'Payment Received',
        'completed' => 'Complete',
        'declined' => 'Declined',
    ];

    $selectedOrderStatus = $statusMap[$orderFilter] ?? 'New Order';
    $filteredOrders = array_values(array_filter($orders, function ($order) use ($selectedOrderStatus) {
        if ($selectedOrderStatus === 'New Order') {
            return in_array(($order['status'] ?? ''), ['New Order', 'Pending'], true);
        }
        if ($selectedOrderStatus === 'Accepted') {
            return in_array(($order['status'] ?? ''), ['Accepted', 'Payment Received'], true);
        }
        return ($order['status'] ?? '') === $selectedOrderStatus;
    }));
}

$selectedOrder = null;
foreach ($filteredOrders as $order) {
    if (($order['id'] ?? '') === $selectedOrderId) {
        $selectedOrder = $order;
        break;
    }
}

$selectedOrderClient = $selectedOrder !== null ? findUserByEmail($store['users'] ?? [], (string) ($selectedOrder['customer_email'] ?? '')) : null;
$selectedOrderClientImage = trim((string) (($selectedOrderClient['profile_image'] ?? $selectedOrderClient['image'] ?? '')));
$selectedOrderClientPhone = trim((string) ($selectedOrderClient['phone'] ?? ''));
$selectedOrderClientAddress = trim((string) ($selectedOrderClient['address'] ?? ''));
$selectedOrderClientEmail = trim((string) ($selectedOrderClient['email'] ?? ($selectedOrder['customer_email'] ?? '')));
$selectedOrderClientName = trim((string) ($selectedOrderClient['name'] ?? ($selectedOrder['customer'] ?? 'Client')));
$selectedOrderClientInitial = strtoupper(substr($selectedOrderClientName, 0, 1)) ?: 'C';

$selectedCustomizationRequest = null;
foreach ($customizationRequests as $request) {
    if (($request['id'] ?? '') === $selectedCustomizationId) {
        $selectedCustomizationRequest = $request;
        break;
    }
}
$selectedCustomizationClient = $selectedCustomizationRequest !== null ? findUserByEmail($store['users'] ?? [], (string) ($selectedCustomizationRequest['customer_email'] ?? '')) : null;
$selectedCustomizationClientPhone = trim((string) ($selectedCustomizationClient['phone'] ?? ($selectedCustomizationRequest['customer_phone'] ?? '')));
$selectedCustomizationMessages = $selectedCustomizationRequest === null ? [] : array_reverse(array_values(array_filter($store['messages'] ?? [], function ($message) use ($selectedCustomizationId) {
    return appMessageCustomizationId($message) === $selectedCustomizationId;
})));
$customizationStatusCounts = [];
foreach ($customizationRequests as $request) {
    $status = (string) ($request['status'] ?? 'Pending');
    $customizationStatusCounts[$status] = ($customizationStatusCounts[$status] ?? 0) + 1;
}

$completedOrders = array_values(array_filter($orders, function ($order) {
    return ($order['status'] ?? '') === 'Complete';
}));
$pendingOrders = array_values(array_filter($orders, function ($order) {
    return in_array(($order['status'] ?? ''), ['New Order', 'Pending'], true);
}));
$acceptedOrders = array_values(array_filter($orders, function ($order) {
    return in_array(($order['status'] ?? ''), ['Accepted', 'Payment Received'], true);
}));
$paymentReceivedOrders = array_values(array_filter($orders, function ($order) {
    return ($order['status'] ?? '') === 'Payment Received';
}));
$completeOrders = array_values(array_filter($orders, function ($order) {
    return ($order['status'] ?? '') === 'Complete';
}));
$declinedOrders = array_values(array_filter($orders, function ($order) {
    return ($order['status'] ?? '') === 'Declined';
}));
$revenue = 0;
foreach ($completedOrders as $order) {
    $revenue += moneyValue((string) ($order['total'] ?? '0'));
}

$stats = [
    ['key' => 'total', 'label' => 'Total Orders', 'value' => (string) count($orders), 'link' => 'Orders', 'tone' => 'navy', 'icon' => 'bag'],
    ['key' => 'revenue', 'label' => 'Total Revenue', 'value' => 'P' . number_format($revenue, 2), 'link' => 'Revenue', 'tone' => 'green', 'icon' => 'wallet'],
    ['key' => 'products', 'label' => 'Total Products', 'value' => (string) count($products), 'link' => 'Inventory', 'tone' => 'orange', 'icon' => 'sofa'],
    ['key' => 'users', 'label' => 'Total User', 'value' => (string) count($store['users']), 'link' => 'Users', 'tone' => 'navy', 'icon' => 'users'],
];

$editingProduct = [
    'id' => '',
    'name' => '',
    'category' => '',
    'material' => '',
    'price' => '',
    'stock' => '0',
    'description' => '',
    'image' => '',
];

if ($editingProductId !== '') {
    foreach ($products as $product) {
        if (($product['id'] ?? '') === $editingProductId) {
            $editingProduct = [
                'id' => $product['id'] ?? '',
                'name' => $product['name'] ?? '',
                'category' => $product['category'] ?? '',
                'material' => $product['material'] ?? '',
                'price' => $product['price'] ?? '',
                'stock' => (string) ($product['stock'] ?? 0),
                'description' => $product['description'] ?? '',
                'image' => $product['image'] ?? '',
            ];
            $section = 'products';
            break;
        }
    }
}

if (is_array($productFormDraft)) {
    $editingProduct = array_merge($editingProduct, $productFormDraft);
}

$showProductForm = $showAddProductForm || $editingProduct['id'] !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Furniture System Admin</title>
    <style>
        :root {
            --surface: #ffffff;
            --bg: #eef3ff;
            --line: #e4eaf7;
            --text: #1f2940;
            --muted: #6f7a92;
            --brand: #12357f;
            --brand-deep: #0f2d6d;
            --navy: #143d8d;
            --orange: #ff8b1f;
            --green: #29a853;
            --violet: #7b55e7;
            --danger: #d74c4c;
            --danger-soft: #fff2f2;
            --soft: #f5f8ff;
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
            min-height: 100vh;
            background: linear-gradient(180deg, #f5f8ff 0%, #e9effa 100%);
            font-family: "Poppins", "Segoe UI", sans-serif;
            color: var(--text);
            overflow-x: hidden;
        }
        a { color: inherit; text-decoration: none; }
        button,
        input,
        select,
        textarea {
            max-width: 100%;
        }
        .shell {
            width: 100%;
            max-width: 430px;
            min-height: 100vh;
            margin: 0 auto;
            background: var(--surface);
            box-shadow: 0 0 0 1px rgba(18, 53, 127, 0.06);
        }
        .topbar {
            padding: 14px 16px 16px;
            color: #fff;
            background: linear-gradient(180deg, var(--brand) 0%, var(--brand-deep) 100%);
        }
        .topbar-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        .topbar strong {
            display: block;
            font-size: 1.05rem;
            line-height: 1.1;
        }
        .topbar span {
            font-size: 0.7rem;
            opacity: 0.8;
        }
        .content {
            padding: 18px clamp(12px, 4vw, 20px) 90px;
            overflow-x: hidden;
        }
        .section-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 14px;
        }
        .section-title h1,
        .section-title h2 {
            margin: 0;
            font-size: 1.2rem;
        }
        .section-title h2 { font-size: 0.98rem; }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }
        .card,
        .panel {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 18px;
            box-shadow: 0 10px 28px rgba(17, 40, 92, 0.05);
        }
        .card {
            padding: 10px 11px;
        }
        .stat-icon {
            width: 30px;
            height: 30px;
            border-radius: 10px;
            display: grid;
            place-items: center;
            margin-bottom: 8px;
            color: #fff;
        }
        .stat-icon svg {
            width: 18px;
            height: 18px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .navy { background: var(--navy); }
        .orange { background: var(--orange); }
        .green { background: var(--green); }
        .violet { background: var(--violet); }
        .danger { background: var(--danger); }
        .stat-label {
            margin: 0 0 6px;
            color: var(--muted);
            font-size: 0.68rem;
            font-weight: 600;
        }
        .stat-value {
            margin: 0 0 4px;
            font-size: 1.05rem;
            font-weight: 700;
            line-height: 1;
        }
        .stat-link {
            color: #4a67d6;
            font-size: 0.66rem;
            font-weight: 600;
        }
        .stat-card-link {
            display: block;
        }
        .ios-tap {
            -webkit-tap-highlight-color: transparent;
            transform: translateZ(0);
            will-change: transform;
            transition: transform 0.18s cubic-bezier(0.2, 0.8, 0.2, 1), box-shadow 0.18s cubic-bezier(0.2, 0.8, 0.2, 1), opacity 0.18s ease, background 0.18s ease;
        }
        .ios-tap.is-pressed {
            transform: scale(0.97);
            box-shadow: 0 10px 24px rgba(17, 40, 92, 0.12);
        }
        .panel { padding: 14px; margin-bottom: 16px; }
        .notice {
            margin-bottom: 14px;
            padding: 10px 12px;
            border-radius: 12px;
            font-size: 0.82rem;
            font-weight: 600;
        }
        .notice.error { background: #fff1f1; color: #9b2f2f; }
        .notice.success { background: #edf9ef; color: #267545; }
        .form-grid {
            display: grid;
            gap: 10px;
        }
        .field label {
            display: block;
            margin-bottom: 5px;
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--muted);
        }
        .field input,
        .field textarea,
        .field select {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 11px 12px;
            font: inherit;
            font-size: max(16px, 0.88rem);
            background: #fff;
        }
        .field textarea {
            min-height: 88px;
            resize: vertical;
        }
        .two-col {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }
        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .actions.wrap {
            flex-wrap: wrap;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            border: 0;
            border-radius: 12px;
            padding: 11px 14px;
            font: inherit;
            font-size: 0.84rem;
            font-weight: 700;
            cursor: pointer;
            min-height: 44px;
            min-width: 44px;
            white-space: normal;
            overflow-wrap: anywhere;
        }
        .btn svg {
            width: 14px;
            height: 14px;
            flex-shrink: 0;
        }
        .btn-primary {
            background: var(--brand);
            color: #fff;
        }
        .btn-soft {
            background: var(--soft);
            color: var(--brand);
        }
        .btn-danger {
            background: var(--danger-soft);
            color: var(--danger);
        }
        .btn-icon {
            width: 42px;
            height: 42px;
            padding: 0;
            border-radius: 14px;
        }
        .btn-icon svg {
            width: 18px;
            height: 18px;
        }
        .list-item {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid #eef2fa;
        }
        .list-item:last-child { border-bottom: 0; padding-bottom: 0; }
        .list-item:first-child { padding-top: 0; }
        .list-item strong {
            display: block;
            margin-bottom: 4px;
            font-size: 0.9rem;
        }
        .thumb {
            width: 72px;
            height: 72px;
            flex: 0 0 72px;
            border-radius: 14px;
            object-fit: cover;
            background: #edf3ff;
            border: 1px solid var(--line);
        }
        .upload-field {
            display: grid;
            gap: 10px;
        }
        .upload-box {
            position: relative;
            border: 1px dashed #9db4df;
            border-radius: 16px;
            background: linear-gradient(180deg, #f8fbff 0%, #eef4ff 100%);
            padding: 14px;
        }
        .upload-box input[type="file"] {
            width: 100%;
            border: 0;
            padding: 0;
            background: transparent;
        }
        .upload-help {
            margin: 0;
            color: var(--muted);
            font-size: 0.76rem;
        }
        .upload-preview {
            display: none;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            border: 1px solid var(--line);
            border-radius: 16px;
            background: #fff;
        }
        .upload-preview.is-visible {
            display: flex;
        }
        .upload-preview img {
            width: 84px;
            height: 84px;
            border-radius: 14px;
            object-fit: cover;
            background: #edf3ff;
            border: 1px solid var(--line);
        }
        .upload-preview strong {
            display: block;
            margin-bottom: 4px;
            font-size: 0.86rem;
        }
        .upload-preview p {
            margin: 0;
            color: var(--muted);
            font-size: 0.76rem;
            line-height: 1.45;
        }
        .list-main {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            flex: 1;
            min-width: 0;
        }
        .list-copy {
            min-width: 0;
        }
        .list-item p {
            margin: 0;
            color: var(--muted);
            font-size: 0.78rem;
            line-height: 1.45;
        }
        .product-size-line {
            margin-top: 5px;
            color: #12357f;
            font-size: 0.76rem;
            font-weight: 800;
            overflow-wrap: anywhere;
        }
        .product-desc-preview {
            margin-top: 6px;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
            color: #5f6f8c;
            font-size: 0.74rem;
            line-height: 1.45;
        }
        .product-desc-preview strong {
            display: inline;
            color: #10296c;
            font-size: inherit;
        }
        .meta {
            display: inline-flex;
            padding: 7px 10px;
            border-radius: 999px;
            background: #edf3ff;
            color: var(--brand);
            font-size: 0.76rem;
            font-weight: 700;
            white-space: nowrap;
        }
        .tag-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }
        .tag-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 10px;
            border-radius: 999px;
            background: #edf3ff;
            color: var(--brand);
            font-size: 0.76rem;
            font-weight: 700;
        }
        .tag-item svg {
            width: 18px;
            height: 18px;
            stroke: currentColor;
            fill: none;
            stroke-width: 1.9;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .tag-item form {
            display: inline;
        }
        .tag-delete {
            border: 0;
            background: transparent;
            color: #c23a33;
            font: inherit;
            font-size: 0.76rem;
            font-weight: 700;
            cursor: pointer;
            padding: 0;
        }
        .status-pill {
            display: inline-flex;
            padding: 7px 10px;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 700;
        }
        .status-pill.pending { background: #ffe8b8; color: #9d7004; }
        .status-pill.processing { background: #ffe1c2; color: #a25d04; }
        .status-pill.completed { background: #daf4d6; color: #3f8d43; }
        .status-pill.accepted { background: #daf4d6; color: #3f8d43; }
        .status-pill.declined { background: #ffe0de; color: #ba3d36; }
        .customization-media {
            display: grid;
            grid-template-columns: 82px minmax(0, 1fr);
            gap: 12px;
            align-items: center;
            margin: 10px 0;
        }
        .customization-media.no-image { grid-template-columns: minmax(0, 1fr); }
        .customization-media img,
        .customization-proof {
            width: 82px;
            height: 72px;
            object-fit: cover;
            border-radius: 12px;
            background: #edf3ff;
            border: 1px solid var(--line);
        }
        .customization-detail-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }
        .customization-detail-grid p {
            margin: 0;
            padding: 10px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: #f8fbff;
            color: var(--muted);
            font-size: 0.78rem;
            line-height: 1.45;
        }
        .customization-detail-grid strong {
            display: block;
            color: var(--text);
        }
        .mini-popup-overlay {
            position: fixed;
            inset: 0;
            background: rgba(10, 26, 64, 0.42);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 18px;
            z-index: 50;
        }
        .mini-popup-overlay[hidden] { display: none !important; }
        .chat-restore-form { margin-left: auto; }
        .admin-chat-menu { margin-left: auto; border: 0; border-radius: 10px; padding: 7px 10px; background: #eef3ff; color: #244aa4; font-size: 1.1rem; cursor: pointer; }
        .mini-popup {
            width: min(100%, 260px);
            border-radius: 18px;
            border: 1px solid var(--line);
            background: #fff;
            box-shadow: 0 24px 48px rgba(17, 40, 92, 0.16);
            padding: 16px;
            text-align: center;
        }
        .mini-popup h3 {
            margin: 0 0 6px;
            color: var(--brand);
            font-size: 0.95rem;
        }
        .mini-popup p {
            margin: 0 0 12px;
            color: var(--muted);
            font-size: 0.78rem;
            line-height: 1.4;
        }
        .order-card {
            padding: 12px 0;
            border-bottom: 1px solid #eef2fa;
        }
        .order-card:last-child { border-bottom: 0; padding-bottom: 0; }
        .order-link-card {
            display: block;
            padding: 14px;
            border: 1px solid var(--line);
            border-radius: 18px;
            background: #fff;
            box-shadow: 0 12px 28px rgba(17, 40, 92, 0.05);
        }
        .order-link-card + .order-link-card {
            margin-top: 12px;
        }
        .order-link-card:hover {
            background: #f7faff;
        }
        .order-top {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 8px;
        }
        .order-focus-title {
            margin: 0 0 6px;
            color: var(--brand);
            font-size: 1rem;
            font-weight: 800;
            line-height: 1.2;
        }
        .order-focus-client {
            margin: 0 0 8px;
            color: var(--text);
            font-size: 0.9rem;
            font-weight: 700;
        }
        .order-card p {
            margin: 0 0 8px;
            color: var(--muted);
            font-size: 0.78rem;
        }
        .order-form {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .order-form select {
            flex: 1;
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 9px 10px;
            font: inherit;
            font-size: 0.8rem;
        }
        .order-detail-shell {
            display: grid;
            gap: 14px;
        }
        .order-back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            width: fit-content;
            color: var(--brand);
            font-size: 0.8rem;
            font-weight: 700;
        }
        .order-back-link svg {
            width: 16px;
            height: 16px;
        }
        .client-card {
            display: grid;
            gap: 12px;
        }
        .client-card-head {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .client-avatar,
        .client-avatar img {
            width: 64px;
            height: 64px;
            flex: 0 0 64px;
            border-radius: 18px;
        }
        .client-avatar {
            display: grid;
            place-items: center;
            overflow: hidden;
            background: linear-gradient(180deg, #163f97 0%, #102c73 100%);
            color: #fff;
            font-size: 1.35rem;
            font-weight: 800;
        }
        .client-avatar img {
            object-fit: cover;
            display: block;
        }
        .client-card-head strong {
            display: block;
            margin-bottom: 4px;
            font-size: 0.98rem;
        }
        .client-card-head span {
            color: var(--muted);
            font-size: 0.76rem;
        }
        .client-info-grid {
            display: grid;
            gap: 10px;
        }
        .client-info-item {
            padding: 10px 12px;
            border: 1px solid var(--line);
            border-radius: 14px;
            background: #f8fbff;
        }
        .client-info-item label {
            display: block;
            margin-bottom: 4px;
            color: var(--muted);
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .client-info-item strong,
        .client-info-item span {
            font-size: 0.86rem;
        }
        .admin-profile-shell {
            display: grid;
            gap: 14px;
        }
        .admin-profile-hero {
            display: grid;
            gap: 14px;
        }
        .admin-profile-head {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .admin-profile-avatar,
        .admin-profile-avatar img {
            width: 72px;
            height: 72px;
            flex: 0 0 72px;
            border-radius: 20px;
        }
        .admin-profile-avatar {
            display: grid;
            place-items: center;
            overflow: hidden;
            background: linear-gradient(180deg, #163f97 0%, #102c73 100%);
            color: #fff;
            font-size: 1.6rem;
            font-weight: 800;
        }
        .admin-profile-avatar img {
            object-fit: cover;
            display: block;
        }
        .admin-profile-copy strong {
            display: block;
            margin-bottom: 4px;
            font-size: 1.05rem;
        }
        .admin-profile-copy span,
        .admin-profile-copy p {
            margin: 0;
            color: var(--muted);
            font-size: 0.76rem;
            line-height: 1.4;
        }
        .admin-profile-info {
            display: grid;
            gap: 10px;
        }
        .admin-profile-info-item {
            padding: 10px 12px;
            border: 1px solid var(--line);
            border-radius: 14px;
            background: #f8fbff;
        }
        .admin-profile-info-item label {
            display: block;
            margin-bottom: 4px;
            color: var(--muted);
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .admin-profile-info-item strong,
        .admin-profile-info-item span {
            font-size: 0.86rem;
        }
        .admin-profile-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .profile-logout-btn {
            width: 100%;
            justify-content: center;
        }
        .settings-popup {
            width: min(100%, 390px);
            max-height: min(82vh, 760px);
            overflow-y: auto;
            border-radius: 22px;
            border: 1px solid var(--line);
            background: #fff;
            box-shadow: 0 28px 56px rgba(17, 40, 92, 0.18);
            padding: 16px;
        }
        .language-setting { display: grid; gap: 8px; margin-bottom: 14px; }
        .language-choices { display: flex; gap: 6px; }
        .language-choices button {
            flex: 1;
            min-height: 36px;
            border: 1px solid var(--line);
            border-radius: 6px;
            background: #fff;
            color: var(--text);
            font: inherit;
            cursor: pointer;
        }
        .language-choices button.active { border-color: var(--brand); background: #e8f0ff; color: var(--brand); font-weight: 700; }
        .settings-popup-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 12px;
        }
        .settings-popup-head h2 {
            margin: 0;
            font-size: 1rem;
            color: var(--brand);
        }
        .settings-popup-close {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            border-radius: 10px;
            background: var(--soft);
            color: var(--brand);
            font-size: 1.2rem;
            line-height: 1;
        }
        .action-sheet {
            width: min(100%, 320px);
            border-radius: 22px;
            border: 1px solid var(--line);
            background: #fff;
            box-shadow: 0 28px 56px rgba(17, 40, 92, 0.18);
            padding: 14px;
            display: grid;
            gap: 10px;
        }
        .action-sheet h3 {
            margin: 0;
            font-size: 0.98rem;
            color: var(--brand);
        }
        .action-sheet p {
            margin: 0;
            color: var(--muted);
            font-size: 0.76rem;
            line-height: 1.45;
        }
        .action-sheet[hidden] {
            display: none;
        }
        .admin-chat-shell {
            display: grid;
            gap: 14px;
        }
        .admin-chat-shell.list-only {
            gap: 10px;
        }
        .admin-chat-shell.chat-only {
            min-height: 0;
        }
        .admin-chat-list,
        .admin-chat-panel {
            border: 1px solid var(--line);
            border-radius: 20px;
            background: #fff;
            box-shadow: 0 14px 32px rgba(17, 40, 92, 0.06);
        }
        .admin-chat-list {
            overflow: hidden;
        }
        .admin-chat-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            border-bottom: 1px solid #eef2fa;
        }
        .admin-chat-link:last-child {
            border-bottom: 0;
        }
        .admin-chat-link.active {
            background: #eef4ff;
        }
        .admin-chat-link:hover {
            background: #f7faff;
        }
        .admin-chat-avatar {
            width: 42px;
            height: 42px;
            flex: 0 0 42px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: linear-gradient(180deg, #163f97 0%, #102c73 100%);
            color: #fff;
            font-size: 0.9rem;
            font-weight: 700;
        }
        .admin-chat-copy {
            min-width: 0;
            flex: 1;
        }
        .admin-chat-copy strong {
            display: block;
            margin-bottom: 4px;
            font-size: 0.84rem;
            color: var(--text);
        }
        .admin-chat-copy p,
        .admin-chat-copy span {
            margin: 0;
            color: var(--muted);
            font-size: 0.72rem;
            line-height: 1.35;
        }
        .admin-chat-panel {
            padding: 14px;
            display: grid;
            gap: 12px;
            min-height: 420px;
        }
        .admin-chat-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            width: fit-content;
            color: var(--brand);
            font-size: 0.78rem;
            font-weight: 700;
        }
        .admin-chat-back svg {
            width: 16px;
            height: 16px;
        }
        .admin-chat-head {
            display: flex;
            align-items: center;
            gap: 12px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eef2fa;
            cursor: pointer;
            user-select: none;
        }
        .admin-chat-context {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            border: 1px solid #dce7ff;
            border-radius: 16px;
            background: #f5f8ff;
        }
        .admin-chat-context-media {
            width: 48px;
            height: 48px;
            flex: 0 0 48px;
            border-radius: 14px;
            overflow: hidden;
            background: #dfe8ff;
        }
        .admin-chat-context-media img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .admin-chat-context-copy {
            min-width: 0;
            flex: 1;
        }
        .admin-chat-context-copy strong {
            display: block;
            margin-bottom: 3px;
            color: var(--brand);
            font-size: 0.84rem;
        }
        .admin-chat-context-copy span {
            color: var(--muted);
            font-size: 0.72rem;
        }
        .admin-chat-thread {
            display: grid;
            gap: 10px;
            min-height: 220px;
            max-height: 360px;
            overflow-y: auto;
            padding-right: 4px;
        }
        .admin-chat-bubble {
            max-width: 84%;
            padding: 10px 12px;
            border-radius: 18px;
            font-size: 0.78rem;
            line-height: 1.45;
            box-shadow: 0 8px 16px rgba(17, 40, 92, 0.06);
        }
        .admin-chat-bubble.outgoing {
            justify-self: end;
            background: linear-gradient(180deg, #2857d6 0%, #163f97 100%);
            color: #fff;
            border-radius: 18px 18px 6px 18px;
        }
        .admin-chat-bubble.incoming {
            justify-self: start;
            background: #eef4ff;
            color: #24416f;
            border-radius: 18px 18px 18px 6px;
        }
        .admin-chat-bubble p {
            margin: 0;
        }
        .admin-chat-image { display: block; max-width: min(100%, 260px); max-height: 240px; object-fit: contain; border-radius: 12px; cursor: zoom-in; }
        .admin-chat-attach { display: inline-flex; align-items: center; gap: 8px; color: var(--brand); font-size: .76rem; font-weight: 700; cursor: pointer; }
        .admin-chat-attach input { max-width: 190px; font-size: .72rem; }
        .admin-chat-product-tag {
            display: inline-flex;
            margin-top: 8px;
            padding: 5px 8px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.2);
            font-size: 0.64rem;
            font-weight: 700;
        }
        .admin-chat-bubble.incoming .admin-chat-product-tag {
            background: #dbe6ff;
            color: #2952c8;
        }
        .admin-chat-meta {
            margin-top: 6px;
            display: flex;
            justify-content: space-between;
            gap: 10px;
            font-size: 0.64rem;
            opacity: 0.82;
        }
        .admin-chat-compose {
            display: grid;
            gap: 10px;
            padding-top: 10px;
            border-top: 1px solid #eef2fa;
        }
        .admin-chat-compose textarea {
            width: 100%;
            min-height: 88px;
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 12px 14px;
            font: inherit;
            font-size: 0.82rem;
            resize: vertical;
        }
        .admin-chat-compose-foot {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }
        .admin-chat-compose-foot span {
            color: var(--muted);
            font-size: 0.7rem;
        }
        .filter-tabs {
            display: flex;
            gap: 6px;
            margin-bottom: 14px;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }
        .filter-tabs::-webkit-scrollbar { display: none; }
        .filter-tab {
            flex: 1;
            text-align: center;
            padding: 9px 10px;
            border-radius: 12px;
            border: 1px solid var(--line);
            font-size: 0.78rem;
            font-weight: 700;
            background: #fff;
            color: var(--muted);
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.15s;
        }
        .filter-tab:hover {
            background: var(--soft);
            border-color: var(--brand);
            color: var(--brand);
        }
        .filter-tab.active {
            background: var(--brand);
            border-color: var(--brand);
            color: #fff;
        }
        .bottom-nav {
            position: fixed;
            left: 50%;
            bottom: 0;
            transform: translateX(-50%);
            width: min(100%, 430px);
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            border-top: 1px solid var(--line);
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(10px);
        }
        .bottom-nav a {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2px;
            padding: 8px 6px 10px;
            text-align: center;
            font-size: 0.68rem;
            font-weight: 600;
            color: var(--muted);
        }
        .bottom-nav a svg {
            width: 16px;
            height: 16px;
        }
        .bottom-nav a.active { color: #1f5cff; }
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
            z-index: 80;
            display: none;
            width: 48px;
            height: 48px;
            border-radius: 16px;
            border: 1px solid rgba(18, 53, 127, 0.12);
            background: linear-gradient(180deg, var(--brand) 0%, var(--brand-deep) 100%);
            color: #fff;
            box-shadow: 0 14px 28px rgba(10, 26, 64, 0.22);
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
        .shell,
        .topbar,
        .content,
        .panel,
        .card,
        .bottom-nav,
        .settings-popup,
        .admin-chat-panel,
        .admin-chat-list {
            min-width: 0;
        }
        .panel,
        .card,
        .settings-popup,
        .mini-popup,
        .action-sheet {
            max-width: 100%;
        }
        .list-item,
        .list-main,
        .admin-chat-context,
        .admin-chat-compose-foot,
        .topbar-head,
        .section-title,
        .order-top,
        .client-card-head,
        .admin-profile-head,
        .upload-preview {
            flex-wrap: wrap;
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
        @media (max-width: 374px) {
            .content {
                padding-left: 10px;
                padding-right: 10px;
            }
            .stats-grid,
            .two-col,
            .customization-media,
            .customization-detail-grid {
                grid-template-columns: 1fr;
            }
            .actions,
            .order-form,
            .admin-chat-compose-foot {
                display: grid;
                grid-template-columns: 1fr;
            }
            .btn,
            .order-form select {
                width: 100%;
            }
        }
        @media (min-width: 560px) {
            .shell {
                max-width: min(100%, 760px);
            }
            .stats-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
            .client-info-grid,
            .admin-profile-info,
            .customization-detail-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .settings-popup {
                width: min(100%, 560px);
            }
        }
        @media (min-width: 768px) {
            .shell {
                max-width: min(100%, 1080px);
            }
            .content {
                padding-left: clamp(20px, 4vw, 40px);
                padding-right: clamp(20px, 4vw, 40px);
            }
            .stats-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
            .admin-chat-shell:not(.chat-only):not(.list-only) {
                grid-template-columns: minmax(260px, 0.9fr) minmax(0, 1.4fr);
            }
        }
        @media (min-width: 1024px) {
            .shell {
                max-width: none;
                margin: 0;
                display: grid;
                grid-template-columns: 232px minmax(0, 1fr);
                grid-template-rows: auto minmax(0, 1fr);
            }
            .topbar {
                grid-column: 2;
                position: sticky;
                top: 0;
                z-index: 20;
            }
            .content {
                grid-column: 2;
                padding: clamp(22px, 3vw, 38px);
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
                border-top: 0;
                border-right: 1px solid var(--line);
            }
            .bottom-nav a {
                flex-direction: row;
                justify-content: flex-start;
                min-height: 50px;
                padding: 0 12px;
                border-radius: 14px;
                font-size: 0.84rem;
                text-align: left;
            }
            .bottom-nav a:hover,
            .bottom-nav a.active {
                background: #edf3ff;
            }
            .bottom-nav a svg {
                width: 20px;
                height: 20px;
                flex: 0 0 20px;
            }
            .mobile-menu-toggle {
                display: none;
            }
        }
        @media (min-width: 1366px) {
            .content {
                padding-left: clamp(36px, 5vw, 72px);
                padding-right: clamp(36px, 5vw, 72px);
            }
            .stats-grid {
                grid-template-columns: repeat(5, minmax(0, 1fr));
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
                border: 0;
                border-radius: 18px;
                opacity: 0;
                pointer-events: none;
                box-shadow: 0 22px 44px rgba(10, 26, 64, 0.22);
                transition: opacity 0.18s ease, transform 0.18s ease;
                z-index: 70;
            }
            .nav-toggle-input:checked ~ .bottom-nav {
                opacity: 1;
                pointer-events: auto;
                transform: translateY(0);
            }
            .bottom-nav a {
                flex-direction: row;
                justify-content: flex-start;
                min-height: 46px;
                padding: 8px 12px;
                border-radius: 12px;
                font-size: 0.78rem;
                text-align: left;
            }
            .bottom-nav a:hover,
            .bottom-nav a.active {
                background: var(--soft);
            }
        }
        @media (max-width: 360px) {
            .shell {
                max-width: none;
                width: 100vw;
            }
            .topbar,
            .content {
                padding-left: 10px;
                padding-right: 10px;
            }
            .topbar-head,
            .section-title,
            .list-item,
            .list-main,
            .order-top,
            .client-card-head,
            .admin-profile-head,
            .admin-chat-context,
            .admin-chat-compose-foot,
            .upload-preview {
                align-items: flex-start;
            }
            .topbar strong,
            .section-title h1,
            .section-title h2 {
                overflow-wrap: anywhere;
            }
            .stats-grid,
            .two-col,
            .customization-media,
            .customization-detail-grid,
            .client-info-grid,
            .admin-profile-info {
                grid-template-columns: 1fr;
            }
            .actions,
            .order-form,
            .admin-chat-compose-foot {
                display: grid;
                grid-template-columns: 1fr;
                width: 100%;
            }
            .btn,
            .order-form select {
                width: 100%;
            }
            .admin-chat-bubble {
                max-width: 100%;
            }
            .mini-popup-overlay {
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
                left: 50%;
                right: auto;
                bottom: 0;
                transform: translateX(-50%);
                width: 100%;
                max-width: 430px;
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
                transform: translateX(-50%);
            }
            .bottom-nav a {
                flex-direction: column;
                justify-content: center;
                align-items: center;
                min-height: 48px;
                padding: 6px 2px;
                border-radius: 10px;
                font-size: clamp(0.46rem, 2.2vw, 0.6rem);
                text-align: center;
                gap: 2px;
            }
            .bottom-nav a svg {
                width: 16px;
                height: 16px;
            }
            .bottom-nav a span {
                max-width: 100%;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
        }
        @media (max-width: 767px) {
            .shell {
                width: min(100%, 430px);
                max-width: 430px;
                min-height: 100vh;
                margin: 0 auto;
                display: block;
            }
            .topbar {
                grid-column: auto;
                padding: 14px 14px 16px;
            }
            .content {
                grid-column: auto;
                padding: 16px clamp(10px, 3.5vw, 16px) calc(86px + env(safe-area-inset-bottom));
            }
            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 10px;
            }
            .two-col,
            .customization-detail-grid,
            .client-info-grid,
            .admin-profile-info {
                grid-template-columns: 1fr;
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
                padding: 5px 2px calc(6px + env(safe-area-inset-bottom));
                border-radius: 0;
            }
            .nav-toggle-input:checked ~ .bottom-nav {
                transform: none;
            }
            .bottom-nav a {
                flex-direction: column;
                justify-content: center;
                min-height: 48px;
                padding: 6px 1px;
                font-size: clamp(0.44rem, 2vw, 0.58rem);
                text-align: center;
            }
        }
        @media (min-width: 768px) and (max-width: 1023px) {
            .shell {
                width: 100%;
                max-width: none;
                margin: 0;
            }
            .content {
                padding-left: clamp(22px, 4vw, 36px);
                padding-right: clamp(22px, 4vw, 36px);
            }
            .stats-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
            .bottom-nav {
                max-width: none;
            }
        }
        @media (min-width: 1024px) {
            .shell {
                width: 100%;
                max-width: none;
                min-height: 100vh;
                margin: 0;
                display: grid;
                grid-template-columns: 248px minmax(0, 1fr);
                grid-template-rows: auto minmax(0, 1fr);
            }
            .topbar {
                grid-column: 2;
                padding: 18px clamp(28px, 4vw, 56px);
            }
            .content {
                grid-column: 2;
                padding: clamp(24px, 3vw, 42px) clamp(28px, 4vw, 64px);
            }
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
                gap: 16px;
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
            .bottom-nav a {
                flex-direction: row;
                justify-content: flex-start;
                min-height: 52px;
                padding: 0 14px;
                font-size: 0.86rem;
                text-align: left;
            }
            .bottom-nav a span {
                white-space: normal;
            }
        }
        @media (max-width: 767px) {
            body {
                background: #fff;
            }
            .shell {
                width: min(100%, 430px);
                max-width: 430px;
                min-height: 100vh;
                margin: 0 auto;
                display: block;
            }
            .topbar {
                grid-column: auto;
                position: relative;
                padding: 14px 16px 16px;
            }
            .content {
                grid-column: auto;
                padding: 18px 16px 90px;
            }
            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px;
            }
            .two-col {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .customization-detail-grid,
            .client-info-grid,
            .admin-profile-info {
                grid-template-columns: 1fr;
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
                column-gap: 6px;
                row-gap: 0;
                padding: 5px 14px calc(6px + env(safe-area-inset-bottom));
                border-radius: 0;
            }
            .nav-toggle-input:checked ~ .bottom-nav {
                transform: translateX(-50%);
            }
            .bottom-nav a {
                flex-direction: column;
                justify-content: center;
                align-items: center;
                min-height: 48px;
                padding: 6px 2px;
                border-radius: 10px;
                font-size: 0.52rem;
                text-align: center;
                gap: 2px;
            }
            .bottom-nav a svg {
                width: 16px;
                height: 16px;
            }
            .bottom-nav a span {
                max-width: 100%;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
        }
        .bottom-nav a { position: relative; }
        .admin-nav-badge {
            position: absolute;
            top: 2px;
            right: 9px;
            display: grid;
            place-items: center;
            min-width: 16px;
            height: 16px;
            padding: 0 3px;
            border-radius: 8px;
            background: #d74c4c;
            color: #fff;
            font-size: 0.62rem;
            line-height: 1;
        }
        .customization-progress {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 6px;
            margin: 10px 0;
            padding: 0;
            list-style: none;
        }
        .customization-progress li {
            display: flex;
            align-items: center;
            gap: 7px;
            min-height: 36px;
            min-width: 0;
            padding: 7px 8px;
            border: 1px solid var(--line);
            border-radius: 6px;
            background: #f8fafc;
            color: var(--muted);
            font-size: 0.75rem;
            overflow-wrap: anywhere;
        }
        .customization-progress li::before {
            content: '';
            width: 9px;
            height: 9px;
            flex: 0 0 9px;
            border: 1px solid #aeb9cf;
            border-radius: 50%;
        }
        .customization-progress li.is-done { color: var(--text); background: #eef7ef; border-color: #c8e4cf; }
        .customization-progress li.is-done::before { background: #29a853; border-color: #29a853; }
        .customization-progress li.is-current { font-weight: 700; border-color: var(--brand); }
        .customer-disclosure { margin: 10px 0 14px; }
        .customer-disclosure summary { display: inline-flex; cursor: pointer; list-style: none; }
        .customer-disclosure summary::-webkit-details-marker { display: none; }
        .customer-disclosure .panel { margin-top: 10px; }
        .order-totals { display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0 14px; }
        .order-totals span { padding: 8px 10px; border: 1px solid var(--line); border-radius: 8px; background: #f8fafc; font-size: .78rem; }
        .order-totals strong { color: var(--brand); }
        .backup-disclosure { margin-top: 12px; border-top: 1px solid var(--line); padding-top: 10px; }
        .backup-disclosure summary { cursor: pointer; font-weight: 700; color: var(--brand); }
        .backup-history-toggle > summary { display: inline-flex; padding: 10px 14px; border: 1px solid var(--line); border-radius: 10px; background: #eef3ff; list-style: none; }
        .backup-history-toggle > summary::-webkit-details-marker { display: none; }
        .backup-brand-title { display: flex; align-items: center; gap: 10px; }
        .backup-brand-mark { display: grid; place-items: center; width: 38px; height: 38px; border-radius: 11px; background: linear-gradient(145deg, #173d90, #3769ca); color: #fff; font-size: .9rem; font-weight: 900; letter-spacing: -.08em; box-shadow: 0 4px 12px rgba(23, 61, 144, .2); }
        .backup-table-wrap { max-width: 100%; overflow-x: auto; margin-top: 12px; }
        .backup-history-table { width: 100%; min-width: 640px; border-collapse: collapse; font-size: .76rem; }
        .backup-history-table th, .backup-history-table td { padding: 10px 8px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: middle; }
        .backup-history-table th { color: var(--brand); background: #f4f7fd; }
        .backup-history-table td:first-child { max-width: 240px; overflow-wrap: anywhere; white-space: normal; }
        .backup-table-actions { display: flex; flex-wrap: nowrap; gap: 5px; }
        .backup-table-actions form { margin: 0; }
        .backup-table-actions .btn { white-space: nowrap; padding: 7px 9px; font-size: .7rem; }
        .customization-progress li.is-action { padding: 0; border-color: var(--brand); background: #eaf0ff; }
        .customization-progress li.is-action::before { margin-left: 8px; }
        .customization-progress li.is-action form { flex: 1; margin: 0; }
        .customization-progress li.is-action button { display: block; width: 100%; padding: 8px 2px; border: 0; background: transparent; color: #194aba; font: inherit; font-weight: 800; text-align: left; cursor: pointer; }
        .order-type-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 14px; }
        .order-type-tabs a { padding: 9px 13px; border-radius: 999px; background: #eef3ff; color: #244aa4; font-size: .78rem; font-weight: 700; }
        .order-type-tabs a.active { background: #254fba; color: #fff; }
        .normal-order-steps { display: flex; flex-wrap: wrap; gap: 8px; margin: 14px 0; }
        .normal-order-step { display: inline-flex; align-items: center; min-height: 36px; padding: 8px 12px; border: 1px solid #d7dfed; border-radius: 999px; background: #f5f7fb; color: #64748b; font: inherit; font-size: .76rem; font-weight: 700; }
        .normal-order-step.done { border-color: #98d8a4; background: #e3f7e6; color: #24733a; }
        .normal-order-step.action { cursor: pointer; border-color: #2a57c8; background: #e9efff; color: #173f98; }
        .backup-row { align-items: center; gap: 6px; margin-bottom: 8px; padding: 8px 0; border-bottom: 1px solid var(--line); }
        .backup-meta { flex: 1 1 100%; min-width: 0; font-size: 0.76rem; }
        .backup-meta small { color: var(--muted); font-size: 0.66rem; overflow-wrap: anywhere; }
        .customization-image-dialog {
            width: min(calc(100% - 24px), 900px);
            max-height: calc(100dvh - 32px);
            border: 0;
            border-radius: 8px;
            padding: 16px;
            background: #fff;
            color: var(--text);
        }
        .customization-image-dialog::backdrop { background: rgba(12, 25, 50, 0.7); }
        .customization-image-dialog h2 { margin: 12px 0; font-size: 1rem; }
        .customization-image-dialog img { display: block; width: 100%; max-height: 70dvh; object-fit: contain; }
        .customization-image-dialog.is-zoomed img { width: 160%; max-height: none; max-width: none; cursor: zoom-out; }
        .restore-done-overlay { position: fixed; inset: 0; z-index: 9999; display: grid; place-items: center; padding: 20px; background: rgba(12, 25, 50, .55); }
        .restore-done-card { width: min(100%, 320px); padding: 24px; border-radius: 18px; background: #fff; text-align: center; box-shadow: 0 20px 55px rgba(12, 25, 50, .25); }
        .restore-done-card p { margin: 0 0 18px; color: var(--brand); font-weight: 700; }
    </style>
</head>
<body data-language-role="admin">
    <div class="shell">
        <header class="topbar">
            <div class="topbar-head">
                <div>
                    <strong>Furniture System</strong>
                    <span>Seller Dashboard</span>
                </div>
            </div>
        </header>

        <main class="content">
            <?php if ($section === 'dashboard'): ?>
                <div class="section-title">
                    <h1>Dashboard</h1>
                </div>
                <div class="stats-grid">
                    <?php foreach ($stats as $stat): ?>
                        <a class="card stat-card-link" href="<?= ($stat['key'] ?? '') === 'messages' ? 'admin.php?section=messages' : 'admin.php?section=dashboard&statpopup=' . urlencode((string) ($stat['key'] ?? '')) ?>">
                            <div class="stat-icon <?= htmlspecialchars($stat['tone'], ENT_QUOTES, 'UTF-8') ?>">
                                <?php if (($stat['icon'] ?? '') === 'bag'): ?>
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M6 7h12l-1 12H7L6 7Z"/>
                                        <path d="M9 9V7a3 3 0 0 1 6 0v2"/>
                                    </svg>
                                <?php elseif (($stat['icon'] ?? '') === 'wallet'): ?>
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M3 7.5A2.5 2.5 0 0 1 5.5 5H18a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5.5A2.5 2.5 0 0 1 3 16.5v-9Z"/>
                                        <path d="M20 10h-4a2 2 0 0 0 0 4h4"/>
                                        <circle cx="16" cy="12" r=".5" fill="currentColor"/>
                                    </svg>
                                <?php elseif (($stat['icon'] ?? '') === 'sofa'): ?>
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M5 12a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v4H5v-4Z"/>
                                        <path d="M7 10V8a2 2 0 0 1 2-2h1v4"/>
                                        <path d="M14 6h1a2 2 0 0 1 2 2v2"/>
                                        <path d="M4 16h16"/>
                                        <path d="M6 16v2"/>
                                        <path d="M18 16v2"/>
                                    </svg>
                                <?php elseif (($stat['icon'] ?? '') === 'users'): ?>
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M16 19a4 4 0 0 0-8 0"/>
                                        <circle cx="12" cy="11" r="3"/>
                                        <path d="M21 19a4 4 0 0 0-3-3.87"/>
                                        <path d="M3 19a4 4 0 0 1 3-3.87"/>
                                        <path d="M17.5 8.5a2.5 2.5 0 1 1 0 5"/>
                                        <path d="M6.5 13.5a2.5 2.5 0 1 1 0-5"/>
                                    </svg>
                                <?php endif; ?>
                            </div>
                            <p class="stat-label"><?= htmlspecialchars($stat['label'], ENT_QUOTES, 'UTF-8') ?></p>
                            <p class="stat-value"><?= htmlspecialchars($stat['value'], ENT_QUOTES, 'UTF-8') ?></p>
                            <span class="stat-link"><?= htmlspecialchars($stat['link'], ENT_QUOTES, 'UTF-8') ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($section === 'dashboard'): ?>
            <?php endif; ?>

            <?php if ($section === 'messages'): ?>
                <div class="section-title">
                    <h1>Messages</h1>
                </div>
                <?php if ($notice !== ''): ?>
                    <div class="notice <?= htmlspecialchars($noticeType, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <?php if ($activeChat === null): ?>
                    <section class="admin-chat-shell list-only">
                        <div class="admin-chat-list">
                            <?php if ($chatThreads === []): ?>
                                <div class="panel" style="margin:0;border:0;box-shadow:none;">
                                    <p class="stat-label">No user messages yet.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($chatThreads as $thread): ?>
                                    <a class="admin-chat-link" href="admin.php?section=messages&chat=<?= urlencode($thread['email'] ?? '') ?>">
                                        <div class="admin-chat-avatar"><?= htmlspecialchars(strtoupper(substr((string) ($thread['name'] ?? 'U'), 0, 1)), ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="admin-chat-copy">
                                            <strong><?= htmlspecialchars($thread['name'] ?? 'User', ENT_QUOTES, 'UTF-8') ?></strong>
                                            <p><?= htmlspecialchars($thread['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
                                            <span><?= htmlspecialchars(appDisplayChatMessage((string) ($thread['last_message'] ?? '')), ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php else: ?>
                    <section class="admin-chat-shell chat-only">
                        <div class="admin-chat-panel">
                            <a class="admin-chat-back" href="admin.php?section=messages">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M15 18 9 12l6-6"/>
                                </svg>
                                Back to clients
                            </a>
                            <div class="admin-chat-head">
                                <div class="admin-chat-avatar"><?= htmlspecialchars(strtoupper(substr((string) ($activeChat['name'] ?? 'U'), 0, 1)), ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="admin-chat-copy">
                                    <strong><?= htmlspecialchars($activeChat['name'] ?? 'User', ENT_QUOTES, 'UTF-8') ?></strong>
                                    <p><?= htmlspecialchars($activeChat['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                                <button class="admin-chat-menu" type="button" data-open-sheet="chatActionSheet" aria-label="Chat actions after reading conversation">⋮</button>
                            </div>
                            <?php if ($activeChatProduct !== null): ?>
                                <div class="admin-chat-context">
                                    <div class="admin-chat-context-media">
                                        <?php if (($activeChatProduct['image'] ?? '') !== ''): ?>
                                            <img src="<?= htmlspecialchars((string) ($activeChatProduct['image'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string) ($activeChatProduct['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                        <?php endif; ?>
                                    </div>
                                    <div class="admin-chat-context-copy">
                                        <strong>About: <?= htmlspecialchars((string) ($activeChatProduct['name'] ?? 'Furniture item'), ENT_QUOTES, 'UTF-8') ?></strong>
                                        <span>This conversation is currently tied to this product.</span>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <div class="admin-chat-thread">
                                <?php foreach ($activeChatMessages as $message): ?>
                                    <?php
                                        $isOutgoing = ($message['from_email'] ?? '') === $adminEmail;
                                        $messageProduct = findProductById($products, (string) ($message['product_id'] ?? ''));
                                        $messageProductName = trim((string) ($messageProduct['name'] ?? ''));
                                    ?>
                                    <article class="admin-chat-bubble <?= $isOutgoing ? 'outgoing' : 'incoming' ?>">
                                        <?php if (trim((string) ($message['image_path'] ?? '')) !== ''): ?>
                                            <a href="<?= htmlspecialchars((string) ($message['image_path'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" aria-label="Open chat image">
                                                <img class="admin-chat-image" src="<?= htmlspecialchars((string) ($message['image_path'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" alt="Chat attachment">
                                            </a>
                                        <?php endif; ?>
                                        <?php if (trim((string) ($message['message'] ?? '')) !== ''): ?>
                                            <p><?= nl2br(htmlspecialchars(appDisplayChatMessage((string) ($message['message'] ?? '')), ENT_QUOTES, 'UTF-8')) ?></p>
                                        <?php endif; ?>
                                        <?php if ($messageProductName !== ''): ?>
                                            <span class="admin-chat-product-tag">About: <?= htmlspecialchars($messageProductName, ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endif; ?>
                                        <div class="admin-chat-meta">
                                            <span><?= $isOutgoing ? 'Admin' : htmlspecialchars((string) ($message['from_name'] ?? 'User'), ENT_QUOTES, 'UTF-8') ?></span>
                                            <span><?= htmlspecialchars((string) ($message['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                            <form class="admin-chat-compose" method="post" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="admin_send_message">
                                <input type="hidden" name="recipient_email" value="<?= htmlspecialchars($activeChat['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="recipient_name" value="<?= htmlspecialchars($activeChat['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="product_id" value="<?= htmlspecialchars((string) ($activeChatProduct['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <textarea name="message" placeholder="<?= $activeChatProduct !== null ? 'Reply about this product...' : 'Reply to this user...' ?>"></textarea>
                                <label class="admin-chat-attach">Attach image <input type="file" name="message_image" accept=".jpg,.jpeg,.jfif,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif"></label>
                                <div class="admin-chat-compose-foot">
                                    <span>Messenger-style admin chat reply.</span>
                                    <button class="btn btn-primary" type="submit">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                            <line x1="22" y1="2" x2="11" y2="13"/>
                                            <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                                        </svg>
                                        Send
                                    </button>
                                </div>
                            </form>
                        </div>
                    </section>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($section === 'products'): ?>
                <div class="section-title">
                    <h1>Products</h1>
                    <?php if (!$showProductForm): ?>
                        <a class="btn btn-primary btn-icon" href="admin.php?section=products&add=1" aria-label="Add furniture">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="12" y1="5" x2="12" y2="19"/>
                                <line x1="5" y1="12" x2="19" y2="12"/>
                            </svg>
                        </a>
                    <?php endif; ?>
                </div>

                <?php if ($notice !== ''): ?>
                    <div class="notice <?= htmlspecialchars($noticeType, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <?php if ($showProductForm): ?>
                    <section class="panel">
                        <div class="section-title">
                            <h2><?= $editingProduct['id'] !== '' ? 'Edit Furniture' : 'Add Furniture' ?></h2>
                        </div>
                        <form method="post" class="form-grid" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="save_product">
                            <input type="hidden" name="product_id" value="<?= htmlspecialchars($editingProduct['id'], ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="existing_image" value="<?= htmlspecialchars($editingProduct['image'], ENT_QUOTES, 'UTF-8') ?>">
                            <div class="field">
                                <label for="name">Furniture Name</label>
                                <input id="name" type="text" name="name" value="<?= htmlspecialchars($editingProduct['name'], ENT_QUOTES, 'UTF-8') ?>" required>
                            </div>
                            <div class="two-col">
                                <div class="field">
                                    <label for="category">Category</label>
                                    <select id="category" name="category" required>
                                        <option value="">Select category</option>
                                        <?php foreach ($categoryOptions as $option): ?>
                                            <option value="<?= htmlspecialchars($option['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" <?= $editingProduct['category'] === ($option['name'] ?? '') ? 'selected' : '' ?>><?= htmlspecialchars($option['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="field">
                                    <label for="material">Material</label>
                                    <select id="material" name="material" required>
                                        <option value="">Select material</option>
                                        <?php foreach ($materialOptions as $option): ?>
                                            <option value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>" <?= $editingProduct['material'] === $option ? 'selected' : '' ?>><?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="two-col">
                                <div class="field">
                                    <label for="price">Price</label>
                                    <input id="price" type="number" min="0" step="0.01" name="price" value="<?= htmlspecialchars((string) $editingProduct['price'], ENT_QUOTES, 'UTF-8') ?>" required>
                                </div>
                                <div class="field">
                                    <label for="stock">Stock</label>
                                    <input id="stock" type="number" min="0" step="1" name="stock" value="<?= htmlspecialchars($editingProduct['stock'], ENT_QUOTES, 'UTF-8') ?>" required>
                                </div>
                            </div>
                            <div class="field upload-field">
                                <label for="image">Product Image</label>
                                <div class="upload-box">
                                    <input id="image" type="file" name="image" accept=".jpg,.jpeg,.jfif,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif" <?= $editingProduct['id'] === '' ? 'required' : '' ?>>
                                    <p class="upload-help">Choose a clear furniture photo. Preview appears here before upload.</p>
                                </div>
                                <div class="upload-preview <?= $editingProduct['image'] !== '' ? 'is-visible' : '' ?>" id="imagePreviewCard">
                                    <img id="imagePreview" src="<?= htmlspecialchars($editingProduct['image'] !== '' ? $editingProduct['image'] : 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', ENT_QUOTES, 'UTF-8') ?>" alt="Product image preview">
                                    <div>
                                        <strong id="imagePreviewName"><?= $editingProduct['image'] !== '' ? 'Current image' : 'No image selected' ?></strong>
                                        <p id="imagePreviewMeta"><?= $editingProduct['image'] !== '' ? 'This image is currently saved for the product.' : 'Supported files: JPG, PNG, WEBP, GIF.' ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="field">
                                <label for="description">Description</label>
                                <textarea id="description" name="description" placeholder="Example: Size: 6ft x 4ft&#10;Add material notes, finish, included items, and customization details."><?= htmlspecialchars($editingProduct['description'], ENT_QUOTES, 'UTF-8') ?></textarea>
                            </div>
                            <div class="actions">
                                <button class="btn btn-primary" type="submit">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                        <?php if ($editingProduct['id'] !== ''): ?>
                                            <path d="M17 3a2.83 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/>
                                        <?php else: ?>
                                            <line x1="12" y1="5" x2="12" y2="19"/>
                                            <line x1="5" y1="12" x2="19" y2="12"/>
                                        <?php endif; ?>
                                    </svg>
                                    <?= $editingProduct['id'] !== '' ? 'Update Furniture' : 'Add Furniture' ?>
                                </button>
                                <a class="btn btn-soft" href="admin.php?section=products">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                        <line x1="18" y1="6" x2="6" y2="18"/>
                                        <line x1="6" y1="6" x2="18" y2="18"/>
                                    </svg>
                                    Close
                                </a>
                            </div>
                        </form>
                    </section>
                <?php endif; ?>

                <section class="panel">
                    <div class="section-title">
                        <h2>Furniture List</h2>
                    </div>
                    <?php if ($products === []): ?>
                        <p class="stat-label">No furniture added yet.</p>
                    <?php else: ?>
                        <?php foreach ($products as $product): ?>
                            <article class="list-item">
                                <div class="list-main">
                                    <?php if (($product['image'] ?? '') !== ''): ?>
                                        <img class="thumb" src="<?= htmlspecialchars($product['image'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($product['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    <?php endif; ?>
                                    <div class="list-copy">
                                        <strong><?= htmlspecialchars($product['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong>
                                        <p><?= htmlspecialchars($product['category'] ?? '', ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($product['material'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
                                        <p>Stock: <?= htmlspecialchars((string) ($product['stock'] ?? 0), ENT_QUOTES, 'UTF-8') ?> · P<?= number_format((float) ($product['price'] ?? 0), 2) ?></p>
                                        <div class="product-size-line">Size: <?= htmlspecialchars(adminProductSize($product), ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php if (trim((string) ($product['description'] ?? '')) !== ''): ?>
                                            <p class="product-desc-preview"><strong>Description:</strong> <?= nl2br(htmlspecialchars((string) ($product['description'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="actions wrap">
                                    <a class="btn btn-soft" href="admin.php?section=products&edit=<?= urlencode($product['id'] ?? '') ?>">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M17 3a2.83 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/>
                                            <path d="m15 5 4 4"/>
                                        </svg>
                                        Edit
                                    </a>
                                    <form method="post" onsubmit="return confirm('Delete this furniture item?');">
                                        <input type="hidden" name="action" value="delete_product">
                                        <input type="hidden" name="product_id" value="<?= htmlspecialchars($product['id'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                        <button class="btn btn-danger" type="submit">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                <polyline points="3 6 5 6 21 6"/>
                                                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                                <line x1="10" y1="11" x2="10" y2="17"/>
                                                <line x1="14" y1="11" x2="14" y2="17"/>
                                            </svg>
                                            Delete
                                        </button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($section === 'orders'): ?>
                <div class="order-type-tabs">
                    <a class="active" href="admin.php?section=orders">Normal Orders (<?= count($orders) ?>)</a>
                    <a href="admin.php?section=customizations">Customization Orders (<?= count($customizationRequests) ?>)</a>
                </div>
                <div class="filter-tabs">
                    <a class="filter-tab count-pending <?= in_array($orderFilter, ['pending', 'new', 'new_order'], true) ? 'active' : '' ?>" href="admin.php?section=orders&order_filter=new_order">New Order (<?= count($pendingOrders) ?>)</a>
                    <a class="filter-tab count-accepted <?= $orderFilter === 'accepted' ? 'active' : '' ?>" href="admin.php?section=orders&order_filter=accepted">Accepted (<?= count($acceptedOrders) ?>)</a>
                    <a class="filter-tab count-accepted <?= $orderFilter === 'completed' ? 'active' : '' ?>" href="admin.php?section=orders&order_filter=completed">Completed (<?= count($completeOrders) ?>)</a>
                    <a class="filter-tab count-declined <?= $orderFilter === 'declined' ? 'active' : '' ?>" href="admin.php?section=orders&order_filter=declined">Declined (<?= count($declinedOrders) ?>)</a>
                </div>
                <?php if ($selectedOrder === null): ?>
                    <section class="panel">
                        <?php if ($filteredOrders === []): ?>
                            <p class="stat-label">No <?= htmlspecialchars($selectedOrderStatus ?? 'New Order', ENT_QUOTES, 'UTF-8') ?> orders yet.</p>
                        <?php else: ?>
                            <?php foreach ($filteredOrders as $order): ?>
                                <a class="order-link-card" href="admin.php?section=orders&order_filter=<?= urlencode($orderFilter) ?>&order=<?= urlencode((string) ($order['id'] ?? '')) ?>">
                                    <div class="order-top">
                                        <span class="status-pill <?= htmlspecialchars($order['tone'] ?? 'pending', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($order['status'] ?? 'New Order', ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="stat-label"><?= htmlspecialchars((string) ($order['date'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                    <h3 class="order-focus-title"><?= htmlspecialchars((string) ($order['product_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h3>
                                    <p class="order-focus-client"><?= htmlspecialchars((string) ($order['customer'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                                    <p>Qty <?= htmlspecialchars((string) ($order['quantity'] ?? 1), ENT_QUOTES, 'UTF-8') ?> · P<?= number_format(moneyValue((string) ($order['total'] ?? '0')), 2) ?></p>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </section>
                <?php else: ?>
                    <section class="order-detail-shell">
                        <a class="order-back-link" href="admin.php?section=orders&order_filter=<?= urlencode($orderFilter) ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M15 18 9 12l6-6"/>
                            </svg>
                             Back to orders
                         </a>
                         <section class="panel">
                            <div class="section-title">
                                <h2>Order Summary</h2>
                                <span class="status-pill <?= htmlspecialchars($selectedOrder['tone'] ?? 'pending', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($selectedOrder['status'] ?? 'New Order', ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <article class="order-card" style="padding-top:0;">
                                <h3 class="order-focus-title"><?= htmlspecialchars((string) ($selectedOrder['product_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h3>
                                <p class="order-focus-client"><?= htmlspecialchars((string) ($selectedOrder['customer'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                                <p>Order ID: <?= htmlspecialchars((string) ($selectedOrder['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                                <p>Date: <?= htmlspecialchars((string) ($selectedOrder['date'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                                <p>Items ordered: <?= (int) ($selectedOrder['quantity'] ?? 1) ?> · Total: P<?= number_format(moneyValue((string) ($selectedOrder['total'] ?? '0')), 2) ?></p>
                                 <?php $orderStage = ['New Order' => 0, 'Pending' => 0, 'Accepted' => 1, 'Payment Received' => 1, 'Complete' => 2][(string) ($selectedOrder['status'] ?? 'New Order')] ?? 0; ?>
                                 <div class="normal-order-steps" aria-label="Order progress">
                                     <?php foreach (['Order Placed', 'Accepted', 'Complete'] as $stepIndex => $stepLabel): ?>
                                             <span class="normal-order-step <?= ($selectedOrder['status'] ?? '') === 'Declined' ? ($stepIndex === 0 ? 'done' : '') : ($stepIndex <= $orderStage ? 'done' : '') ?>"><?= htmlspecialchars($stepLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                     <?php endforeach; ?>
                                </div>
                                <?php if (in_array(($selectedOrder['status'] ?? ''), ['New Order', 'Pending'], true)): ?>
                                    <form class="order-form" method="post">
                                        <input type="hidden" name="action" value="update_order_status">
                                        <input type="hidden" name="order_id" value="<?= htmlspecialchars((string) ($selectedOrder['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                        <select name="status">
                                            <option value="Accepted">Accepted</option>
                                            <option value="Declined">Declined</option>
                                        </select>
                                        <button class="btn btn-primary" type="submit">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                                                <polyline points="17 21 17 13 7 13 7 21"/>
                                                <polyline points="7 3 7 8 15 8"/>
                                            </svg>
                                            Save Decision
                                        </button>
                                    </form>
                                 <?php elseif (in_array(($selectedOrder['status'] ?? ''), ['Accepted', 'Payment Received'], true)): ?>
                                    <form class="order-form" method="post">
                                        <input type="hidden" name="action" value="update_order_status">
                                        <input type="hidden" name="order_id" value="<?= htmlspecialchars((string) ($selectedOrder['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="status" value="Complete">
                                        <button class="btn btn-primary" type="submit">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M20 6 9 17l-5-5"/>
                                            </svg>
                                            Complete
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </article>
                        </section>
                        <details class="customer-disclosure">
                            <summary class="btn btn-soft">View Customer Info</summary>
                            <section class="panel client-card">
                                <div class="section-title"><h2>Client Information</h2></div>
                                <div class="client-card-head">
                                    <div class="client-avatar">
                                        <?php if ($selectedOrderClientImage !== ''): ?><img src="<?= htmlspecialchars($selectedOrderClientImage, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($selectedOrderClientName, ENT_QUOTES, 'UTF-8') ?>"><?php else: ?><span><?= htmlspecialchars($selectedOrderClientInitial, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                                    </div>
                                    <div><strong><?= htmlspecialchars($selectedOrderClientName, ENT_QUOTES, 'UTF-8') ?></strong></div>
                                </div>
                                <div class="client-info-grid">
                                    <div class="client-info-item"><label>Phone Number</label><strong><?= htmlspecialchars($selectedOrderClientPhone !== '' ? $selectedOrderClientPhone : 'Not provided', ENT_QUOTES, 'UTF-8') ?></strong></div>
                                    <div class="client-info-item"><label>Email Address</label><strong><?= htmlspecialchars($selectedOrderClientEmail !== '' ? $selectedOrderClientEmail : 'Not provided', ENT_QUOTES, 'UTF-8') ?></strong></div>
                                    <div class="client-info-item"><label>Delivery Address</label><strong><?= htmlspecialchars($selectedOrderClientAddress !== '' ? $selectedOrderClientAddress : 'Not provided', ENT_QUOTES, 'UTF-8') ?></strong></div>
                                </div>
                            </section>
                        </details>
                    </section>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($section === 'customizations'): ?>
                <div class="order-type-tabs">
                    <a href="admin.php?section=orders">Normal Orders (<?= count($orders) ?>)</a>
                    <a class="active" href="admin.php?section=customizations">Customization Orders (<?= count($customizationRequests) ?>)</a>
                </div>
                <div class="order-totals" aria-label="Customization order totals">
                    <span>Orders: <strong><?= count($customizationRequests) ?></strong></span>
                    <span>Total items ordered: <strong><?= array_sum(array_map(static function ($request) { return (int) ($request['quantity'] ?? 1); }, $customizationRequests)) ?></strong></span>
                    <span>Total quoted: <strong>P<?= number_format(array_sum(array_map(static function ($request) { return (float) ($request['quotation_price'] ?? 0); }, $customizationRequests)), 2) ?></strong></span>
                </div>
                <?php if ($selectedCustomizationRequest === null): ?>
                    <div class="section-title">
                        <h1>Customization Requests</h1>
                        <span class="meta"><?= count($customizationRequests) ?> total</span>
                    </div>
                    <section class="panel">
                        <?php if ($customizationRequests === []): ?>
                            <p class="stat-label">No customization requests yet.</p>
                        <?php else: ?>
                            <?php foreach ($customizationRequests as $request): ?>
                                 <a class="order-link-card" href="admin.php?section=customizations&customization=<?= urlencode((string) ($request['id'] ?? '')) ?>">
                                     <div class="order-top">
                                         <?php $awaitingPayment = ($request['status'] ?? '') === 'Down Payment Paid' && empty($request['payment_confirmed']); ?>
                                         <span class="status-pill <?= htmlspecialchars($awaitingPayment ? 'processing' : (string) ($request['tone'] ?? customizationTone((string) ($request['status'] ?? 'Pending'))), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($awaitingPayment ? 'Payment pending confirmation' : (string) ($request['status'] ?? 'Pending'), ENT_QUOTES, 'UTF-8') ?></span>
                                         <?php $requestUnreadCount = count(array_filter($store['messages'] ?? [], function ($message) use ($request) { return appMessageCustomizationId($message) === (string) ($request['id'] ?? '') && ($message['to'] ?? '') === 'admin' && empty($message['read_by_admin']); })); ?>
                                         <?php if ($requestUnreadCount > 0): ?><span class="status-pill processing"><?= $requestUnreadCount ?> new <?= $requestUnreadCount === 1 ? 'message' : 'messages' ?></span><?php endif; ?>
                                        <span class="stat-label"><?= htmlspecialchars((string) ($request['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                    <div class="customization-media <?= trim((string) ($request['product_image'] ?? '')) === '' ? 'no-image' : '' ?>">
                                        <?php if (trim((string) ($request['product_image'] ?? '')) !== ''): ?>
                                            <img src="<?= htmlspecialchars((string) ($request['product_image'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string) ($request['product_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                        <?php endif; ?>
                                        <div>
                                            <h3 class="order-focus-title"><?= htmlspecialchars((string) ($request['product_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h3>
                                            <p class="order-focus-client"><?= htmlspecialchars((string) ($request['customer'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                                            <p>Qty <?= (int) ($request['quantity'] ?? 1) ?> - <?= htmlspecialchars((string) ($request['preferred_size'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                                            <?php if ((float) ($request['quotation_price'] ?? 0) > 0): ?>
                                                <?php $payment = appCustomizationPaymentSummary($request); ?>
                                                <p>Total: P<?= number_format($payment['total'], 2) ?> · Down payment: P<?= number_format($payment['down_payment'], 2) ?> · Balance due: P<?= number_format($payment['balance'], 2) ?></p>
                                                <?php if ($payment['received'] === 0.0): ?><p>After down payment: P<?= number_format($payment['after_down_payment'], 2) ?> remaining</p><?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </section>
                <?php else: ?>
                    <section class="order-detail-shell">
                        <a class="order-back-link" href="admin.php?section=customizations">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M15 18 9 12l6-6"/>
                            </svg>
                             Back to requests
                         </a>
                         <section class="panel">
                            <div class="section-title">
                                <h2>Customization Request</h2>
                                <?php $awaitingPayment = ($selectedCustomizationRequest['status'] ?? '') === 'Down Payment Paid' && empty($selectedCustomizationRequest['payment_confirmed']); ?>
                                <span class="status-pill <?= htmlspecialchars($awaitingPayment ? 'processing' : (string) ($selectedCustomizationRequest['tone'] ?? customizationTone((string) ($selectedCustomizationRequest['status'] ?? 'Pending'))), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($awaitingPayment ? 'Payment pending confirmation' : (string) ($selectedCustomizationRequest['status'] ?? 'Pending'), ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <div class="customization-media <?= trim((string) ($selectedCustomizationRequest['product_image'] ?? '')) === '' ? 'no-image' : '' ?>">
                                <?php if (trim((string) ($selectedCustomizationRequest['product_image'] ?? '')) !== ''): ?>
                                    <img src="<?= htmlspecialchars((string) ($selectedCustomizationRequest['product_image'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string) ($selectedCustomizationRequest['product_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <?php endif; ?>
                                <div>
                                    <h3 class="order-focus-title"><?= htmlspecialchars((string) ($selectedCustomizationRequest['product_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h3>
                                    <p class="order-focus-client"><?= htmlspecialchars((string) ($selectedCustomizationRequest['customer'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                                    <p><?= htmlspecialchars((string) ($selectedCustomizationRequest['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                            </div>
                            <div class="customization-detail-grid">
                                <p><strong>Size</strong><?= htmlspecialchars((string) ($selectedCustomizationRequest['preferred_size'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                                <p><strong>Color</strong><?= htmlspecialchars((string) ($selectedCustomizationRequest['color'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                                <p><strong>Material</strong><?= htmlspecialchars((string) ($selectedCustomizationRequest['material'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                                <p><strong>Quantity</strong><?= (int) ($selectedCustomizationRequest['quantity'] ?? 1) ?></p>
                            </div>
                            <?php $payment = appCustomizationPaymentSummary($selectedCustomizationRequest); ?>
                            <?php if ($payment['total'] > 0): ?>
                                <div class="order-totals" aria-label="Customization payment breakdown">
                                    <span>Total quotation: <strong>P<?= number_format($payment['total'], 2) ?></strong></span>
                                    <span>Down payment required: <strong>P<?= number_format($payment['down_payment'], 2) ?></strong></span>
                                    <span>Down payment received: <strong>P<?= number_format($payment['down_received'], 2) ?></strong></span>
                                    <span>Total received: <strong>P<?= number_format($payment['received'], 2) ?></strong></span>
                                    <span>Remaining balance: <strong>P<?= number_format($payment['balance'], 2) ?></strong></span>
                                    <?php if ($payment['received'] === 0.0): ?><span>Balance after down payment: <strong>P<?= number_format($payment['after_down_payment'], 2) ?></strong></span><?php endif; ?>
                                </div>
                            <?php else: ?><p>Payment amounts will appear after the quotation is sent.</p><?php endif; ?>
                            <?php
                                $paymentConfirmed = !empty($selectedCustomizationRequest['payment_confirmed']);
                                $progressStep = appCustomizationProgressStep((string) ($selectedCustomizationRequest['status'] ?? 'Pending'), $paymentConfirmed);
                                 $currentStep = in_array((string) ($selectedCustomizationRequest['status'] ?? ''), ['Approved', 'Down Payment Paid'], true) && !$paymentConfirmed ? 3 : $progressStep;
                            ?>
                                 <ol class="customization-progress" aria-label="Customization progress">
                                     <?php foreach (['Request sent', 'Quotation sent', 'Accepted', 'Payment received', 'Ongoing', 'Ready', 'Finished'] as $stepIndex => $stepLabel): ?>
                                     <?php $canConfirmStep = $stepIndex === 3 && in_array((string) ($selectedCustomizationRequest['status'] ?? ''), ['Approved', 'Down Payment Paid'], true) && !$paymentConfirmed; ?>
                                     <li class="<?= $progressStep >= 0 && $stepIndex <= $progressStep ? 'is-done' : '' ?> <?= $stepIndex === $currentStep ? 'is-current' : '' ?> <?= $canConfirmStep ? 'is-action' : '' ?>" <?= $stepIndex === $currentStep ? 'aria-current="step"' : '' ?>>
                                         <?php if ($canConfirmStep): ?>
                                             <form method="post" onsubmit="return confirm('Confirm that the down payment was received?');">
                                                 <input type="hidden" name="action" value="confirm_customization_payment">
                                                 <input type="hidden" name="request_id" value="<?= htmlspecialchars((string) ($selectedCustomizationRequest['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                                 <button type="submit" aria-label="Confirm Payment Received">Payment received · Confirm</button>
                                             </form>
                                         <?php else: ?><?= htmlspecialchars($stepLabel, ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
                                     </li>
                                     <?php endforeach; ?>
                                </ol>
                            <?php if ($payment['total'] > 0 && $paymentConfirmed): ?>
                                <?php if ($payment['full_payment_confirmed'] || $payment['balance'] <= 0): ?>
                                    <p class="status-pill accepted">Full Payment Received</p>
                                <?php elseif (!in_array((string) ($selectedCustomizationRequest['status'] ?? ''), ['Cancelled', 'Unclaimed'], true)): ?>
                                    <form method="post" onsubmit="return confirm('Confirm that the full remaining balance of P<?= number_format($payment['balance'], 2) ?> was received?');">
                                        <input type="hidden" name="action" value="confirm_customization_full_payment">
                                        <input type="hidden" name="request_id" value="<?= htmlspecialchars((string) ($selectedCustomizationRequest['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                        <button class="btn btn-primary" type="submit">Confirm Full Payment Received · P<?= number_format($payment['balance'], 2) ?></button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                            <p>Last updated: <?= htmlspecialchars((string) ($selectedCustomizationRequest['updated_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                            <?php if (trim((string) ($selectedCustomizationRequest['reference_image'] ?? '')) !== ''): ?>
                                <p><button class="btn btn-soft" type="button" data-custom-image="<?= htmlspecialchars((string) ($selectedCustomizationRequest['reference_image'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-image-label="Reference Image">View Reference Image / Zoom</button></p>
                            <?php endif; ?>
                            <?php if (trim((string) ($selectedCustomizationRequest['payment_proof'] ?? '')) !== ''): ?>
                                <p><button class="btn btn-soft" type="button" data-custom-image="<?= htmlspecialchars((string) ($selectedCustomizationRequest['payment_proof'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-image-label="Payment Proof">View Payment Proof</button></p>
                            <?php endif; ?>
                            <?php if (in_array((string) ($selectedCustomizationRequest['status'] ?? ''), ['Pending', 'Quotation Sent'], true)): ?>
                                <form class="form-grid" method="post">
                                    <input type="hidden" name="action" value="send_customization_quote">
                                    <input type="hidden" name="request_id" value="<?= htmlspecialchars((string) ($selectedCustomizationRequest['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                    <div class="two-col">
                                        <div class="field">
                                            <label>Quotation Price</label>
                                            <input type="number" name="quotation_price" min="1" step="0.01" value="<?= htmlspecialchars((string) ($selectedCustomizationRequest['quotation_price'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                                        </div>
                                        <div class="field">
                                            <label>Required Down Payment</label>
                                            <input type="number" name="down_payment" min="0.01" step="0.01" value="<?= htmlspecialchars((string) ($selectedCustomizationRequest['down_payment'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                                        </div>
                                    </div>
                                    <div class="field">
                                        <label>Estimated Completion Date</label>
                                        <input type="date" name="estimated_completion_date" value="<?= htmlspecialchars((string) ($selectedCustomizationRequest['estimated_completion_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                                    </div>
                                    <div class="field">
                                        <label>Notes</label>
                                        <textarea name="admin_notes"><?= htmlspecialchars((string) ($selectedCustomizationRequest['admin_notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                                    </div>
                                    <button class="btn btn-primary" type="submit">Send Quotation</button>
                                </form>
                            <?php endif; ?>
                            <?php if (!in_array((string) ($selectedCustomizationRequest['status'] ?? ''), ['Completed', 'Cancelled', 'Unclaimed'], true)): ?>
                                <?php $customStatus = (string) ($selectedCustomizationRequest['status'] ?? ''); ?>
                                <form class="order-form" method="post" onsubmit="return !['Cancelled','Unclaimed'].includes(this.elements.status.value) || confirm('Close this customization request?');">
                                    <input type="hidden" name="action" value="update_customization_status">
                                    <input type="hidden" name="request_id" value="<?= htmlspecialchars((string) ($selectedCustomizationRequest['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                    <select name="status" required aria-label="Choose customization action">
                                        <option value="" disabled selected>Choose action</option>
                                        <?php if ($customStatus === 'Quotation Sent'): ?><option value="Approved">Approved (after quotation)</option><?php endif; ?>
                                        <?php if ($customStatus === 'Down Payment Paid' && $paymentConfirmed): ?><option value="Ongoing">Ongoing</option><?php endif; ?>
                                        <?php if (in_array($customStatus, ['Ongoing', 'In Production'], true)): ?><option value="Ready">Ready</option><?php endif; ?>
                                        <?php if ($customStatus === 'Ready'): ?><option value="Completed">Finished</option><?php endif; ?>
                                        <option value="Cancelled">Cancelled</option>
                                        <option value="Unclaimed">Unclaimed</option>
                                    </select>
                                    <button class="btn btn-primary" type="submit">Apply</button>
                                </form>
                            <?php endif; ?>
                            <form method="post" style="margin-top:14px;" onsubmit="return confirm('Delete this customization order? You can restore it from Backup History.');">
                                <input type="hidden" name="action" value="delete_customization_request_admin">
                                <input type="hidden" name="backup_csrf" value="<?= htmlspecialchars((string) $_SESSION['backup_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="request_id" value="<?= htmlspecialchars((string) ($selectedCustomizationRequest['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <button class="btn btn-danger" type="submit">Delete Customization Order</button>
                            </form>
                        </section>
                         <section class="panel">
                             <div class="section-title"><h2>Customization Messages</h2></div>
                             <div class="admin-chat-thread">
                                 <?php foreach ($selectedCustomizationMessages as $message): ?>
                                     <?php $isOutgoing = strcasecmp((string) ($message['from_email'] ?? ''), $adminEmail) === 0; ?>
                                     <article class="admin-chat-bubble <?= $isOutgoing ? 'outgoing' : 'incoming' ?>">
                                         <?php if (trim((string) ($message['image_path'] ?? '')) !== ''): ?>
                                             <a href="<?= htmlspecialchars((string) $message['image_path'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><img class="admin-chat-image" src="<?= htmlspecialchars((string) $message['image_path'], ENT_QUOTES, 'UTF-8') ?>" alt="Customization attachment"></a>
                                         <?php endif; ?>
                                         <?php if (appDisplayChatMessage((string) ($message['message'] ?? '')) !== ''): ?><p><?= nl2br(htmlspecialchars(appDisplayChatMessage((string) $message['message']), ENT_QUOTES, 'UTF-8')) ?></p><?php endif; ?>
                                         <div class="admin-chat-meta"><span><?= $isOutgoing ? 'Admin' : htmlspecialchars((string) ($message['from_name'] ?? 'User'), ENT_QUOTES, 'UTF-8') ?></span><span><?= htmlspecialchars((string) ($message['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span></div>
                                     </article>
                                 <?php endforeach; ?>
                             </div>
                              <form class="admin-chat-compose" method="post" enctype="multipart/form-data">
                                 <input type="hidden" name="action" value="admin_send_message">
                                 <input type="hidden" name="customization_id" value="<?= htmlspecialchars((string) ($selectedCustomizationRequest['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                 <input type="hidden" name="recipient_email" value="<?= htmlspecialchars((string) ($selectedCustomizationRequest['customer_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                 <input type="hidden" name="product_id" value="<?= htmlspecialchars((string) ($selectedCustomizationRequest['product_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                 <textarea name="message" placeholder="Reply about this customization..."></textarea>
                                 <label class="admin-chat-attach">Attach image <input type="file" name="message_image" accept="image/*"></label>
                                 <div class="admin-chat-compose-foot"><span>Only this customization order will show this message.</span><button class="btn btn-primary" type="submit">Send Message</button></div>
                              </form>
                          </section>
                          <details class="customer-disclosure">
                              <summary class="btn btn-soft">View Customer Info</summary>
                              <section class="panel client-card">
                                  <div class="section-title"><h2>Client Information</h2></div>
                                  <div class="client-info-grid">
                                      <div class="client-info-item"><label>Name</label><strong><?= htmlspecialchars((string) ($selectedCustomizationRequest['customer'] ?? 'Not provided'), ENT_QUOTES, 'UTF-8') ?></strong></div>
                                      <div class="client-info-item"><label>Phone Number</label><strong><?= htmlspecialchars($selectedCustomizationClientPhone !== '' ? $selectedCustomizationClientPhone : 'Not provided', ENT_QUOTES, 'UTF-8') ?></strong></div>
                                      <div class="client-info-item"><label>Email Address</label><strong><?= htmlspecialchars((string) ($selectedCustomizationRequest['customer_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong></div>
                                      <div class="client-info-item"><label>Delivery Address</label><strong><?= htmlspecialchars((string) ($selectedCustomizationRequest['delivery_address'] ?? 'Not provided'), ENT_QUOTES, 'UTF-8') ?></strong></div>
                                  </div>
                              </section>
                          </details>
                      </section>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($section === 'profile'): ?>
                <div class="section-title">
                    <h1>Admin Profile</h1>
                </div>
                <section class="panel admin-profile-shell">
                    <div class="admin-profile-hero">
                        <div class="admin-profile-head">
                            <div class="admin-profile-avatar">
                                <?php if ($adminProfileImage !== ''): ?>
                                    <img src="<?= htmlspecialchars($adminProfileImage, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($adminProfileName, ENT_QUOTES, 'UTF-8') ?>">
                                <?php else: ?>
                                    <span><?= htmlspecialchars($adminProfileInitial, ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="admin-profile-copy">
                                <strong><?= htmlspecialchars($adminProfileName, ENT_QUOTES, 'UTF-8') ?></strong>
                                <span><?= htmlspecialchars(strtoupper($adminProfileRole), ENT_QUOTES, 'UTF-8') ?> account</span>
                                <p>Manage your admin information, catalog settings, and sign out here.</p>
                            </div>
                        </div>
                        <div class="admin-profile-info">
                            <div class="admin-profile-info-item">
                                <label>Admin Email</label>
                                <strong><?= htmlspecialchars($adminProfileEmail !== '' ? $adminProfileEmail : 'Not provided', ENT_QUOTES, 'UTF-8') ?></strong>
                            </div>
                            <div class="admin-profile-info-item">
                                <label>Admin Number</label>
                                <strong><?= htmlspecialchars($adminProfilePhone !== '' ? $adminProfilePhone : 'Not provided', ENT_QUOTES, 'UTF-8') ?></strong>
                            </div>
                            <div class="admin-profile-info-item">
                                <label>Admin Address</label>
                                <span><?= htmlspecialchars($adminProfileAddress !== '' ? $adminProfileAddress : 'Not provided', ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <div class="admin-profile-info-item">
                                <label>Admin Info</label>
                                <span><?= htmlspecialchars($adminProfileRole !== '' ? ucfirst($adminProfileRole) : 'Admin', ENT_QUOTES, 'UTF-8') ?> account for RN Furniture management.</span>
                            </div>
                        </div>
                        <div class="admin-profile-actions">
                            <a class="btn btn-soft" href="admin.php?section=profile&edit_popup=1">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M17 3a2.83 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/>
                                    <path d="m15 5 4 4"/>
                                </svg>
                                Edit Info
                            </a>
                            <a class="btn btn-primary" href="admin.php?section=profile&settings_popup=1">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="3"/>
                                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                                </svg>
                                Settings
                            </a>
                        </div>
                    </div>
                </section>
                <section class="panel">
                    <div class="section-title">
                        <h2>Logout</h2>
                    </div>
                    <a class="btn btn-danger profile-logout-btn" href="admin.php?logout=1">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                            <polyline points="10 17 15 12 10 7"/>
                            <line x1="15" y1="12" x2="3" y2="12"/>
                        </svg>
                        Logout
                    </a>
                </section>
            <?php endif; ?>
        </main>

        <?php if ($section === 'dashboard' && $selectedStatPopup !== ''): ?>
            <div class="mini-popup-overlay">
                <section class="mini-popup">
                    <?php if ($selectedStatPopup === 'total'): ?>
                        <h3>Total Orders</h3>
                        <p><?= count($orders) ?> total orders recorded in the system.</p>
                    <?php elseif ($selectedStatPopup === 'revenue'): ?>
                        <h3>Total Revenue</h3>
                        <p>P<?= number_format($revenue, 2) ?> total revenue from completed orders only.</p>
                    <?php elseif ($selectedStatPopup === 'products'): ?>
                        <h3>Total Products</h3>
                        <p><?= count($products) ?> product(s) currently listed in the inventory.</p>
                    <?php elseif ($selectedStatPopup === 'users'): ?>
                        <h3>Total User</h3>
                        <p><?= count($store['users']) ?> registered user(s) in the system.</p>
                    <?php elseif ($selectedStatPopup === 'messages'): ?>
                        <h3>User Messages</h3>
                        <p>Open the full Messages section to continue the Messenger-style conversation.</p>
                    <?php endif; ?>
                    <a class="btn btn-primary" href="<?= $selectedStatPopup === 'messages' ? 'admin.php?section=messages' : 'admin.php?section=dashboard' ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <?php if ($selectedStatPopup === 'messages'): ?>
                                <path d="M4 6h16v10H7l-3 3V6Z"/>
                            <?php else: ?>
                                <line x1="18" y1="6" x2="6" y2="18"/>
                                <line x1="6" y1="6" x2="18" y2="18"/>
                            <?php endif; ?>
                        </svg>
                        <?= $selectedStatPopup === 'messages' ? 'Open Messages' : 'Close' ?>
                    </a>
                </section>
            </div>
        <?php endif; ?>

        <?php if ($section === 'profile' && $showProfileSettingsPopup): ?>
            <div class="mini-popup-overlay">
                <section class="settings-popup">
                    <div class="settings-popup-head">
                        <h2>Settings</h2>
                        <a class="settings-popup-close" href="admin.php?section=profile" aria-label="Close settings popup">×</a>
                    </div>
                    <?php if ($notice !== ''): ?>
                        <div class="notice <?= htmlspecialchars($noticeType, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <div class="language-setting">
                        <strong>Language</strong>
                        <div class="language-choices" role="group" aria-label="Language">
                            <button type="button" data-language-choice="en" aria-pressed="true">English</button>
                            <button type="button" data-language-choice="tl" aria-pressed="false">Tagalog</button>
                        </div>
                    </div>
                    <section class="panel" style="margin-bottom:14px;">
                        <div class="section-title">
                            <h2>Slider Images</h2>
                        </div>
                        <?php foreach ((array) ($store['settings']['slider']['images'] ?? []) as $sliderImage): ?>
                            <form method="post" style="display:inline-block;position:relative;width:calc(50% - 6px);margin:0 6px 10px 0;vertical-align:top;">
                                <input type="hidden" name="action" value="remove_slider_image">
                                <input type="hidden" name="slider_image_path" value="<?= htmlspecialchars($sliderImage, ENT_QUOTES, 'UTF-8') ?>">
                                <img src="<?= htmlspecialchars($sliderImage, ENT_QUOTES, 'UTF-8') ?>" alt="Slider image" style="display:block;width:100%;height:110px;object-fit:contain;border:1px solid #d7dce8;">
                                <button class="btn" type="submit" title="Remove image" aria-label="Remove image" style="position:absolute;top:4px;right:4px;padding:5px 9px;">&times;</button>
                            </form>
                        <?php endforeach; ?>
                        <form method="post" enctype="multipart/form-data" class="form-grid">
                            <input type="hidden" name="action" value="update_slider_settings">
                            <div class="field">
                                <label for="sliderImage">Image</label>
                                <input id="sliderImage" type="file" name="slider_image" accept="image/jpeg,image/png,image/webp,image/gif" required>
                            </div>
                            <div class="actions">
                                <button class="btn btn-primary" type="submit">Add Image</button>
                            </div>
                        </form>
                    </section>
                    <section class="panel" style="margin-bottom:14px;">
                        <div class="section-title">
                            <h2>Categories</h2>
                        </div>
                        <form method="post" class="actions" style="margin-bottom:12px;">
                            <input type="hidden" name="action" value="add_category">
                            <input type="text" name="category_name" placeholder="Add category" style="flex:1; border:1px solid var(--line); border-radius:12px; padding:11px 12px; font:inherit; font-size:0.88rem;" required>
                            <button class="btn btn-primary" type="submit">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="12" y1="5" x2="12" y2="19"/>
                                    <line x1="5" y1="12" x2="19" y2="12"/>
                                </svg>
                                Add
                            </button>
                        </form>
                        <div class="tag-list">
                            <?php foreach ($categoryOptions as $option): ?>
                                <span class="tag-item">
                                    <?php if (($option['icon'] ?? '') !== ''): ?>
                                        <?= renderCategoryIcon((string) ($option['name'] ?? '')) ?>
                                    <?php endif; ?>
                                    <?= htmlspecialchars($option['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                    <form method="post">
                                        <input type="hidden" name="action" value="delete_category_icon">
                                        <input type="hidden" name="category_name" value="<?= htmlspecialchars($option['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                        <button class="tag-delete" type="submit" title="Remove icon">⊖</button>
                                    </form>
                                    <form method="post">
                                        <input type="hidden" name="action" value="delete_category">
                                        <input type="hidden" name="category_name" value="<?= htmlspecialchars($option['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                        <button class="tag-delete" type="submit">×</button>
                                    </form>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <section class="panel" style="margin-bottom:0;">
                        <div class="section-title">
                            <h2>Materials</h2>
                        </div>
                        <form method="post" class="actions" style="margin-bottom:12px;">
                            <input type="hidden" name="action" value="add_material">
                            <input type="text" name="material_name" placeholder="Add material" style="flex:1; border:1px solid var(--line); border-radius:12px; padding:11px 12px; font:inherit; font-size:0.88rem;" required>
                            <button class="btn btn-primary" type="submit">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="12" y1="5" x2="12" y2="19"/>
                                    <line x1="5" y1="12" x2="19" y2="12"/>
                                </svg>
                                Add
                            </button>
                        </form>
                        <div class="tag-list">
                            <?php foreach ($materialOptions as $option): ?>
                                <span class="tag-item">
                                    <?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>
                                    <form method="post">
                                        <input type="hidden" name="action" value="delete_material">
                                        <input type="hidden" name="material_name" value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>">
                                        <button class="tag-delete" type="submit">×</button>
                                    </form>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <section class="panel" style="margin-top:14px;">
                        <div class="section-title backup-brand-title"><span class="backup-brand-mark" aria-label="RN Furniture logo">RN</span><h2>Backup &amp; Restore</h2></div>
                        <p class="stat-label">Gumagawa ang admin ng backup copy ng buong system database, kabilang ang customer accounts, furniture products, orders, customization requests, payments, at order tracking records.</p>
                        <form method="post" class="actions" style="margin-bottom:12px;">
                            <input type="hidden" name="action" value="create_backup">
                            <input type="hidden" name="backup_csrf" value="<?= htmlspecialchars((string) $_SESSION['backup_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                            <button class="btn btn-primary" type="submit">Create Backup</button>
                        </form>
                        <details class="backup-disclosure backup-history-toggle">
                            <summary>Backup History</summary>
                            <p class="stat-label">All successfully created backups are listed below.</p>
                            <?php $savedBackups = appBackupHistory(); ?>
                            <?php if ($savedBackups === []): ?>
                                <p class="stat-label">No backup files yet.</p>
                            <?php else: ?>
                                <div class="backup-table-wrap">
                                    <table class="backup-history-table">
                                        <thead><tr><th>Backup File</th><th>Date &amp; Time</th><th>File Size</th><th>Action</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($savedBackups as $backupFile): ?>
                                                <?php $backupName = basename($backupFile); $backupDate = appBackupCreatedAt($backupFile); $backupSize = filesize($backupFile); ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($backupName, ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td><time datetime="<?= htmlspecialchars($backupDate->format(DATE_ATOM), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($backupDate->format('M. j, Y – g:i A'), ENT_QUOTES, 'UTF-8') ?></time></td>
                                                    <td><?= $backupSize < 1048576 ? number_format($backupSize / 1024, 1) . ' KB' : number_format($backupSize / 1048576, 1) . ' MB' ?></td>
                                                    <td>
                                                        <div class="backup-table-actions">
                                                            <a class="btn btn-soft" href="admin.php?download_saved_backup=<?= urlencode($backupName) ?>" download="<?= htmlspecialchars($backupName, ENT_QUOTES, 'UTF-8') ?>">Download</a>
                                                            <form method="post" onsubmit="return confirm('Restore this backup? Current data will be saved to a new backup first.');">
                                                                <input type="hidden" name="action" value="restore_backup">
                                                                <input type="hidden" name="backup_csrf" value="<?= htmlspecialchars((string) $_SESSION['backup_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                                                                <input type="hidden" name="backup_name" value="<?= htmlspecialchars($backupName, ENT_QUOTES, 'UTF-8') ?>">
                                                                <button class="btn btn-soft" type="submit">Restore</button>
                                                            </form>
                                                            <form method="post" onsubmit="return confirm('Delete this backup file? This cannot be undone.');">
                                                                <input type="hidden" name="action" value="delete_backup">
                                                                <input type="hidden" name="backup_csrf" value="<?= htmlspecialchars((string) $_SESSION['backup_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                                                                <input type="hidden" name="backup_name" value="<?= htmlspecialchars($backupName, ENT_QUOTES, 'UTF-8') ?>">
                                                                <button class="btn btn-danger" type="submit">Delete</button>
                                                            </form>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </details>
                    </section>
                    <section class="panel" style="margin-top:14px;">
                        <details class="backup-disclosure">
                            <summary>Trash (<?= count($store['deleted_records'] ?? []) + count($deletedChatThreads) ?>)</summary>
                            <details class="backup-disclosure">
                                <summary>Deleted Items (<?= count($store['deleted_records'] ?? []) ?>)</summary>
                                <?php if (empty($store['deleted_records'])): ?>
                                    <p class="stat-label">No deleted products or orders saved yet.</p>
                                <?php else: ?>
                                    <?php foreach ($store['deleted_records'] as $deletedEntry): ?>
                                        <?php $deletedRecord = (array) ($deletedEntry['record'] ?? []); $deletedType = (string) ($deletedEntry['type'] ?? 'item'); $deletedLabel = (string) ($deletedRecord['product_name'] ?? $deletedRecord['name'] ?? $deletedRecord['id'] ?? 'Item'); ?>
                                        <div class="actions backup-row">
                                            <span class="backup-meta"><strong><?= htmlspecialchars(ucfirst($deletedType), ENT_QUOTES, 'UTF-8') ?>: <?= htmlspecialchars($deletedLabel, ENT_QUOTES, 'UTF-8') ?></strong><br><small><?= htmlspecialchars((string) ($deletedEntry['deleted_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small></span>
                                            <form method="post" onsubmit="return confirm('Restore this deleted item?');">
                                                <input type="hidden" name="action" value="restore_deleted_record">
                                                <input type="hidden" name="backup_csrf" value="<?= htmlspecialchars((string) $_SESSION['backup_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="archive_id" value="<?= htmlspecialchars((string) ($deletedEntry['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                                <button class="btn btn-soft" type="submit">Restore</button>
                                            </form>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </details>
                            <?php if ($deletedChatThreads !== []): ?>
                                <details class="backup-disclosure">
                                    <summary>Archived Conversations (<?= count($deletedChatThreads) ?>)</summary>
                                    <?php foreach ($deletedChatThreads as $thread): ?>
                                        <div class="actions backup-row">
                                            <span class="backup-meta"><strong><?= htmlspecialchars((string) ($thread['name'] ?? 'User'), ENT_QUOTES, 'UTF-8') ?></strong><br><small><?= htmlspecialchars((string) ($thread['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small></span>
                                            <form method="post">
                                                <input type="hidden" name="action" value="restore_chat_thread">
                                                <input type="hidden" name="thread_email" value="<?= htmlspecialchars((string) ($thread['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                                <button class="btn btn-soft" type="submit">Restore</button>
                                            </form>
                                        </div>
                                    <?php endforeach; ?>
                                </details>
                            <?php endif; ?>
                        </details>
                    </section>
                </section>
            </div>
        <?php endif; ?>

        <?php if ($section === 'profile' && $showAdminEditPopup): ?>
            <div class="mini-popup-overlay">
                <section class="settings-popup">
                    <div class="settings-popup-head">
                        <h2>Edit Admin Info</h2>
                        <a class="settings-popup-close" href="admin.php?section=profile" aria-label="Close edit admin popup">×</a>
                    </div>
                    <form class="form-grid" method="post">
                        <input type="hidden" name="action" value="update_admin_profile">
                        <div class="field">
                            <label for="adminEditName">Admin Name</label>
                            <input id="adminEditName" type="text" name="admin_name" value="<?= htmlspecialchars($adminProfileName, ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div class="field">
                            <label for="adminEditEmail">Admin Email</label>
                            <input id="adminEditEmail" type="email" name="admin_email" value="<?= htmlspecialchars($adminProfileEmail, ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div class="field">
                            <label for="adminEditPhone">Admin Number</label>
                            <input id="adminEditPhone" type="text" name="admin_phone" value="<?= htmlspecialchars($adminProfilePhone, ENT_QUOTES, 'UTF-8') ?>" placeholder="Enter admin number">
                        </div>
                        <div class="field">
                            <label for="adminEditAddress">Admin Address</label>
                            <textarea id="adminEditAddress" name="admin_address" placeholder="Enter admin address"><?= htmlspecialchars($adminProfileAddress, ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>
                        <div class="actions">
                            <button class="btn btn-primary" type="submit">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M17 3a2.83 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/>
                                    <path d="m15 5 4 4"/>
                                </svg>
                                Save
                            </button>
                            <a class="btn btn-soft" href="admin.php?section=profile">Cancel</a>
                        </div>
                    </form>
                </section>
            </div>
        <?php endif; ?>

        <?php if ($section === 'messages' && $activeChat !== null): ?>
            <div class="mini-popup-overlay" id="chatActionSheet" hidden>
                <section class="action-sheet">
                    <h3>Chat Actions</h3>
                    <p>Move this read conversation to Deleted Chats. You can restore it later.</p>
                    <form method="post" onsubmit="return confirm('Move this chat to Deleted Chats?');">
                        <input type="hidden" name="action" value="delete_chat_thread">
                        <input type="hidden" name="thread_email" value="<?= htmlspecialchars((string) ($activeChat['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        <button class="btn btn-danger" type="submit">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="3 6 5 6 21 6"/>
                                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                <line x1="10" y1="11" x2="10" y2="17"/>
                                <line x1="14" y1="11" x2="14" y2="17"/>
                            </svg>
                            Move to Deleted Chats
                        </button>
                    </form>
                    <button class="btn btn-soft" type="button" data-close-sheet>Cancel</button>
                </section>
            </div>
        <?php endif; ?>

        <input class="nav-toggle-input" type="checkbox" id="adminMobileNavToggle" aria-hidden="true">
        <label class="mobile-menu-toggle" for="adminMobileNavToggle" aria-label="Open navigation menu"><span></span></label>
        <nav class="bottom-nav" aria-label="Admin navigation">
            <a class="<?= $section === 'dashboard' ? 'active' : '' ?>" href="admin.php?section=dashboard">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
                <span>Home</span>
            </a>
            <a class="<?= $section === 'messages' ? 'active' : '' ?>" href="admin.php?section=messages">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.5 8.5 0 0 1-8.5 8.5A8.4 8.4 0 0 1 8 18.7L3 20l1.3-5A8.4 8.4 0 0 1 3 11.5 8.5 8.5 0 0 1 11.5 3h1A8.5 8.5 0 0 1 21 11.5Z"></path></svg>
                <span>Messages</span>
                <?php if ($adminUnreadMessageCount > 0): ?><span class="admin-nav-badge" aria-label="<?= $adminUnreadMessageCount ?> unread messages"><?= $adminUnreadMessageCount ?></span><?php endif; ?>
            </a>
            <a class="<?= $section === 'products' ? 'active' : '' ?>" href="admin.php?section=products">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
                <span>Products</span>
            </a>
            <a class="<?= in_array($section, ['orders', 'customizations'], true) ? 'active' : '' ?>" href="admin.php?section=orders">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                <span>Orders</span>
                <?php if ($adminUnreadCustomizationCount > 0): ?><span class="admin-nav-badge" aria-label="<?= $adminUnreadCustomizationCount ?> unread customization messages"><?= $adminUnreadCustomizationCount ?></span><?php endif; ?>
            </a>
            <a class="<?= $section === 'profile' ? 'active' : '' ?>" href="admin.php?section=profile">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
                <span>Admin</span>
            </a>
        </nav>
    </div>
    <?php if ($restoreDone): ?>
        <div class="restore-done-overlay" role="alertdialog" aria-modal="true" aria-label="Restore complete">
            <div class="restore-done-card">
                <p>Restore done.</p>
                <button class="btn btn-primary" type="button" onclick="this.closest('.restore-done-overlay').remove()">OK</button>
            </div>
        </div>
    <?php endif; ?>
    <dialog class="customization-image-dialog" id="customizationImageDialog" aria-label="Customization image">
        <button class="btn btn-soft" type="button" id="customizationImageBack">Back</button>
        <button class="btn btn-soft" type="button" id="customizationImageZoom">Zoom In</button>
        <h2 id="customizationImageLabel"></h2>
        <img id="customizationImagePreview" alt="">
    </dialog>
    <script>
        (function () {
            let hasFocusedField = false;
            let hasPendingChanges = false;
            const fields = Array.from(document.querySelectorAll('input, textarea, select'));
            const imageInput = document.getElementById('image');
            const imagePreviewCard = document.getElementById('imagePreviewCard');
            const imagePreview = document.getElementById('imagePreview');
            const imagePreviewName = document.getElementById('imagePreviewName');
            const imagePreviewMeta = document.getElementById('imagePreviewMeta');
            const chatActionSheet = document.getElementById('chatActionSheet');
            const openSheetButtons = Array.from(document.querySelectorAll('[data-open-sheet]'));
            const longPressTargets = Array.from(document.querySelectorAll('[data-long-press]'));
            const closeSheetButtons = Array.from(document.querySelectorAll('[data-close-sheet]'));
            const tappableElements = Array.from(document.querySelectorAll('a, button'));
            const customizationImageDialog = document.getElementById('customizationImageDialog');
            const customizationImagePreview = document.getElementById('customizationImagePreview');
            const customizationImageLabel = document.getElementById('customizationImageLabel');
            const customizationImageBack = document.getElementById('customizationImageBack');
            const customizationImageZoom = document.getElementById('customizationImageZoom');

            document.querySelectorAll('[data-custom-image]').forEach(function (button) {
                button.addEventListener('click', function () {
                    customizationImagePreview.src = button.dataset.customImage || '';
                    customizationImagePreview.alt = button.dataset.imageLabel || 'Customization image';
                    customizationImageLabel.textContent = customizationImagePreview.alt;
                    customizationImageDialog.classList.remove('is-zoomed');
                    customizationImageZoom.textContent = 'Zoom In';
                    customizationImageDialog.showModal();
                });
            });
            customizationImageBack.addEventListener('click', function () {
                customizationImageDialog.close();
            });
            customizationImageZoom.addEventListener('click', function () {
                const zoomed = customizationImageDialog.classList.toggle('is-zoomed');
                customizationImageZoom.textContent = zoomed ? 'Zoom Out' : 'Zoom In';
            });
            customizationImageDialog.addEventListener('close', function () {
                customizationImagePreview.removeAttribute('src');
            });

            tappableElements.forEach(function (element) {
                element.classList.add('ios-tap');

                const pressStart = function () {
                    element.classList.add('is-pressed');
                };
                const pressEnd = function () {
                    window.setTimeout(function () {
                        element.classList.remove('is-pressed');
                    }, 120);
                };

                element.addEventListener('pointerdown', pressStart);
                element.addEventListener('pointerup', pressEnd);
                element.addEventListener('pointercancel', pressEnd);
                element.addEventListener('pointerleave', pressEnd);
            });

            fields.forEach(function (field) {
                field.addEventListener('focus', function () {
                    hasFocusedField = true;
                });

                field.addEventListener('blur', function () {
                    hasFocusedField = false;
                });

                field.addEventListener('input', function () {
                    hasPendingChanges = true;
                });

                field.addEventListener('change', function () {
                    hasPendingChanges = true;
                });
            });

            <?php if ($section !== 'profile'): ?>
            setInterval(function () {
                const sheetOpen = chatActionSheet && !chatActionSheet.hasAttribute('hidden');
                if (!hasFocusedField && !hasPendingChanges && !sheetOpen && !customizationImageDialog.open && !document.querySelector('.mini-popup-overlay')) {
                    window.location.reload();
                }
            }, 8000);
            <?php endif; ?>

            if (chatActionSheet) {
                openSheetButtons.forEach(function (button) {
                    button.addEventListener('click', function () {
                        chatActionSheet.removeAttribute('hidden');
                    });
                });
                closeSheetButtons.forEach(function (button) {
                    button.addEventListener('click', function () {
                        chatActionSheet.setAttribute('hidden', 'hidden');
                    });
                });

                chatActionSheet.addEventListener('click', function (event) {
                    if (event.target === chatActionSheet) {
                        chatActionSheet.setAttribute('hidden', 'hidden');
                    }
                });
            }

            longPressTargets.forEach(function (target) {
                let longPressTimer = null;
                let longPressTriggered = false;

                const openSheet = function () {
                    const sheetId = target.getAttribute('data-long-press');
                    const sheet = sheetId ? document.getElementById(sheetId) : null;
                    if (!sheet) {
                        return;
                    }

                    sheet.removeAttribute('hidden');
                    longPressTriggered = true;
                };

                const clearLongPress = function () {
                    if (longPressTimer !== null) {
                        window.clearTimeout(longPressTimer);
                        longPressTimer = null;
                    }
                };

                target.addEventListener('pointerdown', function () {
                    longPressTriggered = false;
                    clearLongPress();
                    longPressTimer = window.setTimeout(openSheet, 520);
                });

                ['pointerup', 'pointerleave', 'pointercancel', 'pointermove'].forEach(function (eventName) {
                    target.addEventListener(eventName, function () {
                        clearLongPress();
                    });
                });

                target.addEventListener('click', function (event) {
                    if (longPressTriggered) {
                        event.preventDefault();
                        event.stopPropagation();
                        longPressTriggered = false;
                    }
                });
            });

            if (imageInput && imagePreviewCard && imagePreview && imagePreviewName && imagePreviewMeta) {
                imageInput.addEventListener('change', function () {
                    const file = imageInput.files && imageInput.files[0] ? imageInput.files[0] : null;

                    if (!file) {
                        return;
                    }

                    if (!file.type.startsWith('image/')) {
                        imagePreviewCard.classList.remove('is-visible');
                        imagePreviewName.textContent = 'Invalid image file';
                        imagePreviewMeta.textContent = 'Please choose a JPG, PNG, WEBP, or GIF image.';
                        return;
                    }

                    const reader = new FileReader();
                    reader.onload = function (event) {
                        imagePreview.src = typeof event.target?.result === 'string' ? event.target.result : imagePreview.src;
                        imagePreviewCard.classList.add('is-visible');
                        imagePreviewName.textContent = file.name;
                        imagePreviewMeta.textContent = Math.max(1, Math.round(file.size / 1024)) + ' KB preview ready before upload.';
                    };
                    reader.readAsDataURL(file);
                });
            }
        }());
    </script>
    <script src="language.js"></script>
</body>
</html>
