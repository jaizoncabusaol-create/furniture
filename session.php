<?php

$configuredSessionDirectory = trim((string) getenv('APP_SESSION_DIR'));
$sessionDirectory = $configuredSessionDirectory !== ''
    ? $configuredSessionDirectory
    : __DIR__ . DIRECTORY_SEPARATOR . '.sessions';

if (!is_dir($sessionDirectory)) {
    @mkdir($sessionDirectory, 0775, true);
}

if (is_dir($sessionDirectory) && is_writable($sessionDirectory)) {
    session_save_path($sessionDirectory);
}

$forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
    || in_array('https', array_map('trim', explode(',', $forwardedProto)), true);

ini_set('session.cookie_secure', $isHttps ? '1' : '0');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', '1');

session_start();

