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

/**
 * When the `split_alloptions` configuration option is enabled, the `alloptions` cache key is stored
 * in a Redis hash, instead of a single key. For some setups this helps to reduce data transfer
 * and will minimize race conditions when several processes update options simultaneously.
 */
trait SplitsAllOptions
{
    /**
     * Returns `true` when `alloptions` splitting is enabled
     * and the given `$id` is the `alloptions` cache key.
     *
     * @param  string  $id
     * @return bool
     */
    protected function isAllOptionsId(string $id): bool
    {
        if (! $this->config->split_alloptions) {
            return false;
        }

        return $id === $this->id('alloptions', 'options');
    }

    /**
     * Returns a single `alloptions` array from the Redis hash.
     *
     * @param  string  $id
     * @return array|false
     */
    protected function getAllOptions(string $id)
    {
        $alloptions = $this->connection->hgetall("{$id}:hash");

        return empty($alloptions) ? false : $alloptions;
    }

    /**
     * Keeps the `alloptions` Redis hash in sync.
     *
     * 1. All keys present in memory, but not in given data, will be deleted
     * 2. All keys present in data, but not in memory, or with a different value will be set
     * 3. When given `$expire`, sets the hash's time-to-live in seconds
     *
     * @param  string  $id
     * @param  mixed  $data
     * @param  int  $expire
     * @return bool
     */
    protected function syncAllOptions(string $id, $data, int $expire): bool
    {
        $runtimeCache = $this->hasInMemory($id, 'options')
            ? $this->getFromMemory($id, 'options')
            : [];

        $removedOptions = array_keys(array_diff_key($runtimeCache, $data));

        if (! empty($removedOptions)) {
            $this->connection->hdel("{$id}:hash", ...$removedOptions);
        }

        $changedOptions = array_diff_assoc($data, $runtimeCache);

        if (! empty($changedOptions)) {
            $this->connection->hmset("{$id}:hash", $changedOptions);
        }

        if ($expire) {
            $this->connection->expire("{$id}:hash", $expire);
        }

        return true;
    }

    /**
     * Deletes the `alloptions` hash.
     *
     * @param  string  $id
     * @return bool
     */
    protected function deleteAllOptions(string $id): bool
    {
        return (bool) $this->connection->del("{$id}:hash");
    }
}
