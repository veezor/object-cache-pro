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

trait Meta
{
    /**
     * Boot Meta component and register hooks.
     *
     * @return void
     */
    public function bootMeta()
    {
        add_filter('plugins_api', [$this, 'pluginInformation'], 10, 3);

        add_filter('plugin_row_meta', [$this, 'pluginRowMeta'], 10, 4);

        add_filter('plugin_action_links_object-cache.php', [$this, 'actionLinks']);
        add_filter("plugin_action_links_{$this->basename}", [$this, 'actionLinks']);
        add_filter("network_admin_plugin_action_links_{$this->basename}", [$this, 'actionLinks']);

        add_filter('manage_sites_action_links', [$this, 'siteActionLinks'], 10, 3);
    }

    /**
     * Adds useful links to the meta row of the plugin, must-use plugin and drop-in.
     *
     * @param  array  $links
     * @param  string  $file
     * @param  array  $data
     * @param  string  $status
     * @return array
     */
    public function pluginRowMeta($links, $file, $data, $status)
    {
        if (! in_array('Object Cache Pro', [$data['Name'], $data['Title']])) {
            return $links;
        }

        if (! in_array('Redis Cache Pro', [$data['Name'], $data['Title']])) {
            return $links;
        }

        if ($file !== $this->basename && $file !== 'object-cache.php') {
            return $links;
        }

        $append = [];

        if ($file === 'object-cache.php' || $status === 'mustuse') {
            $links = array_filter($links, function ($link) {
                return ! strpos($link, "href=\"{$this->url}");
            });

            $append[] = sprintf(
                '<a href="%s" class="thickbox open-plugin-details-modal">View details</a>',
                self_admin_url('plugin-install.php?' . http_build_query([
                    'tab' => 'plugin-information',
                    'plugin' => $this->basename,
                    'section' => 'changelog',
                    'TB_iframe' => 'true',
                    'width' => '600',
                    'height' => '800',
                ]))
            );
        }

        $append[] = sprintf('<a href="%s" target="_blank">Docs</a>', 'https://objectcache.pro/docs/');

        return array_merge($links, $append);
    }

    /**
     * Adds useful links to the plugin action link list.
     *
     * @param  array  $links
     * @return array
     */
    public function actionLinks($links)
    {
        global $wp_version;

        if (version_compare($wp_version, '5.2', '>=') && ! is_network_admin()) {
            $links = array_merge([
                sprintf('<a href="%s">Health</a>', admin_url('site-health.php')),
            ], $links);
        }

        return $links;
    }

    /**
     * Adds a "Flush" link to sites in "Network Admin -> Sites".
     *
     * @param  array  $actions
     * @param  int  $blog_id
     * @param  string  $blogname
     * @return array
     */
    public function siteActionLinks($actions, $blog_id, $blogname)
    {
        if (! $this->blogFlushingEnabled()) {
            return $actions;
        }

        if (! current_user_can('rediscache_manage') || ! current_user_can('manage_sites')) {
            return $actions;
        }

        if (! $this->diagnostics()->ping()) {
            return $actions;
        }

        array_splice($actions, 1, 0, [
            sprintf(
                '<a href="%s" title="Flush Object Cache">Flush</a>',
                esc_url(wp_nonce_url(
                    network_admin_url("sites.php?action=flush-blog-object-cache&id={$blog_id}"),
                    "flushblog_{$blog_id}"
                ))
            ),
        ]);

        return $actions;
    }

    /**
     * Fetch plugin information for update modal.
     *
     * @param  false|object|array  $result
     * @param  string  $action
     * @param  object  $args
     *
     * @return object|array|WP_Error
     */
    public function pluginInformation($result, $action = null, $args = null)
    {
        if ($action === 'plugin_information' && $args->slug === $this->slug()) {
            $info = $this->request('plugin/info');

            if (is_wp_error($info)) {
                return false;
            }

            return $info;
        }

        return $result;
    }
}
