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

namespace RedisCachePro\Connections;

use Throwable;

use RedisCachePro\Exceptions\ConnectionException;

abstract class Connection
{
    /**
     * The configuration instance.
     *
     * @var \RedisCachePro\Configuration\Configuration
     */
    protected $config;

    /**
     * The logger instance.
     *
     * @var \RedisCachePro\Loggers\Logger
     */
    protected $log;

    /**
     * Run a command against Redis.
     *
     * @param  string  $command
     * @param  array  $parameters
     * @return mixed
     */
    public function command(string $name, array $parameters = [])
    {
        $context = [
            'command' => $name,
            'parameters' => $parameters,
        ];

        if ($this->config->debug || $this->config->save_commands) {
            $context['backtrace'] = \debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

            if (function_exists('wp_debug_backtrace_summary')) {
                $context['backtrace_summary'] = \wp_debug_backtrace_summary(__CLASS__);
            }
        }

        try {
            $start = $this->now();

            $result = $this->client->{$name}(...$parameters);

            $time = \round(($this->now() - $start) * 1000, 4);
        } catch (Throwable $exception) {
            $this->log->error("Failed to execute Redis `{$name}` command", $context + [
                'exception' => $exception,
            ]);

            throw ConnectionException::from($exception);
        }

        $arguments = \implode(' ', \array_map('json_encode', $parameters));
        $command = trim("{$name} {$arguments}");

        $this->log->info("Executed Redis command `{$command}` in {$time}ms", $context + [
            'result' => $result,
            'time' => $time,
        ]);

        return $result;
    }

    /**
     * Execute the callback without data mutations on the connection,
     * such as serialization and compression algorithms.
     *
     * @param  callable  $callback
     * @return mixed
     */
    public function withoutMutations(callable $callback)
    {
        return $callback($this);
    }

    /**
     * Returns the system's current time in microseconds.
     * Will use high resolution time when available.
     *
     * @return float
     */
    protected function now()
    {
        static $supportsHRTime;

        if (is_null($supportsHRTime)) {
            $supportsHRTime = \function_exists('hrtime');
        }

        return $supportsHRTime ? \hrtime(true) * 1e-9 : \microtime(true);
    }

    /**
     * Pass other method calls down to the underlying client.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->command($method, $parameters);
    }
}
