<?php
/**
 * URL helpers for friendly URLs
 */

function base_url(): string {
    if (defined('APP_BASE_URL') && APP_BASE_URL) {
        return rtrim(APP_BASE_URL, '/');
    }

    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    if (!is_string($host) || !preg_match('/^[a-z0-9.-]+(?::\d+)?$/i', $host)) {
        $host = 'localhost';
    }
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $base = dirname($script);
    // API requests run under /api/export.php; keep base URL at app root.
    if ($base === '/api') {
        $base = '';
    } elseif (preg_match('#/api$#', $base)) {
        $base = preg_replace('#/api$#', '', $base);
    }
    if ($base === '/' || $base === '\\') {
        $base = '';
    }
    return ($https ? 'https' : 'http') . '://' . $host . $base;
}

function url_collect(string $slug): string {
    return base_url() . '/collect/' . urlencode($slug);
}

function url_project(string $slug, ?string $view = null): string {
    $url = base_url() . '/project/' . urlencode($slug);
    if ($view === 'map') {
        $url .= '/map';
    } elseif ($view === 'edit') {
        $url .= '/edit';
    }
    return $url;
}

function url_index(?string $query = null): string {
    $url = base_url() . '/';
    if ($query) {
        $url .= '?' . $query;
    }
    return $url;
}

function url_asset(string $path): string {
    $path = ltrim($path, '/');
    return base_url() . '/' . $path;
}
