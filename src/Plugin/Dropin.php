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

namespace RedisCachePro\Plugin;

trait Dropin
{
    /**
     * Boot dropin component.
     *
     * @return void
     */
    public function bootDropin()
    {
        add_action('upgrader_process_complete', [$this, 'maybeUpdateDropin'], 10, 2);
    }

    /**
     * Attempt to enable the object cache drop-in.
     *
     * @return bool
     */
    public function enableDropin()
    {
        global $wp_filesystem;

        if (! \WP_Filesystem()) {
            return false;
        }

        $dropin = \WP_CONTENT_DIR . '/object-cache.php';
        $stub = realpath(__DIR__ . '/../../stubs/object-cache.php');

        $result = $wp_filesystem->copy($stub, $dropin, true, FS_CHMOD_FILE);

        if (function_exists('wp_opcache_invalidate')) {
            wp_opcache_invalidate($dropin, true);
        }

        return $result;
    }

    /**
     * Attempt to disable the object cache drop-in.
     *
     * @return bool
     */
    public function disableDropin()
    {
        global $wp_filesystem;

        if (! \WP_Filesystem()) {
            return false;
        }

        $dropin = \WP_CONTENT_DIR . '/object-cache.php';

        if (! $wp_filesystem->exists($dropin)) {
            return false;
        }

        return $wp_filesystem->delete($dropin);
    }

    /**
     * Update the object cache drop-in, if it's outdated.
     *
     * @param  WP_Upgrader  $upgrader
     * @param  array  $options
     * @return void
     */
    public function maybeUpdateDropin($upgrader, $options)
    {
        if (! wp_is_file_mod_allowed('object_cache_dropin')) {
            return;
        }

        if ($options['action'] !== 'update' || $options['type'] !== 'plugin') {
            return;
        }

        if (! in_array($this->basename, $options['plugins'] ?? [])) {
            return;
        }

        $diagnostics = $this->diagnostics();

        if (! $diagnostics->dropinExists() || ! $diagnostics->dropinIsValid()) {
            return;
        }

        if ($diagnostics->dropinIsUpToDate()) {
            return;
        }

        $this->enableDropin();
    }
}
