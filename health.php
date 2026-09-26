<?php

require_once __DIR__ . '/db.php';

try {
    appDb();
    header('Content-Type: text/plain; charset=utf-8');
    echo 'ok';
} catch (Throwable $error) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'unavailable';
}
