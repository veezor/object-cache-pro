<?php
/*
 * Plugin Name: Object Cache Pro
 * Plugin URI: https://objectcache.pro
 * Description: A business class Redis object cache backend for WordPress.
 * Version: 1.13.3
 * Author: Rhubarb Group
 * Author URI: https://rhubarb.group
 * License: Proprietary
 * Network: true
 * Requires PHP: 7.0
 */

defined('ABSPATH') || exit;

/*
 * The plugin version number.
 */
define('RedisCachePro\Version', '1.13.3');

/*
 * Register WP CLI commands when running in console.
 */
if (defined('WP_CLI') && WP_CLI) {
    require_once dirname(__FILE__) . '/src/Commands.php';

    WP_CLI::add_command('redis', \RedisCachePro\Commands::class);
}

/**
 * Bootstrap the plugin and instantiate it.
 */
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/src/License.php';
require_once __DIR__ . '/src/Plugin/Authorization.php';
require_once __DIR__ . '/src/Plugin/Dropin.php';
require_once __DIR__ . '/src/Plugin/Health.php';
require_once __DIR__ . '/src/Plugin/Licensing.php';
require_once __DIR__ . '/src/Plugin/Lifecycle.php';
require_once __DIR__ . '/src/Plugin/Meta.php';
require_once __DIR__ . '/src/Plugin/Network.php';
require_once __DIR__ . '/src/Plugin/Updates.php';
require_once __DIR__ . '/src/Plugin/Widget.php';
require_once __DIR__ . '/src/Plugin/Extensions/Debugbar.php';
require_once __DIR__ . '/src/Plugin/Extensions/QueryMonitor.php';
require_once __DIR__ . '/src/Plugin.php';

add_action('plugins_loaded', function () {
    if (! defined('RedisCachePro\Basename')) {
        define('RedisCachePro\Basename', plugin_basename(__FILE__));
    }

    $GLOBALS['RedisCachePro'] = \RedisCachePro\Plugin::boot();
});

add_action('activated_plugin', function ($plugin) {
    if ($plugin === plugin_basename(__FILE__)) {
        deactivate_plugins('redis-cache/redis-cache.php', true, is_multisite());
    }
});
