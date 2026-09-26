<?php

return [
    // Use 'production' on hosting.
    'app_env' => 'production',

    // Hosting database credentials.
    'db_host' => 'localhost',
    'db_port' => 3306,
    'db_name' => 'your_cpanel_db_name',
    'db_user' => 'your_cpanel_db_user',
    'db_pass' => 'your_cpanel_db_password',
    'db_charset' => 'utf8mb4',

    // Keep this false on hosting when you already imported lumber123.sql.
    'auto_create_database' => false,
];
