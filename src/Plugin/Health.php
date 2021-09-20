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

use RedisCachePro\Diagnostics\Diagnostics;

trait Health
{
    /**
     * Boot health component.
     *
     * @return void
     */
    public function bootHealth()
    {
        add_filter('debug_information', [$this, 'healthDebugInformation'], 1);
        add_filter('site_status_tests', [$this, 'healthStatusTests'], 1);

        add_action('wp_ajax_health-check-rediscache-license', [$this, 'healthTestLicense']);
        add_action('wp_ajax_health-check-rediscache-filesystem', [$this, 'healthTestFilesystem']);
        add_action('wp_ajax_health-check-rediscache-servers', [$this, 'healthTestServers']);
    }

    /**
     * Callback for WordPress’ `debug_information` hook.
     *
     * Adds diagnostic information to: Tools > Site Health > Info
     *
     * @param  array  $debug
     * @return array
     */
    public function healthDebugInformation($debug)
    {
        $fields = [];
        $diagnostics = $this->diagnostics();

        foreach ($diagnostics->toArray() as $groupName => $group) {
            if (empty($group)) {
                continue;
            }

            if ($groupName === Diagnostics::ERRORS) {
                continue;
            }

            foreach ($group as $name => $diagnostic) {
                if (is_null($diagnostic->value)) {
                    continue;
                }

                $fields["{$groupName}-{$name}"] = [
                    'label' => $diagnostic->name,
                    'value' => $diagnostic->withComment()->text,
                    'private' => false,
                ];
            }
        }

        $debug['rediscache'] = [
            'label' => 'Object Cache Pro',
            'description' => 'Diagnostic information about your object cache, its configuration, and Redis.',
            'fields' => $fields,
        ];

        return $debug;
    }

    /**
     * Callback for WordPress’ `site_status_tests` hook.
     *
     * Adds diagnostic tests to: Tools > Site Health > Status
     *
     * @param  array  $debug
     * @return array
     */
    public function healthStatusTests($tests)
    {
        global $wp_object_cache_errors;

        $tests['async']['rediscache_license'] = [
            'label' => 'Object Cache Pro license',
            'test' => 'rediscache-license',
        ];

        $tests['async']['rediscache_servers'] = [
            'label' => 'Object Cache Pro servers',
            'test' => 'rediscache-servers',
        ];

        $tests['direct']['rediscache_config'] = [
            'label' => 'Object Cache Pro configuration',
            'test' => function () {
                return $this->healthTestConfiguration();
            },
        ];

        /*
         * Whether to run the filesystem health check.
         *
         * @param  bool  $run_check  Whether to run the filesystem health check.
         */
        if (apply_filters('rediscache_check_filesystem', true)) {
            $tests['async']['rediscache_filesystem'] = [
                'label' => 'Object Cache Pro filesystem',
                'test' => 'rediscache-filesystem',
            ];
        }

        $diagnostics = $this->diagnostics();

        $tests['direct']['rediscache_state'] = [
            'label' => 'Object cache state',
            'test' => function () use ($diagnostics) {
                return $this->healthTestState($diagnostics);
            },
        ];

        if ($diagnostics->isDisabled()) {
            return $tests;
        }

        $tests['direct']['rediscache_dropin'] = [
            'label' => 'Object cache dropin',
            'test' => function () use ($diagnostics) {
                return $this->healthTestDropin($diagnostics);
            },
        ];

        if (! $diagnostics->dropinExists() || ! $diagnostics->dropinIsValid()) {
            return $tests;
        }

        $tests['direct']['rediscache_errors'] = [
            'label' => 'Object cache errors',
            'test' => function () use ($diagnostics) {
                return $this->healthTestErrors($diagnostics);
            },
        ];

        if (! empty($wp_object_cache_errors)) {
            return $tests;
        }

        $tests['direct']['rediscache_connection'] = [
            'label' => 'Object cache connection',
            'test' => function () use ($diagnostics) {
                return $this->healthTestConnection($diagnostics);
            },
        ];

        $tests['direct']['rediscache_eviction_policy'] = [
            'label' => 'Redis eviction policy',
            'test' => function () use ($diagnostics) {
                return $this->healthTestEvictionPolicy($diagnostics);
            },
        ];

        if ($this->config->async_flush) {
            $tests['direct']['rediscache_async_support'] = [
                'label' => 'Asynchronous Redis commands',
                'test' => function () use ($diagnostics) {
                    return $this->healthTestAsyncSupport($diagnostics);
                },
            ];
        }

        return $tests;
    }

