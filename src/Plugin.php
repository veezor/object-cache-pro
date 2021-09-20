<?php
/**
 * Copyright © Rhubarb Tech Inc. All Rights Reserved.
 *
 * All information contained herein is, and remains the property of Rhubarb Tech Incorporated.
 * The intellectual and technical concepts contained herein are proprietary to Rhubarb Tech Incorporated and
 * are protected by trade secret or copyright law. Dissemination and modification of this information or
 * reproduction of this material is strictly forbidden unless prior written permission is obtained from
 * Rhubarb Tech Incorporated.
 *
 * You should have received a copy of the `LICENSE` with this file. If not, please visit:
 * https://objectcache.pro/license.txt
 *
 * Kelvin, you have my full attention. How rich we will make our lawyers is up to you.
 */

declare(strict_types=1);

namespace RedisCachePro;

use RedisCachePro\Diagnostics\Diagnostics;
use RedisCachePro\Configuration\Configuration;

class Plugin
{
    use Plugin\Authorization,
        Plugin\Dropin,
        Plugin\Health,
        Plugin\Licensing,
        Plugin\Lifecycle,
        Plugin\Meta,
        Plugin\Network,
        Plugin\Updates,
        Plugin\Widget,
        Plugin\Extensions\Debugbar,
        Plugin\Extensions\QueryMonitor;

    /**
     * The configuration instance.
     *
     * @var \RedisCachePro\Configuration\Configuration
     */
    protected $config;

    /**
     * Holds the plugin version number.
     *
     * @var string
     */
    protected $version;

    /**
     * Holds the plugin basename.
     *
     * @var string
     */
    protected $basename;

    /**
     * Holds the plugin website.
     *
     * @var string
     */
    protected $url = 'https://objectcache.pro';

    /**
     * Initialize the plugin, load all extensions and register lifecycle hooks.
     *
     * @return self
     */
    public static function boot()
    {
        global $wp_object_cache;

        $instance = new static;
        $instance->version = Version;
        $instance->basename = Basename;

        if (method_exists($wp_object_cache, 'config')) {
            $instance->config = $wp_object_cache->config();
        } else {
            $instance->config = Configuration::safelyFrom(
                defined('\WP_REDIS_CONFIG') ? \WP_REDIS_CONFIG : []
            );
        }

        foreach (class_uses($instance) as $class) {
            $name = substr($class, strrpos($class, '\\') + 1);

            $instance->{"boot{$name}"}();
        }

        return $instance;
    }

    /**
     * Returns the cleaned up basename.
     *
     * @return string
     */
    protected function slug()
    {
        return strpos($this->basename, '/') === false
            ? $this->basename
            : dirname($this->basename);
    }

    /**
     * Returns a singleton diagnostics instance.
     *
     * @return \RedisCachePro\Diagnostics\Diagnostics
     */
    public function diagnostics()
    {
        global $wp_object_cache;

        static $diagnostics = null;

        if (! $diagnostics) {
            $diagnostics = new Diagnostics($wp_object_cache);
        }

        return $diagnostics;
    }
}
