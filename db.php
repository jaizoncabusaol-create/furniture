<?php

function appCreateMessageRecord(
    string $fromName,
    string $fromEmail,
    string $recipient,
    string $productId,
    string $message,
    int $readByAdmin = 0,
    int $readByUser = 0
): array {
    return [
        'id' => uniqid('msg_', true),
        'from_name' => $fromName,
        'from_email' => $fromEmail,
        'to' => $recipient,
        'product_id' => $productId,
        'message' => $message,
        'image_path' => '',
        'created_at' => date('c'),
        'read_by_admin' => $readByAdmin,
        'read_by_user' => $readByUser,
    ];
}

function appDisplayChatMessage(string $message): string
{
    return trim((string) preg_replace('/\s*\(cust_[A-Za-z0-9_.-]+\)/', '', $message));
}

function appMessageCustomizationId(array $message): string
{
    preg_match('/\((cust_[A-Za-z0-9_.-]+)\)/', (string) ($message['message'] ?? ''), $matches);
    return (string) ($matches[1] ?? '');
}

function appArchiveDeletedRecord(array &$store, string $type, array $record): void
{
    $store['deleted_records'] = $store['deleted_records'] ?? [];
    $store['deleted_records'][] = [
        'id' => uniqid('deleted_', true),
        'type' => $type,
        'record' => $record,
        'deleted_at' => date('c'),
    ];
}

function appRestoreDeletedRecord(array &$store, string $archiveId): bool
{
    foreach (($store['deleted_records'] ?? []) as $index => $entry) {
        if (($entry['id'] ?? '') !== $archiveId) {
            continue;
        }
        $type = (string) ($entry['type'] ?? '');
        $record = $entry['record'] ?? null;
        $targetMap = ['product' => 'products', 'order' => 'orders', 'customization' => 'customization_requests'];
        if (!is_array($record)) {
            return false;
        }
        if (isset($targetMap[$type])) {
            $target = $targetMap[$type];
            $recordId = (string) ($record['id'] ?? '');
            if ($recordId === '' || count(array_filter($store[$target] ?? [], static function ($existing) use ($recordId) {
                return (string) ($existing['id'] ?? '') === $recordId;
            })) > 0) {
                return false;
            }
            array_unshift($store[$target], $record);
        } elseif ($type === 'category') {
            $name = (string) ($record['name'] ?? '');
            if ($name === '' || count(array_filter($store['settings']['categories'] ?? [], static function ($existing) use ($name) {
                return strcasecmp((string) ($existing['name'] ?? ''), $name) === 0;
            })) > 0) {
                return false;
            }
            $store['settings']['categories'][] = $record;
        } elseif ($type === 'material') {
            $name = (string) ($record['name'] ?? '');
            if ($name === '' || count(array_filter($store['settings']['materials'] ?? [], static function ($existing) use ($name) {
                return strcasecmp((string) $existing, $name) === 0;
            })) > 0) {
                return false;
            }
            $store['settings']['materials'][] = $name;
        } else {
            return false;
        }
        array_splice($store['deleted_records'], $index, 1);
        return true;
    }
    return false;
}

function appCustomizationPaymentSummary(array $request): array
{
    $total = max(0.0, (float) ($request['quotation_price'] ?? 0));
    $downPayment = min($total, max(0.0, (float) ($request['down_payment'] ?? 0)));
    $downReceived = !empty($request['payment_confirmed']) ? $downPayment : 0.0;
    $fullReceived = $downReceived > 0 && !empty($request['full_payment_confirmed']);
    $received = $fullReceived ? $total : $downReceived;
    return [
        'total' => $total,
        'down_payment' => $downPayment,
        'down_received' => $downReceived,
        'full_payment_confirmed' => $fullReceived,
        'received' => $received,
        'balance' => max(0.0, $total - $received),
        'after_down_payment' => max(0.0, $total - $downPayment),
    ];
}

function appOrderImagePath(array $order, array $products, array $deletedRecords = []): string
{
    $snapshot = trim((string) ($order['product_image'] ?? ''));
    if ($snapshot !== '' && (!str_starts_with($snapshot, 'uploads/') || is_file(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $snapshot)))) {
        return $snapshot;
    }
    $productId = (string) ($order['product_id'] ?? '');
    foreach ($products as $product) {
        if ((string) ($product['id'] ?? '') === $productId) {
            return trim((string) ($product['image'] ?? ''));
        }
    }
    foreach ($deletedRecords as $entry) {
        if (($entry['type'] ?? '') === 'product' && (string) (($entry['record'] ?? [])['id'] ?? '') === $productId) {
            return trim((string) (($entry['record'] ?? [])['image'] ?? ''));
        }
    }
    return '';
}

function appMessageReadByUser(array $message): bool
{
    return (int) ($message['read_by_user'] ?? 0) === 1;
}

function appCustomizationProgressStep(string $status, bool $paymentConfirmed = false): int
{
    return match ($status) {
        'Pending' => 0,
        'Quotation Sent' => 1,
        'Approved' => 2,
        'Down Payment Paid' => $paymentConfirmed ? 3 : 2,
        'Ongoing', 'In Production' => 4,
        'Ready' => 5,
        'Completed' => 6,
        default => -1,
    };
}