    /**
     * Test whether the configuration could be instantiated.
     *
     * @return array
     */
    protected function healthTestConfiguration()
    {
        if (! $this->config->initException) {
            return [
                'label' => 'Configuration instantiated',
                'description' => '<p>The Object Cache Pro configuration could be instantiated.</p>',
                'badge' => ['label' => 'Object Cache Pro', 'color' => 'blue'],
                'status' => 'good',
                'test' => 'rediscache_config',
            ];
        }

        return [
            'label' => 'Configuration could not be instantiated',
            'description' => sprintf(
                '<p>An error occurred during the instantiation of the configuration.</p><p><code>%s</code></p>',
                esc_html($this->config->initException->getMessage())
            ),
            'badge' => ['label' => 'Object Cache Pro', 'color' => 'red'],
            'status' => 'critical',
            'test' => 'rediscache_config',
        ];
    }

    /**
     * Test whether the object cache was disabled via constant or environment variable.
     *
     * @param  \RedisCachePro\Diagnostics\Diagnostics  $diagnostics
     * @return array
     */
    protected function healthTestState(Diagnostics $diagnostics)
    {
        if (! $diagnostics->isDisabled()) {
            return [
                'label' => 'Object cache is not disabled',
                'description' => '<p>The Redis object cache is not disabled using the <code>WP_REDIS_DISABLED</code> constant or environment variable.</p>',
                'badge' => ['label' => 'Object Cache Pro', 'color' => 'blue'],
                'status' => 'good',
                'test' => 'rediscache_state',
            ];
        }

        return [
            'label' => 'Object cache is disabled',
            'description' => $diagnostics->isDisabledUsingEnvVar()
                ? '<p>The Redis object cache is disabled because the <code>WP_REDIS_DISABLED</code> constant is set and truthy.</p>'
                : '<p>The Redis object cache is disabled because the <code>WP_REDIS_DISABLED</code> environment variable set and is truthy.</p>',
            'badge' => ['label' => 'Object Cache Pro', 'color' => 'orange'],
            'status' => 'recommended',
            'test' => 'rediscache_state',
        ];
    }

    /**
     * Test whether the object cache drop-in exists, is valid and up-to-date.
     *
     * @param  \RedisCachePro\Diagnostics\Diagnostics  $diagnostics
     * @return array
     */
    protected function healthTestDropin(Diagnostics $diagnostics)
    {
        if (! $diagnostics->dropinExists()) {
            return [
                'label' => 'Object cache drop-in is not installed',
                'description' => sprintf(
                    '<p>%s</p>',
                    implode(' ', [
                        'The Object Cache Pro object cache drop-in is not installed and Redis is not being used.',
                        'Use the Dashboard Widget or WP CLI to enable the object cache drop-in.',
                    ])
                ),
                'badge' => ['label' => 'Object Cache Pro', 'color' => 'red'],
                'status' => 'critical',
                'test' => 'rediscache_dropin',
            ];
        }

        if (! $diagnostics->dropinIsValid()) {
            return [
                'label' => 'Invalid object cache drop-in detected',
                'description' => sprintf(
                    '<p>%s</p>',
                    implode(' ', [
                        'WordPress is using a foreign object cache drop-in and Object Cache Pro is not being used.',
                        'Use the Dashboard Widget or WP CLI to enable the object cache drop-in.',
                    ])
                ),
                'badge' => ['label' => 'Object Cache Pro', 'color' => 'red'],
                'status' => 'critical',
                'test' => 'rediscache_dropin',
            ];
        }

        if (! $diagnostics->dropinIsUpToDate()) {
            return [
                'label' => 'Object cache drop-in outdated',
                'description' => '<p>The Redis object cache drop-in is outdated and should be updated.</p>',
                'badge' => ['label' => 'Object Cache Pro', 'color' => 'orange'],
                'status' => 'recommended',
                'test' => 'rediscache_dropin',
            ];
        }

        return [
            'label' => 'Object cache drop-in up to date',
            'description' => '<p>The Redis object cache drop-in exists, is valid and up to date.</p>',
            'badge' => ['label' => 'Object Cache Pro', 'color' => 'blue'],
            'status' => 'good',
            'test' => 'rediscache_dropin',
        ];
    }

