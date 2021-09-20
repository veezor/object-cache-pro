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

trait Widget
{
    /**
     * Whitelist of widget actions.
     *
     * @var array
     */
    protected $widgetActions = [
        'flush-cache',
        'enable-dropin',
        'update-dropin',
        'disable-dropin',
    ];

    /**
     * Whitelist of widget action statuses.
     *
     * @var array
     */
    protected $widgetActionStatuses = [
        'cache-flushed',
        'cache-not-flushed',
        'dropin-enabled',
        'dropin-not-enabled',
        'dropin-updated',
        'dropin-not-updated',
        'dropin-disabled',
        'dropin-not-disabled',
    ];

    /**
     * Boot widget component.
     *
     * @return void
     */
    public function bootWidget()
    {
        add_action('admin_init', [$this, 'registerWidget']);
    }

    /**
     * Register the dashboard widget.
     *
     * @return void
     */
    public function registerWidget()
    {
        if (! current_user_can('rediscache_manage')) {
            return;
        }

        add_action('load-index.php', [$this, 'handleWidgetActions']);
        add_action('admin_print_styles-index.php', [$this, 'printWidgetStyles']);

        add_action('admin_notices', [$this, 'displayWidgetNotice'], 0);
        add_action('network_admin_notices', [$this, 'displayWidgetNotice'], 0);

        add_action('wp_dashboard_setup', function () {
            wp_add_dashboard_widget('dashboard_rediscachepro', 'Object Cache Pro', [$this, 'renderWidget']);
        });

        add_action('wp_network_dashboard_setup', function () {
            wp_add_dashboard_widget('dashboard_rediscachepro', 'Object Cache Pro', [$this, 'renderWidget']);
        });
    }

    /**
     * Render the dashboard widget.
     *
     * @return void
     */
    public function renderWidget()
    {
        global $wp_object_cache_errors;

        require __DIR__ . '/templates/widget.phtml';
    }

    /**
     * Handle widget actions and redirect back to dashboard.
     *
     * @return void
     */
    public function handleWidgetActions()
    {
        if (! isset($_GET['rediscache-action'], $_GET['_wpnonce'])) {
            return;
        }

        $action = $_GET['rediscache-action'];

        if (! in_array($action, $this->widgetActions)) {
            wp_die('Invalid action.', 400);
        }

        if (! wp_verify_nonce($_GET['_wpnonce'], $action)) {
            wp_die("Invalid nonce for {$action} action.", 400);
        }

        if (is_multisite() && ! is_network_admin() && ! in_array($action, ['flush-cache'])) {
            wp_die("Sorry, you are not allowed to perform the {$action} action.", 403);
        }

        switch ($action) {
            case 'flush-cache':
                $status = wp_cache_flush() ? 'cache-flushed' : 'cache-not-flushed';
                break;
            case 'enable-dropin':
                $status = $this->enableDropin() ? 'dropin-enabled' : 'dropin-not-enabled';
                break;
            case 'update-dropin':
                $status = $this->enableDropin() ? 'dropin-updated' : 'dropin-not-updated';
                break;
            case 'disable-dropin':
                $status = $this->disableDropin() ? 'dropin-disabled' : 'dropin-not-disabled';
                break;
        }

        $url = is_network_admin() ? network_admin_url() : admin_url();

        wp_safe_redirect(add_query_arg('rediscache-status', $status, $url));
        exit;
    }

    /**
     * Print the widget styles inlines to support non-standard installs.
     *
     * @return void
     */
    public function printWidgetStyles()
    {
        $styles = file_get_contents(
            __DIR__ . '/../../resources/css/styles.css'
        );

        $styles = preg_replace('/(\v|\s{2,})/', ' ', $styles);
        $styles = preg_replace('/\s+/', ' ', $styles);

        printf('<style type="text/css">%s</style>%s', trim($styles), PHP_EOL);
    }

    /**
     * Display status notices for widget actions.
     *
     * @return void
     */
    public function displayWidgetNotice()
    {
        if (! isset($_GET['rediscache-status'])) {
            return;
        }

        if (! in_array($_GET['rediscache-status'], $this->widgetActionStatuses)) {
            return;
        }

        $notice = function ($type, $text) {
            return sprintf('<div class="notice notice-%s"><p>%s</p></div>', $type, $text);
        };

        switch ($_GET['rediscache-status']) {
            case 'cache-flushed':
                echo $notice('success', 'The object cache was flushed.');
                break;
            case 'cache-not-flushed':
                echo $notice('error', 'The object cache could not be flushed.');
                break;
            case 'dropin-enabled':
                echo $notice('success', 'The object cache drop-in was enabled.');
                break;
            case 'dropin-not-enabled':
                echo $notice('error', 'The object cache drop-in could not be enabled.');
                break;
            case 'dropin-updated':
                echo $notice('success', 'The object cache drop-in was updated.');
                break;
            case 'dropin-not-updated':
                echo $notice('error', 'The object cache drop-in could not be updated.');
                break;
            case 'dropin-disabled':
                echo $notice('success', 'The object cache drop-in was disabled.');
                break;
            case 'dropin-not-disabled':
                echo $notice('error', 'The object cache drop-in could not be disabled.');
                break;
        }
    }
}