function appConfig(): array
{
    static $config = null;

    if (is_array($config)) {
        return $config;
    }

    $defaults = [
        'app_env' => 'local',
        'db_host' => '127.0.0.1',
        'db_port' => 3306,
        'db_name' => 'lumber123',
        'db_user' => 'root',
        'db_pass' => '',
        'db_charset' => 'utf8mb4',
        'auto_create_database' => true,
    ];

    $fileConfig = [];
    $configFile = __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
    if (is_file($configFile)) {
        $loaded = require $configFile;
        if (is_array($loaded)) {
            $fileConfig = $loaded;
        }
    }

    $envConfig = [
        'app_env' => getenv('APP_ENV') ?: null,
        'db_host' => getenv('DB_HOST') ?: null,
        'db_port' => getenv('DB_PORT') !== false ? (int) getenv('DB_PORT') : null,
        'db_name' => getenv('DB_NAME') ?: null,
        'db_user' => getenv('DB_USER') ?: null,
        'db_pass' => getenv('DB_PASS') !== false ? getenv('DB_PASS') : null,
        'db_charset' => getenv('DB_CHARSET') ?: null,
        'auto_create_database' => getenv('AUTO_CREATE_DATABASE') !== false
            ? filter_var(getenv('AUTO_CREATE_DATABASE'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
            : null,
    ];

    $config = array_merge($defaults, array_filter($fileConfig, function ($value) {
        return $value !== null;
    }), array_filter($envConfig, function ($value) {
        return $value !== null;
    }));

    $config['db_port'] = (int) ($config['db_port'] ?? 3306);
    $config['auto_create_database'] = !empty($config['auto_create_database']);

    return $config;
}

function appDefaultStore(): array
{
    return [
        'users' => [],
        'products' => [],
        'orders' => [],
        'carts' => [],
        'wishlists' => [],
        'customization_requests' => [],
        'deleted_records' => [],
        'messages' => [],
        'settings' => [
            'categories' => [
                ['name' => 'Bed', 'icon' => ''],
                ['name' => 'Chair', 'icon' => ''],
                ['name' => 'Cabinet', 'icon' => ''],
                ['name' => 'Sofa', 'icon' => ''],
                ['name' => 'Dining Set', 'icon' => ''],
                ['name' => 'Door', 'icon' => ''],
            ],
            'materials' => ['Wood', 'Solid Wood', 'Metal', 'Fabric', 'Glass', 'Plastic', 'Mixed'],
            'slider' => [
                'height' => '165',
                'mobile_height' => '140',
                'image_fit' => 'contain',
                'background_color' => '#f4f6fb',
                'card_color' => '#ffffff',
                'dot_color' => '#d7dce8',
                'dot_active_color' => '#12357f',
            ],
        ],
    ];
}

function appDb(): mysqli
{
    static $db = null;

    if ($db instanceof mysqli) {
        return $db;
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $config = appConfig();
    $host = (string) ($config['db_host'] ?? '127.0.0.1');
    $port = (int) ($config['db_port'] ?? 3306);
    $database = (string) ($config['db_name'] ?? 'lumber123');
    $username = (string) ($config['db_user'] ?? 'root');
    $password = (string) ($config['db_pass'] ?? '');
    $charset = (string) ($config['db_charset'] ?? 'utf8mb4');
    $autoCreateDatabase = !empty($config['auto_create_database']);

    if ($autoCreateDatabase) {
        $bootstrap = mysqli_init();
        $bootstrap->real_connect($host, $username, $password, '', $port);
        $bootstrap->set_charset($charset);
        $bootstrap->query("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $bootstrap->close();
    }

    $db = mysqli_init();
    $db->real_connect($host, $username, $password, $database, $port);
    $db->set_charset($charset);

    $schemaVersion = null;
    try {
        $result = $db->query("SELECT option_value FROM settings_options WHERE option_name = 'schema_version'");
        $schemaVersion = $result->fetch_row()[0] ?? null;
        $result->free();
    } catch (mysqli_sql_exception $error) {
        if ($error->getCode() !== 1146) {
            throw $error;
        }
    }
    if ($schemaVersion !== '2026-09-26-1') {
        appEnsureSchema($db);
        $db->query("INSERT INTO settings_options (option_name, option_value) VALUES ('schema_version', '2026-09-26-1') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)");
    }

    return $db;
}

function appEnsureSchema(mysqli $db): void
{
    $sql = [
        "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL DEFAULT '',
            role VARCHAR(30) NOT NULL DEFAULT 'user',
            phone VARCHAR(50) NOT NULL DEFAULT '',
            profile_image TEXT NOT NULL,
            address TEXT NOT NULL,
            notifications_enabled TINYINT(1) NOT NULL DEFAULT 1,
            google_auth TINYINT(1) NOT NULL DEFAULT 0,
            created_at VARCHAR(50) NOT NULL DEFAULT ''
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS products (
            id VARCHAR(80) PRIMARY KEY,
            name VARCHAR(190) NOT NULL,
            category VARCHAR(120) NOT NULL,
            material VARCHAR(120) NOT NULL,
            price DECIMAL(12,2) NOT NULL DEFAULT 0,
            stock INT NOT NULL DEFAULT 0,
            description TEXT NOT NULL,
            image TEXT NOT NULL,
            updated_at VARCHAR(50) NOT NULL DEFAULT '',
            created_at VARCHAR(50) NOT NULL DEFAULT ''
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS orders_store (
            id VARCHAR(80) PRIMARY KEY,
            customer VARCHAR(150) NOT NULL,
            customer_email VARCHAR(190) NOT NULL,
            product_id VARCHAR(80) NOT NULL,
            product_name VARCHAR(190) NOT NULL,
            product_image TEXT NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            date_label VARCHAR(50) NOT NULL DEFAULT '',
            status VARCHAR(50) NOT NULL DEFAULT 'New Order',
            tone VARCHAR(50) NOT NULL DEFAULT 'pending',
            total DECIMAL(12,2) NOT NULL DEFAULT 0,
            created_at VARCHAR(50) NOT NULL DEFAULT '',
            updated_at VARCHAR(50) NOT NULL DEFAULT ''
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS carts (
            id VARCHAR(80) PRIMARY KEY,
            customer_email VARCHAR(190) NOT NULL,
            product_id VARCHAR(80) NOT NULL,
            product_name VARCHAR(190) NOT NULL,
            price DECIMAL(12,2) NOT NULL DEFAULT 0,
            image TEXT NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            created_at VARCHAR(50) NOT NULL DEFAULT '',
            updated_at VARCHAR(50) NOT NULL DEFAULT ''
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS wishlists (
            id VARCHAR(80) PRIMARY KEY,
            customer_email VARCHAR(190) NOT NULL,
            product_id VARCHAR(80) NOT NULL,
            product_name VARCHAR(190) NOT NULL,
            price DECIMAL(12,2) NOT NULL DEFAULT 0,
            image TEXT NOT NULL,
            created_at VARCHAR(50) NOT NULL DEFAULT '',
            updated_at VARCHAR(50) NOT NULL DEFAULT ''
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS messages (
            id VARCHAR(80) PRIMARY KEY,
            from_name VARCHAR(150) NOT NULL,
            from_email VARCHAR(190) NOT NULL,
            recipient VARCHAR(50) NOT NULL DEFAULT 'admin',
            product_id VARCHAR(80) NOT NULL DEFAULT '',
            message TEXT NOT NULL,
            image_path TEXT NOT NULL,
            created_at VARCHAR(50) NOT NULL DEFAULT '',
            read_by_admin TINYINT(1) NOT NULL DEFAULT 1,
            read_by_user TINYINT(1) NOT NULL DEFAULT 1,
            deleted_by_admin TINYINT(1) NOT NULL DEFAULT 0,
            deleted_by_user TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS deleted_records (
            id VARCHAR(80) PRIMARY KEY,
            record_type VARCHAR(40) NOT NULL,
            payload LONGTEXT NOT NULL,
            deleted_at VARCHAR(50) NOT NULL DEFAULT ''
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS settings_categories (
            name VARCHAR(120) PRIMARY KEY,
            icon TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS settings_materials (
            name VARCHAR(120) PRIMARY KEY
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS settings_options (
            option_name VARCHAR(120) PRIMARY KEY,
            option_value TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS mix_match_designs (
            id VARCHAR(80) PRIMARY KEY,
            user_id INT NOT NULL DEFAULT 0,
            user_email VARCHAR(190) NOT NULL,
            design_name VARCHAR(190) NOT NULL,
            room_type VARCHAR(80) NOT NULL,
            total_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            created_at VARCHAR(50) NOT NULL DEFAULT '',
            updated_at VARCHAR(50) NOT NULL DEFAULT '',
            INDEX user_email_index (user_email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS mix_match_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            design_id VARCHAR(80) NOT NULL,
            product_id VARCHAR(80) NOT NULL,
            selected_color VARCHAR(120) NOT NULL DEFAULT '',
            selected_material VARCHAR(120) NOT NULL DEFAULT '',
            selected_size VARCHAR(120) NOT NULL DEFAULT '',
            quantity INT NOT NULL DEFAULT 1,
            price DECIMAL(12,2) NOT NULL DEFAULT 0,
            position_x DECIMAL(6,2) NOT NULL DEFAULT 12,
            position_y DECIMAL(6,2) NOT NULL DEFAULT 12,
            rotation DECIMAL(6,2) NOT NULL DEFAULT 0,
            scale_value DECIMAL(6,2) NOT NULL DEFAULT 1,
            layer_order INT NOT NULL DEFAULT 1,
            INDEX design_id_index (design_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS customization_requests (
            id VARCHAR(80) PRIMARY KEY,
            customer VARCHAR(150) NOT NULL,
            customer_email VARCHAR(190) NOT NULL,
            customer_phone VARCHAR(50) NOT NULL DEFAULT '',
            delivery_address TEXT NOT NULL,
            product_id VARCHAR(80) NOT NULL,
            product_name VARCHAR(190) NOT NULL,
            product_image TEXT NOT NULL,
            preferred_size VARCHAR(190) NOT NULL,
            color VARCHAR(120) NOT NULL,
            material VARCHAR(120) NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            design_instructions TEXT NOT NULL,
            reference_image TEXT NOT NULL,
            quotation_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            down_payment DECIMAL(12,2) NOT NULL DEFAULT 0,
            estimated_completion_date VARCHAR(50) NOT NULL DEFAULT '',
            admin_notes TEXT NOT NULL,
            payment_proof TEXT NOT NULL,
            payment_confirmed TINYINT(1) NOT NULL DEFAULT 0,
            full_payment_confirmed TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(50) NOT NULL DEFAULT 'Pending',
            tone VARCHAR(50) NOT NULL DEFAULT 'pending',
            created_at VARCHAR(50) NOT NULL DEFAULT '',
            updated_at VARCHAR(50) NOT NULL DEFAULT '',
            INDEX customer_email_index (customer_email),
            INDEX status_index (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($sql as $statement) {
        $db->query($statement);
    }

    appEnsureColumn(
        $db,
        'users',
        'phone',
        "ALTER TABLE users ADD COLUMN phone VARCHAR(50) NOT NULL DEFAULT '' AFTER role"
    );
    appEnsureColumn(
        $db,
        'users',
        'profile_image',
        "ALTER TABLE users ADD COLUMN profile_image TEXT NOT NULL AFTER phone"
    );
    appEnsureColumn(
        $db,
        'users',
        'address',
        "ALTER TABLE users ADD COLUMN address TEXT NOT NULL AFTER profile_image"
    );
    appEnsureColumn(
        $db,
        'users',
        'notifications_enabled',
        "ALTER TABLE users ADD COLUMN notifications_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER address"
    );
    appEnsureColumn(
        $db,
        'messages',
        'read_by_admin',
        'ALTER TABLE messages ADD COLUMN read_by_admin TINYINT(1) NOT NULL DEFAULT 1'
    );
    appEnsureColumn(
        $db,
        'messages',
        'read_by_user',
        'ALTER TABLE messages ADD COLUMN read_by_user TINYINT(1) NOT NULL DEFAULT 1'
    );
    appEnsureColumn($db, 'messages', 'image_path', 'ALTER TABLE messages ADD COLUMN image_path TEXT NOT NULL');
    appEnsureColumn($db, 'messages', 'deleted_by_admin', 'ALTER TABLE messages ADD COLUMN deleted_by_admin TINYINT(1) NOT NULL DEFAULT 0');
    appEnsureColumn($db, 'messages', 'deleted_by_user', 'ALTER TABLE messages ADD COLUMN deleted_by_user TINYINT(1) NOT NULL DEFAULT 0');
    appEnsureColumn($db, 'orders_store', 'product_image', 'ALTER TABLE orders_store ADD COLUMN product_image TEXT NOT NULL AFTER product_name');
    $db->query("UPDATE orders_store AS o JOIN products AS p ON p.id = o.product_id SET o.product_image = p.image WHERE o.product_image = '' AND p.image <> ''");
    appEnsureColumn($db, 'customization_requests', 'payment_confirmed', 'ALTER TABLE customization_requests ADD COLUMN payment_confirmed TINYINT(1) NOT NULL DEFAULT 0');
    appEnsureColumn($db, 'customization_requests', 'full_payment_confirmed', 'ALTER TABLE customization_requests ADD COLUMN full_payment_confirmed TINYINT(1) NOT NULL DEFAULT 0');
    appEnsureColumn(
        $db,
        'mix_match_items',
        'rotation',
        "ALTER TABLE mix_match_items ADD COLUMN rotation DECIMAL(6,2) NOT NULL DEFAULT 0 AFTER position_y"
    );
    appEnsureColumn(
        $db,
        'mix_match_items',
        'scale_value',
        "ALTER TABLE mix_match_items ADD COLUMN scale_value DECIMAL(6,2) NOT NULL DEFAULT 1 AFTER rotation"
    );
    appEnsureColumn(
        $db,
        'mix_match_items',
        'layer_order',
        "ALTER TABLE mix_match_items ADD COLUMN layer_order INT NOT NULL DEFAULT 1 AFTER scale_value"
    );

    $count = (int) appDbValue($db, 'SELECT COUNT(*) FROM users');
    if ($count === 0) {
        appMigrateJsonToDatabase($db, __DIR__ . DIRECTORY_SEPARATOR . 'users.json');
    }

    appEnsureBaseAccounts($db);
    appEnsureDefaultSettings($db);
    appEnsureCatalogProducts($db);
}

function appMigrateJsonToDatabase(mysqli $db, string $storageFile): void
{
    $store = appDefaultStore();

    if (is_file($storageFile)) {
        $raw = file_get_contents($storageFile);
        $decoded = json_decode($raw ?: '[]', true);
        if (is_array($decoded)) {
            if (array_keys($decoded) === range(0, count($decoded) - 1)) {
                $store['users'] = $decoded;
            } else {
                $store['users'] = isset($decoded['users']) && is_array($decoded['users']) ? $decoded['users'] : [];
                $store['products'] = isset($decoded['products']) && is_array($decoded['products']) ? $decoded['products'] : [];
                $store['orders'] = isset($decoded['orders']) && is_array($decoded['orders']) ? $decoded['orders'] : [];
                $store['carts'] = isset($decoded['carts']) && is_array($decoded['carts']) ? $decoded['carts'] : [];
                $store['wishlists'] = isset($decoded['wishlists']) && is_array($decoded['wishlists']) ? $decoded['wishlists'] : [];
                $store['messages'] = isset($decoded['messages']) && is_array($decoded['messages']) ? $decoded['messages'] : [];
                if (isset($decoded['settings']) && is_array($decoded['settings'])) {
                    $store['settings']['categories'] = isset($decoded['settings']['categories']) && is_array($decoded['settings']['categories']) ? $decoded['settings']['categories'] : $store['settings']['categories'];
                    $store['settings']['materials'] = isset($decoded['settings']['materials']) && is_array($decoded['settings']['materials']) ? $decoded['settings']['materials'] : $store['settings']['materials'];
                    $store['settings']['slider'] = isset($decoded['settings']['slider']) && is_array($decoded['settings']['slider']) ? array_merge($store['settings']['slider'], $decoded['settings']['slider']) : $store['settings']['slider'];
                }
            }
        }
    }

    appWriteStoreToDatabase($db, $store);
}

function appEnsureBaseAccounts(mysqli $db): void
{
    if ((string) (appConfig()['app_env'] ?? '') === 'production') {
        if ((int) appDbValue($db, "SELECT COUNT(*) FROM users WHERE role = 'admin'") === 0) {
            $email = trim((string) getenv('ADMIN_EMAIL'));
            $password = (string) getenv('ADMIN_PASSWORD');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
                throw new RuntimeException('Set ADMIN_EMAIL and an ADMIN_PASSWORD of at least 12 characters in production.');
            }

            appDbExecute(
                $db,
                'INSERT INTO users (name, email, password, role, phone, profile_image, address, notifications_enabled, google_auth, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                ['admin', $email, password_hash($password, PASSWORD_DEFAULT), 'admin', '', '', '', 1, 0, date('c')]
            );
        }

        $defaultUserExists = (int) appDbValue(
            $db,
            'SELECT COUNT(*) FROM users WHERE LOWER(name) = ? OR LOWER(email) = ?',
            ['user', 'user@demo.local']
        ) > 0;
        if (!$defaultUserExists) {
            appDbExecute(
                $db,
                'INSERT INTO users (name, email, password, role, phone, profile_image, address, notifications_enabled, google_auth, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                ['user', 'user@demo.local', password_hash('123', PASSWORD_DEFAULT), 'user', '', '', '', 1, 0, date('c')]
            );
        }

        return;
    }

    $accounts = [
        ['name' => 'admin', 'email' => 'admin@demo.local', 'password' => password_hash('123', PASSWORD_DEFAULT), 'role' => 'admin', 'google_auth' => 0],
        ['name' => 'user', 'email' => 'user@demo.local', 'password' => password_hash('123', PASSWORD_DEFAULT), 'role' => 'user', 'google_auth' => 0],
    ];

    foreach ($accounts as $account) {
        $count = (int) appDbValue($db, 'SELECT COUNT(*) FROM users WHERE email = ?', [$account['email']]);
        if ($count === 0) {
            appDbExecute(
                $db,
                'INSERT INTO users (name, email, password, role, phone, profile_image, address, notifications_enabled, google_auth, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $account['name'],
                    $account['email'],
                    $account['password'],
                    $account['role'],
                    '', // phone
                    '', // profile_image
                    '', // address
                    1,  // notifications_enabled
                    $account['google_auth'],
                    date('c'),
                ]
            );
        }
    }
}

function appEnsureDefaultSettings(mysqli $db): void
{
    if ((string) appDbValue($db, "SELECT option_value FROM settings_options WHERE option_name = 'defaults_seeded'") === '1') {
        return;
    }

    if ((int) appDbValue($db, 'SELECT COUNT(*) FROM settings_categories') === 0) {
        foreach (appDefaultStore()['settings']['categories'] as $category) {
            appDbExecute($db, 'INSERT INTO settings_categories (name, icon) VALUES (?, ?)', [
                (string) ($category['name'] ?? ''),
                (string) ($category['icon'] ?? ''),
            ]);
        }
    }

    if ((int) appDbValue($db, 'SELECT COUNT(*) FROM settings_materials') === 0) {
        foreach (appDefaultStore()['settings']['materials'] as $material) {
            appDbExecute($db, 'INSERT INTO settings_materials (name) VALUES (?)', [(string) $material]);
        }
    }

    appDbExecute($db, "INSERT INTO settings_options (option_name, option_value) VALUES ('defaults_seeded', '1') ON DUPLICATE KEY UPDATE option_value = '1'");
}

function appEnsureCatalogProducts(mysqli $db): void
{
    $catalogSeeded = (string) appDbValue($db, "SELECT option_value FROM settings_options WHERE option_name = 'catalog_seeded'") === '1';
    $catalogSeedVersion = (int) appDbValue($db, "SELECT option_value FROM settings_options WHERE option_name = 'catalog_seed_version'");
    if ($catalogSeeded && $catalogSeedVersion >= 2) {
        return;
    }

    appDbExecute($db, "DELETE FROM products WHERE id LIKE 'prd_catalog_%' OR image LIKE 'uploads/catalog_%'");
    appDbExecute($db, "UPDATE products SET category = 'Bed', material = 'Solid Wood' WHERE id = 'prd_6a37be6c657f09.69652152'");

    $imageFiles = [];
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $extension) {
        $imageFiles = array_merge(
            $imageFiles,
            glob(__DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'fur_clean_*.' . $extension) ?: []
        );
    }
    sort($imageFiles, SORT_NATURAL | SORT_FLAG_CASE);

    foreach ($imageFiles as $imageFile) {
        $filename = basename($imageFile);
        $slug = preg_replace('/^fur_clean_/', '', pathinfo($filename, PATHINFO_FILENAME));
        $product = appCleanImageProduct((string) $slug);

        appDbExecute(
            $db,
            'INSERT INTO products (id, name, category, material, price, stock, description, image, updated_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), category = VALUES(category), material = VALUES(material), price = VALUES(price), stock = VALUES(stock), description = VALUES(description), image = VALUES(image), updated_at = VALUES(updated_at)',
            [
                'prd_clean_' . $slug,
                $product['name'],
                $product['category'],
                $product['material'],
                $product['price'],
                $product['stock'],
                $product['description'],
                'uploads/' . $filename,
                date('c'),
                date('c'),
            ]
        );
    }

    appDbExecute(
        $db,
        "DELETE p1 FROM products p1
         INNER JOIN products p2
            ON p1.image <> ''
           AND p1.image = p2.image
           AND p1.id > p2.id"
    );
    appDbExecute($db, "INSERT INTO settings_options (option_name, option_value) VALUES ('catalog_seeded', '1') ON DUPLICATE KEY UPDATE option_value = '1'");
    appDbExecute($db, "INSERT INTO settings_options (option_name, option_value) VALUES ('catalog_seed_version', '2') ON DUPLICATE KEY UPDATE option_value = '2'");
}

function appCatalogProductIsVisible(array $product): bool
{
    return trim((string) ($product['image'] ?? '')) !== '' || trim((string) ($product['name'] ?? '')) !== '';
}

function appCleanImageProduct(string $slug): array
{
    $category = 'Furniture';
    $type = 'Wooden Furniture';
    $price = 6500.00;
    $stock = 5;

    if (preg_match('/(^|-)door($|-)/', $slug)) {
        $category = 'Door';
        $type = str_contains($slug, 'carved') || str_contains($slug, 'floral') || str_contains($slug, 'ornate')
            ? 'Carved Wooden Door'
            : 'Wooden Door';
        $price = str_contains($slug, 'carved') || str_contains($slug, 'floral') || str_contains($slug, 'ornate') ? 8500.00 : 4500.00;
        $stock = 8;
    } elseif (str_contains($slug, 'daybed')) {
        $category = 'Sofa';
        $type = 'Carved Wooden Daybed';
        $price = 25000.00;
        $stock = 3;
    } elseif (str_contains($slug, 'bed') || str_contains($slug, 'headboard')) {
        $category = 'Bed';
        $type = str_contains($slug, 'carved') || str_contains($slug, 'heart') || str_contains($slug, 'wave')
            ? 'Carved Wooden Bed'
            : 'Wooden Bed';
        $price = str_contains($slug, 'carved') || str_contains($slug, 'heart') || str_contains($slug, 'wave') ? 17000.00 : 12000.00;
        $stock = 5;
    } elseif (str_contains($slug, 'chair') || str_contains($slug, 'bench')) {
        $category = 'Chair';
        $type = str_contains($slug, 'bench') ? 'Wooden Bench Set' : (str_contains($slug, 'chairs') ? 'Wooden Chair Set' : 'Wooden Chair');
        $price = str_contains($slug, 'bench') || str_contains($slug, 'chairs') ? 6500.00 : 3500.00;
        $stock = 6;
    } elseif (str_contains($slug, 'dining') || str_contains($slug, 'table')) {
        $category = 'Dining Set';
        $type = 'Dining Table Set';
        $price = 18000.00;
        $stock = 4;
    } elseif (str_contains($slug, 'sala') || str_contains($slug, 'sofa')) {
        $category = 'Sofa';
        $type = 'Wooden Sala Set';
        $price = 25000.00;
        $stock = 3;
    } elseif (str_contains($slug, 'cabinet') || str_contains($slug, 'wardrobe') || str_contains($slug, 'shelf') || str_contains($slug, 'bookcase')) {
        $category = 'Cabinet';
        $type = str_contains($slug, 'wardrobe') ? 'Open Wardrobe' : (str_contains($slug, 'shelf') || str_contains($slug, 'bookcase') ? 'Wooden Shelf Cabinet' : 'Wooden Cabinet');
        $price = str_contains($slug, 'wardrobe') ? 8000.00 : 6500.00;
        $stock = 6;
    }

    $finish = str_contains($slug, 'raw') || str_contains($slug, 'light') || str_contains($slug, 'cream')
        ? 'Natural raw/light wood finish, ready for varnish or paint'
        : 'Glossy natural brown varnish finish';
    $size = appCleanImageProductSize($slug, $category);

    return [
        'name' => appDisplayNameFromSlug($slug, $type),
        'category' => $category,
        'material' => 'Solid Wood',
        'price' => $price,
        'stock' => $stock,
        'description' => "Product Details:\nType: {$type}\nSize: {$size}\nMaterial: Solid wood / hardwood style\nFinish/Color: {$finish}\nCustomization: Exact size, design, carving, color/varnish, and hardware can be customized by request\nIncluded: Furniture item shown in the photo only\nBest For: Home, rental unit, bedroom, dining area, living room, office, or renovation use\nCondition: Brand new / made-to-order or ready item depending on stock\nAvailability: Ready to reserve while stocks last\n\nImportant Notes:\nActual size, wood grain, shade, and carving details may vary per item.\nAccessories, mattress, lockset, handles, hinges, delivery, assembly, and installation are not included unless confirmed by seller.\nPlease message seller before checkout to confirm exact size, final stock, delivery fee, custom design, color, and installation request.",
    ];
}

function appCleanImageProductSize(string $slug, string $category): string
{
    if ($category === 'Door') {
        return str_contains($slug, 'sample') ? 'Per panel approx. 32in W x 80in H' : '36in W x 84in H x 1.5in T';
    }

    if ($category === 'Bed') {
        if (str_contains($slug, 'single') || str_contains($slug, 'simple')) {
            return '48in W x 75in L';
        }
        if (str_contains($slug, 'headboard')) {
            return '60in W x 48in H headboard';
        }
        return '60in W x 75in L';
    }

    if ($category === 'Dining Set') {
        if (str_contains($slug, 'six')) {
            return 'Table approx. 72in L x 36in W x 30in H, 6 chairs';
        }
        return 'Table approx. 60in L x 36in W x 30in H';
    }

    if ($category === 'Chair') {
        if (str_contains($slug, 'bench')) {
            return '60in W x 24in D x 34in H';
        }
        if (str_contains($slug, 'chairs')) {
            return 'Each chair approx. 22in W x 24in D x 38in H';
        }
        return '22in W x 24in D x 38in H';
    }

    if ($category === 'Sofa') {
        if (str_contains($slug, 'daybed')) {
            return '75in L x 36in D x 34in H';
        }
        return 'Main sofa approx. 84in W x 32in D x 36in H';
    }

    if ($category === 'Cabinet') {
        if (str_contains($slug, 'wardrobe')) {
            return '36in W x 22in D x 72in H';
        }
        if (str_contains($slug, 'shelf') || str_contains($slug, 'bookcase')) {
            return '32in W x 14in D x 60in H';
        }
        if (str_contains($slug, 'hutch') || str_contains($slug, 'display')) {
            return '48in W x 18in D x 72in H';
        }
        return '36in W x 18in D x 40in H';
    }

    return 'Approx. 36in W x 18in D x 36in H';
}

function appDisplayNameFromSlug(string $slug, string $fallback): string
{
    $words = array_values(array_filter(explode('-', $slug), function ($word) {
        return !in_array($word, ['a', 'b', 'clean', 'room', 'workshop', 'outdoor', 'indoor', 'photo', 'installed', 'loading', 'closeup', 'truck', 'top', 'side'], true);
    }));

    if ($words === []) {
        return $fallback;
    }

    $name = ucwords(implode(' ', $words));

    return trim($name) !== '' ? trim($name) : $fallback;
}

function appLoadStore(): array
{
    return appReadStoreFromDatabase(appDb());
}

function appSaveStore(array $data): void
{
    appWriteStoreToDatabase(appDb(), $data);
}

function appReadStoreFromDatabase(mysqli $db): array
{
    $store = appDefaultStore();

    $store['users'] = appDbFetchAll($db, 'SELECT name, email, password, role, phone, profile_image, address, notifications_enabled, google_auth, created_at FROM users ORDER BY id DESC');

    $products = appDbFetchAll($db, 'SELECT id, name, category, material, price, stock, description, image, updated_at, created_at FROM products ORDER BY created_at DESC');
    $store['products'] = array_map(function ($product) {
        $product['price'] = number_format((float) ($product['price'] ?? 0), 2, '.', '');
        $product['stock'] = (int) ($product['stock'] ?? 0);
        return $product;
    }, $products);

    $orders = appDbFetchAll($db, 'SELECT id, customer, customer_email, product_id, product_name, product_image, quantity, date_label, status, tone, total, created_at, updated_at FROM orders_store ORDER BY created_at DESC');
    $store['orders'] = array_map(function ($order) {
        return [
            'id' => $order['id'] ?? '',
            'customer' => $order['customer'] ?? '',
            'customer_email' => $order['customer_email'] ?? '',
            'product_id' => $order['product_id'] ?? '',
            'product_name' => $order['product_name'] ?? '',
            'product_image' => $order['product_image'] ?? '',
            'quantity' => (int) ($order['quantity'] ?? 1),
            'date' => $order['date_label'] ?? '',
            'status' => $order['status'] ?? 'New Order',
            'tone' => $order['tone'] ?? 'pending',
            'total' => number_format((float) ($order['total'] ?? 0), 2, '.', ''),
            'created_at' => $order['created_at'] ?? '',
            'updated_at' => $order['updated_at'] ?? '',
        ];
    }, $orders);

    $carts = appDbFetchAll($db, 'SELECT id, customer_email, product_id, product_name, price, image, quantity, created_at, updated_at FROM carts ORDER BY created_at DESC');
    $store['carts'] = array_map(function ($item) {
        $item['price'] = number_format((float) ($item['price'] ?? 0), 2, '.', '');
        $item['quantity'] = (int) ($item['quantity'] ?? 1);
        return $item;
    }, $carts);

    $wishlists = appDbFetchAll($db, 'SELECT id, customer_email, product_id, product_name, price, image, created_at, updated_at FROM wishlists ORDER BY created_at DESC');
    $store['wishlists'] = array_map(function ($item) {
        $item['price'] = number_format((float) ($item['price'] ?? 0), 2, '.', '');
        return $item;
    }, $wishlists);

    $store['messages'] = array_map(function ($message) {
        return [
            'id' => $message['id'] ?? '',
            'from_name' => $message['from_name'] ?? '',
            'from_email' => $message['from_email'] ?? '',
            'to' => $message['recipient'] ?? 'admin',
            'product_id' => $message['product_id'] ?? '',
            'message' => $message['message'] ?? '',
            'image_path' => $message['image_path'] ?? '',
            'created_at' => $message['created_at'] ?? '',
            'read_by_admin' => (int) ($message['read_by_admin'] ?? 1),
            'read_by_user' => (int) ($message['read_by_user'] ?? 1),
            'deleted_by_admin' => (int) ($message['deleted_by_admin'] ?? 0),
            'deleted_by_user' => (int) ($message['deleted_by_user'] ?? 0),
        ];
    }, appDbFetchAll($db, 'SELECT id, from_name, from_email, recipient, product_id, message, image_path, created_at, read_by_admin, read_by_user, deleted_by_admin, deleted_by_user FROM messages ORDER BY created_at DESC'));

    $store['deleted_records'] = array_map(static function ($entry) {
        $record = json_decode((string) ($entry['payload'] ?? ''), true);
        return [
            'id' => (string) ($entry['id'] ?? ''),
            'type' => (string) ($entry['record_type'] ?? ''),
            'record' => is_array($record) ? $record : [],
            'deleted_at' => (string) ($entry['deleted_at'] ?? ''),
        ];
    }, appDbFetchAll($db, 'SELECT id, record_type, payload, deleted_at FROM deleted_records ORDER BY deleted_at DESC'));

    $customizationRequests = appDbFetchAll($db, 'SELECT id, customer, customer_email, customer_phone, delivery_address, product_id, product_name, product_image, preferred_size, color, material, quantity, design_instructions, reference_image, quotation_price, down_payment, estimated_completion_date, admin_notes, payment_proof, payment_confirmed, full_payment_confirmed, status, tone, created_at, updated_at FROM customization_requests ORDER BY created_at DESC');
    $store['customization_requests'] = array_map(function ($request) {
        $request['quantity'] = (int) ($request['quantity'] ?? 1);
        $request['quotation_price'] = number_format((float) ($request['quotation_price'] ?? 0), 2, '.', '');
        $request['down_payment'] = number_format((float) ($request['down_payment'] ?? 0), 2, '.', '');
        return $request;
    }, $customizationRequests);

    $store['settings']['categories'] = appDbFetchAll($db, 'SELECT name, icon FROM settings_categories ORDER BY name ASC');
    $store['settings']['materials'] = array_map(function ($row) {
        return $row['name'] ?? '';
    }, appDbFetchAll($db, 'SELECT name FROM settings_materials ORDER BY name ASC'));
    foreach (appDbFetchAll($db, 'SELECT option_name, option_value FROM settings_options') as $option) {
        $name = (string) ($option['option_name'] ?? '');
        if ($name === 'slider_images') {
            $images = json_decode((string) ($option['option_value'] ?? ''), true);
            $store['settings']['slider']['images'] = is_array($images) ? array_values(array_filter($images, 'is_string')) : [];
        } elseif (str_starts_with($name, 'slider_')) {
            $store['settings']['slider'][substr($name, 7)] = (string) ($option['option_value'] ?? '');
        }
    }

    return $store;
}

function appWriteStoreToDatabase(mysqli $db, array $data): void
{
    $catalogSeeded = (string) appDbValue($db, "SELECT option_value FROM settings_options WHERE option_name = 'catalog_seeded'") === '1';
    $schemaVersion = (string) appDbValue($db, "SELECT option_value FROM settings_options WHERE option_name = 'schema_version'");
    $db->begin_transaction();

    try {
        foreach (['users', 'products', 'orders_store', 'carts', 'wishlists', 'messages', 'customization_requests', 'deleted_records', 'settings_categories', 'settings_materials', 'settings_options'] as $table) {
            $db->query("DELETE FROM {$table}");
        }

        foreach (($data['users'] ?? []) as $user) {
            appDbExecute(
                $db,
                'INSERT INTO users (name, email, password, role, phone, profile_image, address, notifications_enabled, google_auth, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    (string) ($user['name'] ?? ''),
                    (string) ($user['email'] ?? ''),
                    (string) ($user['password'] ?? ''),
                    (string) ($user['role'] ?? 'user'),
                    (string) ($user['phone'] ?? ''),
                    (string) ($user['profile_image'] ?? ''),
                    (string) ($user['address'] ?? ''),
                    !array_key_exists('notifications_enabled', $user) || !empty($user['notifications_enabled']) ? 1 : 0,
                    !empty($user['google_auth']) ? 1 : 0,
                    (string) ($user['created_at'] ?? ''),
                ]
            );
        }

        foreach (($data['products'] ?? []) as $product) {
            appDbExecute(
                $db,
                'INSERT INTO products (id, name, category, material, price, stock, description, image, updated_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    (string) ($product['id'] ?? ''),
                    (string) ($product['name'] ?? ''),
                    (string) ($product['category'] ?? ''),
                    (string) ($product['material'] ?? ''),
                    (float) ($product['price'] ?? 0),
                    (int) ($product['stock'] ?? 0),
                    (string) ($product['description'] ?? ''),
                    (string) ($product['image'] ?? ''),
                    (string) ($product['updated_at'] ?? ''),
                    (string) ($product['created_at'] ?? ''),
                ]
            );
        }

        foreach (($data['orders'] ?? []) as $order) {
            appDbExecute(
                $db,
                'INSERT INTO orders_store (id, customer, customer_email, product_id, product_name, product_image, quantity, date_label, status, tone, total, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    (string) ($order['id'] ?? ''),
                    (string) ($order['customer'] ?? ''),
                    (string) ($order['customer_email'] ?? ''),
                    (string) ($order['product_id'] ?? ''),
                    (string) ($order['product_name'] ?? ''),
                    (string) ($order['product_image'] ?? ''),
                    (int) ($order['quantity'] ?? 1),
                    (string) ($order['date'] ?? ''),
                    (string) ($order['status'] ?? 'New Order'),
                    (string) ($order['tone'] ?? 'pending'),
                    (float) ($order['total'] ?? 0),
                    (string) ($order['created_at'] ?? ''),
                    (string) ($order['updated_at'] ?? ''),
                ]
            );
        }

        foreach (($data['carts'] ?? []) as $item) {
            appDbExecute(
                $db,
                'INSERT INTO carts (id, customer_email, product_id, product_name, price, image, quantity, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    (string) ($item['id'] ?? ''),
                    (string) ($item['customer_email'] ?? ''),
                    (string) ($item['product_id'] ?? ''),
                    (string) ($item['product_name'] ?? ''),
                    (float) ($item['price'] ?? 0),
                    (string) ($item['image'] ?? ''),
                    (int) ($item['quantity'] ?? 1),
                    (string) ($item['created_at'] ?? ''),
                    (string) ($item['updated_at'] ?? ''),
                ]
            );
        }

        foreach (($data['wishlists'] ?? []) as $item) {
            appDbExecute(
                $db,
                'INSERT INTO wishlists (id, customer_email, product_id, product_name, price, image, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    (string) ($item['id'] ?? ''),
                    (string) ($item['customer_email'] ?? ''),
                    (string) ($item['product_id'] ?? ''),
                    (string) ($item['product_name'] ?? ''),
                    (float) ($item['price'] ?? 0),
                    (string) ($item['image'] ?? ''),
                    (string) ($item['created_at'] ?? ''),
                    (string) ($item['updated_at'] ?? ''),
                ]
            );
        }

        foreach (($data['messages'] ?? []) as $message) {
            appDbExecute(
                $db,
                'INSERT INTO messages (id, from_name, from_email, recipient, product_id, message, image_path, created_at, read_by_admin, read_by_user, deleted_by_admin, deleted_by_user) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    (string) ($message['id'] ?? ''),
                    (string) ($message['from_name'] ?? ''),
                    (string) ($message['from_email'] ?? ''),
                    (string) ($message['to'] ?? 'admin'),
                    (string) ($message['product_id'] ?? ''),
                    (string) ($message['message'] ?? ''),
                    (string) ($message['image_path'] ?? ''),
                    (string) ($message['created_at'] ?? ''),
                    (int) ($message['read_by_admin'] ?? 1),
                    (int) ($message['read_by_user'] ?? 1),
                    (int) ($message['deleted_by_admin'] ?? 0),
                    (int) ($message['deleted_by_user'] ?? 0),
                ]
            );
        }

        foreach (($data['deleted_records'] ?? []) as $entry) {
            appDbExecute(
                $db,
                'INSERT INTO deleted_records (id, record_type, payload, deleted_at) VALUES (?, ?, ?, ?)',
                [
                    (string) ($entry['id'] ?? ''),
                    (string) ($entry['type'] ?? ''),
                    json_encode($entry['record'] ?? [], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES),
                    (string) ($entry['deleted_at'] ?? ''),
                ]
            );
        }

        foreach (($data['customization_requests'] ?? []) as $request) {
            appDbExecute(
                $db,
                'INSERT INTO customization_requests (id, customer, customer_email, customer_phone, delivery_address, product_id, product_name, product_image, preferred_size, color, material, quantity, design_instructions, reference_image, quotation_price, down_payment, estimated_completion_date, admin_notes, payment_proof, payment_confirmed, full_payment_confirmed, status, tone, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    (string) ($request['id'] ?? ''),
                    (string) ($request['customer'] ?? ''),
                    (string) ($request['customer_email'] ?? ''),
                    (string) ($request['customer_phone'] ?? ''),
                    (string) ($request['delivery_address'] ?? ''),
                    (string) ($request['product_id'] ?? ''),
                    (string) ($request['product_name'] ?? ''),
                    (string) ($request['product_image'] ?? ''),
                    (string) ($request['preferred_size'] ?? ''),
                    (string) ($request['color'] ?? ''),
                    (string) ($request['material'] ?? ''),
                    (int) ($request['quantity'] ?? 1),
                    (string) ($request['design_instructions'] ?? ''),
                    (string) ($request['reference_image'] ?? ''),
                    (float) ($request['quotation_price'] ?? 0),
                    (float) ($request['down_payment'] ?? 0),
                    (string) ($request['estimated_completion_date'] ?? ''),
                    (string) ($request['admin_notes'] ?? ''),
                    (string) ($request['payment_proof'] ?? ''),
                    (int) ($request['payment_confirmed'] ?? 0),
                    (int) ($request['full_payment_confirmed'] ?? 0),
                    (string) ($request['status'] ?? 'Pending'),
                    (string) ($request['tone'] ?? 'pending'),
                    (string) ($request['created_at'] ?? ''),
                    (string) ($request['updated_at'] ?? ''),
                ]
            );
        }

        foreach ((($data['settings'] ?? [])['categories'] ?? []) as $category) {
            appDbExecute(
                $db,
                'INSERT INTO settings_categories (name, icon) VALUES (?, ?)',
                [
                    (string) ($category['name'] ?? ''),
                    (string) ($category['icon'] ?? ''),
                ]
            );
        }

        foreach ((($data['settings'] ?? [])['materials'] ?? []) as $material) {
            appDbExecute(
                $db,
                'INSERT INTO settings_materials (name) VALUES (?)',
                [(string) $material]
            );
        }

        foreach ((($data['settings'] ?? [])['slider'] ?? []) as $name => $value) {
            appDbExecute(
                $db,
                'INSERT INTO settings_options (option_name, option_value) VALUES (?, ?)',
                ['slider_' . (string) $name, $name === 'images' ? json_encode(array_values((array) $value)) : (string) $value]
            );
        }

        appDbExecute($db, "INSERT INTO settings_options (option_name, option_value) VALUES ('defaults_seeded', '1')");
        if ($catalogSeeded || ($data['products'] ?? []) !== []) {
            appDbExecute($db, "INSERT INTO settings_options (option_name, option_value) VALUES ('catalog_seeded', '1')");
        }
        if ($schemaVersion !== '') {
            appDbExecute($db, 'INSERT INTO settings_options (option_name, option_value) VALUES (?, ?)', ['schema_version', $schemaVersion]);
        }

        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function appDbExecute(mysqli $db, string $sql, array $params = []): void
{
    if ($params === []) {
        $db->query($sql);
        return;
    }

    $statement = $db->prepare($sql);
    $statement->bind_param(appDbParamTypes($params), ...appDbParamValues($params));
    $statement->execute();
    $statement->close();
}

function appDbFetchAll(mysqli $db, string $sql, array $params = []): array
{
    if ($params === []) {
        $result = $db->query($sql);
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    $statement = $db->prepare($sql);
    $statement->bind_param(appDbParamTypes($params), ...appDbParamValues($params));
    $statement->execute();
    $result = $statement->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $statement->close();

    return $rows;
}

function appDbValue(mysqli $db, string $sql, array $params = [])
{
    $rows = appDbFetchAll($db, $sql, $params);
    if ($rows === []) {
        return null;
    }

    return array_shift($rows[0]);
}

function appEnsureColumn(mysqli $db, string $table, string $column, string $alterSql): void
{
    $exists = (int) appDbValue(
        $db,
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$table, $column]
    );

    if ($exists === 0) {
        $db->query($alterSql);
    }
}

function appDbParamTypes(array $params): string
{
    $types = '';

    foreach ($params as $param) {
        if (is_int($param)) {
            $types .= 'i';
            continue;
        }

        if (is_float($param)) {
            $types .= 'd';
            continue;
        }

        $types .= 's';
    }

    return $types;
}

function appDbParamValues(array $params): array
{
    return array_map(function ($param) {
        if (is_bool($param)) {
            return $param ? 1 : 0;
        }

        if ($param === null) {
            return '';
        }

        return $param;
    }, $params);
}