    /**
     * Test whether the object cache encountered any errors.
     *
     * @param  \RedisCachePro\Diagnostics\Diagnostics  $diagnostics
     * @return array
     */
    protected function healthTestErrors(Diagnostics $diagnostics)
    {
        global $wp_object_cache_errors;

        if (! empty($wp_object_cache_errors)) {
            return [
                'label' => 'Object cache errors occurred',
                'description' => sprintf(
                    '<p>The object cache encountered errors.</p><ul>%s</ul>',
                    implode(', ', array_map(function ($error) {
                        return "<li><b>{$error}</b></li>";
                    }, $wp_object_cache_errors))
                ),
                'badge' => ['label' => 'Object Cache Pro', 'color' => 'red'],
                'status' => 'critical',
                'test' => 'rediscache_errors',
            ];
        }

        return [
            'label' => 'No object cache errors occurred',
            'description' => '<p>The object cache did not encounter any errors.</p>',
            'badge' => ['label' => 'Object Cache Pro', 'color' => 'blue'],
            'status' => 'good',
            'test' => 'rediscache_errors',
        ];
    }

    /**
     * Test whether the object cache established a connection to Redis.
     *
     * @param  \RedisCachePro\Diagnostics\Diagnostics  $diagnostics
     * @return array
     */
    protected function healthTestConnection(Diagnostics $diagnostics)
    {
        if (! $diagnostics->ping()) {
            return [
                'label' => 'Object cache is not connected to Redis',
                'description' => '<p>The object cache is not connected to Redis.</p>',
                'badge' => ['label' => 'Object Cache Pro', 'color' => 'red'],
                'status' => 'critical',
                'test' => 'rediscache_connection',
            ];
        }

        return [
            'label' => 'Object cache is connected to Redis',
            'description' => '<p>The object cache is connected to Redis.</p>',
            'badge' => ['label' => 'Object Cache Pro', 'color' => 'blue'],
            'status' => 'good',
            'test' => 'rediscache_connection',
        ];
    }

    /**
     * Test whether Redis uses the noeviction policy.
     *
     * @param  \RedisCachePro\Diagnostics\Diagnostics  $diagnostics
     * @return array
     */
    protected function healthTestEvictionPolicy(Diagnostics $diagnostics)
    {
        $policy = $diagnostics->maxMemoryPolicy();

        if ($policy !== 'noeviction') {
            return [
                'label' => "Redis uses the {$policy} policy",
                'description' => "Redis is configured to use the {$policy} policy.",
                'badge' => ['label' => 'Object Cache Pro', 'color' => 'blue'],
                'status' => 'good',
                'test' => 'rediscache_eviction_policy',
            ];
        }

        return [
            'label' => 'Redis uses the noeviction policy',
            'description' => sprintf(
                '<p>%s</p>',
                implode(' ', [
                    'Redis is configured to use the <code>noeviction</code> policy, which might crash your site when Redis runs out of memory.',
                    'Setting a reasonable MaxTTL (maximum time-to-live) helps to reduce that risk.',
                ])
            ),
            'badge' => ['label' => 'Object Cache Pro', 'color' => 'orange'],
            'status' => 'recommended',
            'test' => 'rediscache_eviction_policy',
            'actions' => sprintf(
                '<p><a href="%s" target="_blank">%s</a><p>',
                'https://objectcache.pro/docs/diagnostics/#eviction-policy',
                'Learn more about the eviction policy.'
            ),
        ];
    }

    /**
     * Test whether Redis supports asynchronous commands.
     *
     * @param  \RedisCachePro\Diagnostics\Diagnostics  $diagnostics
     * @return array
     */
    protected function healthTestAsyncSupport(Diagnostics $diagnostics)
    {
        $redisVersion = (string) $diagnostics->redisVersion()->value;

        if (version_compare($redisVersion, '4.0', '>=')) {
            return [
                'label' => 'Redis supports asynchronous commands',
                'description' => '<p>The Redis connection supports asynchronous commands.</p>',
                'badge' => ['label' => 'Object Cache Pro', 'color' => 'blue'],
                'status' => 'good',
                'test' => 'rediscache_async_support',
            ];
        }

        return [
            'label' => 'Redis does not support asynchronous commands',
            'description' => sprintf(
                '<p>%s</p>',
                implode(' ', [
                    'Object Cache Pro is configured to use asynchronous commands,',
                    "but the connected Redis server ({$redisVersion}) is too old and does not support them.",
                    'Upgrade Redis to version 4.0 or newer, or disable asynchronous flushing.',
                ])
            ),
            'badge' => ['label' => 'Object Cache Pro', 'color' => 'red'],
            'status' => 'critical',
            'test' => 'rediscache_async_support',
            'actions' => sprintf(
                '<p><a href="%s" target="_blank">%s</a></p>',
                'https://objectcache.pro/docs/configuration-options/#asynchronous-flushing',
                'Learn more.'
            ),
        ];
    }

