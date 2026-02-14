<?php
/**
 * Router for PHP built-in server - enables friendly URLs when .htaccess is not used
 * Run: php -S localhost:8765 router.php
 */
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH);
$path = rtrim($path, '/') ?: '/';

if ($path === '/' || $path === '/index.php') {
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    include __DIR__ . '/index.php';
    return true;
}

if (preg_match('#^/collect/([a-z0-9-]+)/?$#', $path, $m)) {
    $_GET['p'] = $m[1];
    $_SERVER['SCRIPT_NAME'] = '/collect.php';
    include __DIR__ . '/collect.php';
    return true;
}

if (preg_match('#^/project/([a-z0-9-]+)/map/?$#', $path, $m)) {
    $_GET['p'] = $m[1];
    $_GET['view'] = 'map';
    $_SERVER['SCRIPT_NAME'] = '/project.php';
    include __DIR__ . '/project.php';
    return true;
}

if (preg_match('#^/project/([a-z0-9-]+)/edit/?$#', $path, $m)) {
    $_GET['p'] = $m[1];
    $_GET['edit'] = '1';
    $_SERVER['SCRIPT_NAME'] = '/project.php';
    include __DIR__ . '/project.php';
    return true;
}

if (preg_match('#^/project/([a-z0-9-]+)/?$#', $path, $m)) {
    $_GET['p'] = $m[1];
    $_SERVER['SCRIPT_NAME'] = '/project.php';
    include __DIR__ . '/project.php';
    return true;
}

// Static files and API - pass through
if (preg_match('#^/(assets/|api/|thumb\.php|photo\.php|index\.php|collect\.php|project\.php)#', $path) || file_exists(__DIR__ . $path)) {
    return false;
}

// 404
http_response_code(404);
echo 'Not Found';
return true;
