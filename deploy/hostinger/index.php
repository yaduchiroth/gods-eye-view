<?php
declare(strict_types=1);

$appDir = __DIR__ . '/app';
$requestPath = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
$relativePath = ltrim($requestPath, '/');
$relativePath = preg_replace('#/+#', '/', $relativePath);

if ($relativePath === '') {
    $relativePath = 'index.html';
}

$sanitizedPath = str_replace(['../', '..\\'], '', $relativePath);
$target = realpath($appDir . '/' . $sanitizedPath);
$appReal = realpath($appDir);

if ($target !== false && $appReal !== false && str_starts_with($target, $appReal) && is_file($target)) {
    $mime = mime_content_type($target);
    if (is_string($mime) && $mime !== '') {
        header('Content-Type: ' . $mime);
    }
    readfile($target);
    exit;
}

$indexPath = $appDir . '/index.html';
if (is_file($indexPath)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($indexPath);
    exit;
}

http_response_code(500);
header('Content-Type: text/plain; charset=utf-8');
echo "Build output missing. Upload the generated app/ directory from dist-hostinger.";
