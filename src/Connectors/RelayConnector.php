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

namespace RedisCachePro\Connectors;

use Relay\Relay;
use RuntimeException;

use RedisCachePro\Configuration\Configuration;

use RedisCachePro\Connections\RelayConnection;
use RedisCachePro\Connections\ConnectionInterface;

use RedisCachePro\Exceptions\RelayMissingException;

class RelayConnector implements Connector
{
    /**
     * Ensure the Relay extension is loaded.
     */
    public static function boot(): void
    {
        if (! extension_loaded('relay')) {
            throw new RelayMissingException;
        }
    }

    /**
     * Create a new Relay connection.
     *
     * @param  \RedisCachePro\Configuration\Configuration  $config
     * @return \RedisCachePro\Connections\ConnectionInterface
     */
    public static function connect(Configuration $config): ConnectionInterface
    {
        if ($config->cluster) {
            return static::connectToCluster($config);
        }

        if ($config->servers) {
            return static::connectToReplicatedServers($config);
        }

        return static::connectToInstance($config);
    }

    /**
     * Create a new PhpRedis connection to an instance.
     *
     * @param  \RedisCachePro\Configuration\Configuration  $config
     * @return \RedisCachePro\Connections\PhpRedisConnection
     */
    public static function connectToInstance(Configuration $config): ConnectionInterface
    {
        $client = new Relay;
        $version = \phpversion('relay');

        $persistent = $config->persistent;
        $persistentId = \is_string($persistent) ? $persistent : '';

        $host = $config->host;

        if ($config->scheme) {
            $host = "{$config->scheme}://{$config->host}";
        }

        $host = \str_replace('unix://', '', $host);

        $parameters = [
            $host,
            $config->port ?? 0,
            $config->timeout,
            $persistentId,
            $config->retry_interval,
            $config->read_timeout,
        ];

        if ($config->tls_options) {
            $parameters[] = ['stream' => $config->tls_options];
        }

        $method = $persistent ? 'pconnect' : 'connect';

        $client->{$method}(...$parameters);

        if ($config->username && $config->password) {
            $client->auth([$config->username, $config->password]);
        } elseif ($config->password) {
            $client->auth($config->password);
        }

        if ($config->database) {
            $client->select($config->database);
        }

        if ($config->read_timeout) {
            $client->setOption(Relay::OPT_READ_TIMEOUT, (string) $config->read_timeout);
        }

        return new RelayConnection($client, $config);
    }

    /**
     * Create a new clustered Relay connection.
     *
     * @param  \RedisCachePro\Configuration\Configuration  $config
     *
     * @throws \RuntimeException
     */
    public static function connectToCluster(Configuration $config): ConnectionInterface
    {
        throw new RuntimeException('Relay does not support clusters.');
    }

    /**
     * Create a new replicated Relay connection.
     *
     * @param  \RedisCachePro\Configuration\Configuration  $config
     *
     * @throws \RuntimeException
     */
    public static function connectToReplicatedServers(Configuration $config): ConnectionInterface
    {
        throw new RuntimeException('Relay does not support replicated connections.');
    }
}
