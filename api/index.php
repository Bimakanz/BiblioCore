<?php

/**
 * Vercel Serverless Entrypoint for Laravel
 */

// Pastikan direktori runtime di /tmp tersedia
$directories = [
    '/tmp/storage/framework/views',
    '/tmp/storage/framework/cache/data',
    '/tmp/storage/framework/sessions',
    '/tmp/storage/logs',
    '/tmp/bootstrap/cache',
];

foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

// Pastikan file sqlite di /tmp tersedia jika dibutuhkan
if (!file_exists('/tmp/database.sqlite')) {
    @touch('/tmp/database.sqlite');
}

// Forward ke entrypoint publik Laravel
require __DIR__ . '/../public/index.php';

