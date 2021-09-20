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

trait Updates
{
    /**
     * Boot updates component.
     *
     * @return void
     */
    public function bootUpdates()
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'pluginUpdate']);
        add_action("in_plugin_update_message-{$this->basename}", [$this, 'updateTokenNotice']);

        add_action('after_plugin_row', [$this, 'afterPluginRow'], 10, 3);

        // remove default must-use update notice
        add_action('after_plugin_row', function () {
            remove_action("after_plugin_row_{$this->basename}", 'wp_plugin_update_row');
        });
    }

    /**
     * Inject plugin into `update_plugins` transient.
     *
     * This seamlessly integrates updates into WordPress.
     *
     * @param  object  $transient
     * @return object|WP_Error
     */
    public function pluginUpdate($transient)
    {
        static $update = null;

        if (empty($transient->checked)) {
            return $transient;
        }

        if (! $update) {
            $update = $this->request('plugin/update');
        }

        if (is_wp_error($update)) {
            return $transient;
        }

        $group = version_compare($update->version, $this->version, '>')
            ? 'response'
            : 'no_update';

        $transient->{$group}[$this->basename] = (object) [
            'slug' => $this->slug(),
            'plugin' => $this->basename,
            'url' => $this->url,
            'new_version' => $update->version,
            'package' => $update->package,
            'tested' => $update->wp,
            'requires_php' => $update->php,
            'icons' => [
                'default' => "{$this->url}/assets/icon.png?v={$this->version}",
            ],
            'banners' => [
                'low' => "{$this->url}/assets/banner.png?v={$this->version}",
                'high' => "{$this->url}/assets/banner.png?v={$this->version}",
            ],
        ];

        return $transient;
    }

    /**
     * Display a notice to set the license token in the plugin list
     * when automatic updates are disabled.
     *
     * @return void
     */
    public function updateTokenNotice()
    {
        if ($this->token()) {
            return;
        }

        printf(
            '<br />To enable automatic updates, please <a href="%1$s" target="_blank">set your license token</a>.',
            'https://objectcache.pro/docs/configuration-options/#token'
        );
    }

    /**
     * Adds an update notice to the object cache drop and must-use plugin.
     *
     * @param  string  $file
     * @param  array  $data
     * @param  string  $status
     * @return void
     */
    public function afterPluginRow($file, $data, $status)
    {
        if ($file !== 'object-cache.php' && $status !== 'mustuse') {
            return;
        }

        if (! in_array('Object Cache Pro', [$data['Name'], $data['Title']])) {
            return;
        }

        if (! in_array('Redis Cache Pro', [$data['Name'], $data['Title']])) {
            return;
        }

        $updates = get_site_transient('update_plugins');
        $update = $updates->response[$this->basename] ?? null;

        if ($update) {
            require __DIR__ . '/templates/update.phtml';
        } elseif (version_compare($this->version, $data['Version'], '>')) {
            require __DIR__ . '/templates/outdated.phtml';
        }
    }
}
