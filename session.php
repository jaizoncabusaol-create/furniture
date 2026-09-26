<?php

$sessionDirectory = __DIR__ . DIRECTORY_SEPARATOR . '.sessions';

if (!is_dir($sessionDirectory)) {
    @mkdir($sessionDirectory, 0775, true);
}

if (is_dir($sessionDirectory) && is_writable($sessionDirectory)) {
    session_save_path($sessionDirectory);
}

session_start();

