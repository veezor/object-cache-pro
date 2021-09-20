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

use WP_Error;
use Exception;

use RedisCachePro\License;

trait Licensing
{
    /**
     * Boot licensing component.
     *
     * @return void
     */
    public function bootLicensing()
    {
        add_action('admin_notices', [$this, 'displayLicenseNotices'], 0);
        add_action('network_admin_notices', [$this, 'displayLicenseNotices'], 0);

        $this->justInCase();
    }

    /**
     * Return the license configured token.
     *
     * @return string|null
     */
    public function token()
    {
        if (! defined('WP_REDIS_CONFIG')) {
            return;
        }

        return \WP_REDIS_CONFIG['token'] ?? null;
    }

    /**
     * Display admin notices when license is unpaid/canceled,
     * and when no license token is set.
     *
     * @return void
     */
    public function displayLicenseNotices()
    {
        if (! current_user_can('activate_plugins')) {
            return;
        }

        $notice = function ($type, $text) {
            printf('<div class="notice notice-%s"><p>%s</p></div>', $type, $text);
        };

        $license = $this->license();

        if ($license->isCanceled()) {
            return $notice('error', implode(' ', [
                'Your Object Cache Pro license has expired, and the object cache will be disabled.',
                'Per the license agreement, you must uninstall the plugin.',
            ]));
        }

        if ($license->isUnpaid()) {
            return $notice('error', implode(' ', [
                'Your Object Cache Pro license payment is overdue.',
                sprintf(
                    'Please <a target="_blank" href="%s">update your payment information</a>.',
                    "{$this->url}/account"
                ),
                'If your license expires, the object cache will automatically be disabled.',
            ]));
        }

        if (! $this->token()) {
            return $notice('info', implode(' ', [
                'The Object Cache Pro license token has not been set and plugin updates have been disabled.',
                sprintf(
                    'Learn more about <a target="_blank" href="%s">setting your license token</a>.',
                    'https://objectcache.pro/docs/configuration-options/#token'
                ),
            ]));
        }

        if ($license->isInvalid()) {
            return $notice('error', 'The Object Cache Pro license token is invalid and plugin updates have been disabled.');
        }

        if ($license->isDeauthorized()) {
            return $notice('error', 'The Object Cache Pro license token could not be verified and plugin updates have been disabled.');
        }
    }

    /**
     * Returns the license object.
     *
     * Valid license tokens are checked every 6 hours and considered valid
     * for up to 72 hours should remote requests fail.
     *
     * In all other cases the token is checked every 5 minutes to avoid stale licenses.
     *
     * @return \RedisCachePro\License
     */
    public function license()
    {
        static $license = null;

        if ($license) {
            return $license;
        }

        $license = License::load();

        // if no license is stored or the token has changed, always attempt to fetch it
        if (! $license || ! $license->isToken($this->token())) {
            $response = $this->fetchLicense();

            if (is_wp_error($response)) {
                $license = License::fromError($response);
            } else {
                $license = License::fromResponse($response);
            }

            return $license;
        }

        // deauthorize valid licenses that could not be verified after 72h
        if ($license->isValid() && $license->hoursSinceVerification(72)) {
            $license->deauthorize();

            return $license;
        }

        // verify valid licenses every 6 hours and
        // attempt to update invalid licenses every 5 minutes
        if (
            ($license->isValid() && $license->minutesSinceLastCheck(6 * 60)) ||
            (! $license->isValid() && $license->minutesSinceLastCheck(5))
        ) {
            $response = $this->fetchLicense();

            if (is_wp_error($response)) {
                $license = $license->checkFailed($response);
            } else {
                $license = License::fromResponse($response);
            }
        }

        return $license;
    }

    /**
     * Fetch the license for configured token.
     *
     * @return object|WP_Error
     */
    protected function fetchLicense()
    {
        $response = $this->request('license');

        if (is_wp_error($response)) {
            return new WP_Error('rediscache_fetch_failed', sprintf(
                'Could not verify license. %s',
                $response->get_error_message()
            ), [
                'token' => $this->token(),
            ]);
        }

        return $response;
    }

