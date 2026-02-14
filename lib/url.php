<?php
/**
 * URL helpers for friendly URLs
 */

function base_url(): string {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $base = dirname($script);
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
