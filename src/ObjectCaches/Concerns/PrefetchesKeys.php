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

namespace RedisCachePro\ObjectCaches\Concerns;

use Exception;
use __PHP_Incomplete_Class;

/**
 * When the `prefetch` configuration option is enabled, all persistent keys are stored in
 * a hash of the current HTTP request and retrieved in batches of cache groups early on.
 */
trait PrefetchesKeys
{
    /**
     * Holds the prefetchable keys.
     *
     * @var array
     */
    protected $prefetch = [];

    /**
     * The amount of prefetched keys.
     *
     * @var int
     */
    protected $prefetches = 0;

    /**
     * Whether prefetching occurred.
     *
     * @var bool
     */
    protected $prefetched = false;

    /**
     * Holds the cache group name for prefetches.
     *
     * @var string
     */
    protected $prefetchGroup = 'ocp-prefetches';

    /**
     * If prefetching is enabled and the current HTTP request is prefetchable,
     * retrieve prefetchable keys in batch of cache groups and register
     * a shutdown handler to keep the prefetches current.
     */
    public function prefetch()
    {
        if ($this->prefetched || ! $this->shouldPrefetch()) {
            return;
        }

        \register_shutdown_function(function () {
            $this->storePrefetches();
        });

        $prefetch = $this->get($this->prefetchRequestHash(), $this->prefetchGroup, true);

        if (! empty($prefetch)) {
            foreach ($prefetch as $group => $keys) {
                if ($this->isNonPrefetchableGroup((string) $group)) {
                    continue;
                }

                if (\count($keys) > 1) {
                    $this->ensurePrefetchability(
                        $this->get_multiple($keys, (string) $group, true), $group
                    );
                }
            }
        }

        $this->prefetched = true;
    }

    /**
     * Ensure the prefetched items are not incomplete PHP classes.
     *
     * @param  array  $items
     * @param  string  $group
     */
    protected function ensurePrefetchability($items, $group)
    {
        foreach ((array) $items as $key => $value) {
            if ($value instanceof __PHP_Incomplete_Class) {
                $this->undoPrefetch((string) $key, $group);

                continue;
            }

            if (is_array($value) || is_object($value)) {
                array_walk_recursive($value, function ($item) use ($key, $group) {
                    if ($item instanceof __PHP_Incomplete_Class) {
                        $this->undoPrefetch((string) $key, $group);
                    }
                });
            }
        }
    }

    /**
     * Remove the prefetched key from memory and log error.
     *
     * @param  string  $key
     * @param  string  $group
     */
    protected function undoPrefetch(string $key, string $group)
    {
        $this->deleteFromMemory($key, $group);
        $this->prefetches--;

        \error_log(
            "objectcache.warning: The key `{$key}` is incompatible with prefetching" .
            " and the group `{$group}` should be added to the non-prefetchable groups." .
            ' For more information see: https://objectcache.pro/docs/configuration-options/#non-prefetchable-groups'
        );
    }

    /**
     * Store the prefetches for the current HTTP request.
     */
    protected function storePrefetches()
    {
        if (! $this->shouldPrefetch()) {
            return;
        }

        $prefetch = \array_map(function ($group) {
            return \array_keys($group);
        }, $this->prefetch);

        $prefetch = \array_filter($prefetch, function ($group) {
            return $this->isPrefetchableGroup((string) $group);
        }, \ARRAY_FILTER_USE_KEY);

        // don't prefetch `alloptions` when using hashes
        if ($this->config->split_alloptions) {
            foreach ($prefetch['options'] ?? [] as $i => $key) {
                if (\strpos($key, 'alloptions') !== false) {
                    unset($prefetch['options'][$i]);
                }
            }
        }

        if (! empty($prefetch)) {
            $this->set($this->prefetchRequestHash(), $prefetch, $this->prefetchGroup);
        }
    }

    /**
     * Deletes all stored prefetches.
     *
     * @return bool
     */
    public function deletePrefetches()
    {
        $this->prefetch = [];

        $script = file_get_contents(__DIR__ . '/../scripts/chunked-scan.lua');
        $command = $this->config->async_flush ? 'UNLINK' : 'DEL';

        try {
            $this->connection->eval($script, ['*ocp-prefetches:*', $command], 1);
        } catch (Exception $exception) {
            $this->error($exception);

            return false;
        }

        return true;
    }

    /**
     * Determines whether the current request should use prefetching.
     *
     * @return bool
     */
    protected function shouldPrefetch(): bool
    {
        return $this->config->prefetch
            && $this->requestIsPrefetchable();
    }

    /**
     * Determines whether the current HTTP request is prefetchable.
     *
     * @return bool
     */
    protected function requestIsPrefetchable(): bool
    {
        if (
            (defined('WP_CLI') && WP_CLI) ||
            (defined('REST_REQUEST') && REST_REQUEST) ||
            (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)
        ) {
            return false;
        }

        if (empty($_SERVER['REQUEST_URI'] ?? null)) {
            return false;
        }

        if (! \in_array($_SERVER['REQUEST_METHOD'] ?? null, ['GET', 'HEAD'])) {
            return false;
        }

        return true;
    }

    /**
     * Generates a prefetch identifier for the current HTTP request.
     *
     * @return string
     */
    protected function prefetchRequestHash(): string
    {
        static $key = null;

        if ($key) {
            return $key;
        }

        $components = [
            'method' => $_SERVER['REQUEST_METHOD'],
            'scheme' => $_SERVER['HTTPS']
                ?? $_SERVER['SERVER_PORT']
                ?? $_SERVER['HTTP_X_FORWARDED_PROTO']
                ?? 'http',
            'host' => $_SERVER['HTTP_X_FORWARDED_HOST']
                ?? $_SERVER['HTTP_HOST']
                ?? $_SERVER['SERVER_NAME']
                ?? 'localhost',
            'path' => \urldecode($_SERVER['REQUEST_URI']),
            'query' => \urldecode($_SERVER['QUERY_STRING'] ?? ''),
        ];

        return $key = \md5(\serialize($components));
    }
}