    /**
     * Perform API request.
     *
     * @param  string  $action
     * @return object|WP_Error
     */
    protected function request($action)
    {
        $response = wp_remote_post("{$this->url}/api/{$action}", [
            'headers' => [
                'Accept' => 'application/json',
            ],
            'body' => $this->telemetry(),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $status = $response['response']['code'];
        $body = wp_remote_retrieve_body($response);

        if ($status >= 400) {
            return new WP_Error('rediscache_server_error', "Request returned status code {$status}.");
        }

        $json = (object) json_decode($response['body'], true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('rediscache_json_error', json_last_error_msg(), $body);
        }

        return $json;
    }

    /**
     * The telemetry send along with requests.
     *
     * @return array
     */
    protected function telemetry()
    {
        global $wp_object_cache;

        $isMultisite = is_multisite();
        $diagnostics = $this->diagnostics()->toArray();

        $info = method_exists($wp_object_cache, 'info')
            ? $wp_object_cache->info()
            : null;

        $sites = $isMultisite && function_exists('wp_count_sites')
            ? wp_count_sites()['all']
            : null;

        return [
            'token' => $this->token(),
            'url' => static::telemetry_home_url(),
            'network_url' => static::telemetry_network_url(),
            'network' => $isMultisite,
            'sites' => $sites,
            'locale' => get_locale(),
            'wordpress' => get_bloginfo('version'),
            'woocommerce' => defined('\WC_VERSION') ? \WC_VERSION : null,
            'php' => phpversion(),
            'phpredis' => phpversion('redis'),
            'igbinary' => phpversion('igbinary'),
            'openssl' => phpversion('openssl'),
            'status' => $diagnostics['general']['status']->value,
            'plugin' => $diagnostics['versions']['plugin']->value,
            'dropin' => $diagnostics['versions']['dropin']->value,
            'redis' => $diagnostics['versions']['redis']->value,
            'scheme' => $diagnostics['config']['scheme']->value,
            'cache' => $info->meta['Cache'] ?? null,
            'connection' => $info->meta['Connection'] ?? null,
        ];
    }

    /**
     * Returns the WordPress home URL.
     *
     * @return string
     */
    public static function telemetry_home_url()
    {
        $url = home_url();

        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }

        return static::telemetry_fallback_url();
    }

    /**
     * Returns the WordPress network URL.
     *
     * @return string
     */
    public static function telemetry_network_url()
    {
        $url = network_home_url();

        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }

        return static::telemetry_fallback_url();
    }

    /**
     * Builds and returns the URL of current request from server variables.
     *
     * @return string
     */
    public static function telemetry_fallback_url()
    {
        $url = is_ssl() ? 'https://' : 'http://';

        if (! empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
            return $url . $_SERVER['HTTP_X_FORWARDED_HOST'];
        }

        return $url . $_SERVER['HTTP_HOST'];
    }

    /**
     * Call home every hundredth admin request using a shutdown callback,
     * to make them h4xx0rz work a little harder.
     *
     * @return void
     */
    protected function justInCase()
    {
        if (mt_rand(1, 100) !== 42 || ! is_admin()) {
            return;
        }

        register_shutdown_function(function () {
            try {
                if (! did_action('admin_head')) {
                    return;
                }

                $config = defined('\WP_REDIS_CONFIG') ? \WP_REDIS_CONFIG : [];

                file_get_contents('https://objectcache.pro/api/license', false, stream_context_create([
                    'http' => [
                        'method' => 'POST',
                        'timeout' => 2,
                        'ignore_errors' => true,
                        'header' => 'Content-Type: application/x-www-form-urlencoded',
                        'content' => http_build_query([
                            'token' => $config['token'] ?? null,
                            'url' => static::telemetry_home_url(),
                            'network_url' => static::telemetry_network_url(),
                            'network' => is_multisite(),
                            'locale' => get_locale(),
                            'wordpress' => get_bloginfo('version'),
                            'woocommerce' => defined('\WC_VERSION') ? \WC_VERSION : null,
                            'php' => phpversion(),
                            'phpredis' => phpversion('redis'),
                            'igbinary' => phpversion('igbinary'),
                            'openssl' => phpversion('openssl'),
                        ]),
                    ],
                ]));
            } catch (Exception $e) {
                //
            }
        });
    }
}