    /**
     * Callback for `wp_ajax_health-check-rediscache-license` hook.
     *
     * @return void
     */
    public function healthTestLicense()
    {
        check_ajax_referer('health-check-site-status');

        if (! $this->token()) {
            wp_send_json_success([
                'label' => 'No license token set',
                'description' => '<p>No Object Cache Pro license token was set and plugin updates are disabled.</p>',
                'badge' => ['label' => 'Object Cache Pro', 'color' => 'red'],
                'status' => 'critical',
                'test' => 'rediscache_license',
                'actions' => sprintf(
                    '<p><a href="%s" target="_blank">%s</a></p>',
                    'https://objectcache.pro/docs/configuration-options/#token',
                    'Learn more about setting the license token.'
                ),
            ]);
        }

        $license = $this->license();

        if ($license->isInvalid()) {
            wp_send_json_success([
                'label' => 'Invalid license token',
                'description' => sprintf(
                    '<p>The license token <code>••••••••%s</code> appears to be invalid.</p>',
                    substr($license->token(), -4)
                ),
                'badge' => ['label' => 'Object Cache Pro', 'color' => 'red'],
                'status' => 'critical',
                'test' => 'rediscache_license',
            ]);
        }

        if ($license->isValid()) {
            wp_send_json_success([
                'label' => 'License token is set and license is valid',
                'description' => '<p>The Object Cache Pro license token is set and the license is valid.</p>',
                'badge' => ['label' => 'Object Cache Pro', 'color' => 'blue'],
                'status' => 'good',
                'test' => 'rediscache_license',
            ]);
        }

        wp_send_json_success([
            'label' => "License is {$license->state()}",
            'description' => "<p>Your Object Cache Pro license is {$license->state()}.</p>",
            'badge' => ['label' => 'Object Cache Pro', 'color' => 'red'],
            'status' => 'critical',
            'test' => 'rediscache_license',
            'actions' => implode('', [
                sprintf(
                    '<p><a href="%s" target="_blank">%s</a><p>',
                    'https://objectcache.pro/account',
                    'Manage your billing information'
                ),
                sprintf(
                    '<p><a href="%s" target="_blank">%s</a><p>',
                    'https://objectcache.pro/support',
                    'Contact customer service'
                ),
            ]),
        ]);
    }

    /**
     * Callback for `wp_ajax_health-check-rediscache-servers` hook.
     *
     * @return void
     */
    public function healthTestServers()
    {
        check_ajax_referer('health-check-site-status');

        $response = $this->request('license');

        if (is_wp_error($response) && strpos($response->get_error_code(), 'rediscache_', 0) === false) {
            wp_send_json_success([
                'label' => 'Licensing servers are unreachable',
                'description' => sprintf(
                    '<p>WordPress is unable to communicate with the Object Cache Pro licensing servers.</p><p><code>%s</code></p>',
                    esc_html($response->get_error_message())
                ),
                'badge' => ['label' => 'Object Cache Pro', 'color' => 'red'],
                'status' => 'critical',
                'test' => 'rediscache_servers',
            ]);
        }

        wp_send_json_success([
            'label' => 'Licensing servers are reachable',
            'description' => '<p>The Object Cache Pro licensing servers are reachable.</p>',
            'badge' => ['label' => 'Object Cache Pro', 'color' => 'blue'],
            'status' => 'good',
            'test' => 'rediscache_servers',
        ]);
    }

    /**
     * Callback for `wp_ajax_health-check-rediscache-filesystem` hook.
     *
     * @return void
     */
    public function healthTestFilesystem()
    {
        check_ajax_referer('health-check-site-status');

        $fs = $this->diagnostics()->filesystemAccess();

        if (is_wp_error($fs)) {
            wp_send_json_success([
                'label' => 'Unable to manage object cache drop-in',
                'description' => sprintf(
                    '<p>Object Cache Pro is unable to access the local filesystem and cannot manage the object cache drop-in.</p><p><code>%s</code></p>',
                    esc_html($fs->get_error_message())
                ),
                'badge' => ['label' => 'Object Cache Pro', 'color' => 'red'],
                'status' => 'critical',
                'test' => 'rediscache_filesystem',
            ]);
        }

        wp_send_json_success([
            'label' => 'Object cache drop-in can be managed',
            'description' => '<p>Object Cache Pro can access the local filesystem and is able manage the object cache drop-in.</p>',
            'badge' => ['label' => 'Object Cache Pro', 'color' => 'blue'],
            'status' => 'good',
            'test' => 'rediscache_filesystem',
        ]);
    }
}
