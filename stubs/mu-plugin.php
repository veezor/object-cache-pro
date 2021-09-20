<?php
/*
 * Plugin Name: Object Cache Pro (MU)
 * Plugin URI: https://objectcache.pro
 * Description: A business class Redis object cache backend for WordPress.
 * Version: 1.13.3
 * Author: Rhubarb Group
 * Author URI: https://rhubarb.group
 * License: Proprietary
 * Requires PHP: 7.0
 */

if (! defined('ABSPATH')) {
    exit;
}

define('RedisCachePro\Basename', basename(__FILE__));

foreach ([
    defined('WP_REDIS_DIR') ? rtrim(WP_REDIS_DIR, '/') : null,
    __DIR__ . '/redis-cache-pro',
    __DIR__ . '/object-cache-pro',
] as $path) {
    if ($path === null || ! is_readable("{$path}/redis-cache-pro.php")) {
        continue;
    }

    if (include_once "{$path}/redis-cache-pro.php") {
        return;
    }
}

error_log('objectcache.critical: Failed to locate and load Object Cache Pro plugin.');

if (defined('WP_DEBUG') && WP_DEBUG) {
    throw new RuntimeException('Failed to locate and load Object Cache Pro plugin.');
}
