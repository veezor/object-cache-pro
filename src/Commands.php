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

namespace RedisCachePro;

use Exception;

use WP_CLI;
use WP_CLI_Command;

use RedisCachePro\Diagnostics\Diagnostics;
use RedisCachePro\Configuration\Configuration;

/**
 * Enables, disabled, updates, and checks the status of the Redis object cache.
 */
class Commands extends WP_CLI_Command
{
    /**
     * Enables the Redis object cache.
     *
     * Copies the the object cache drop-in into the content directory.
     * Will not overwrite existing files, unless the --force option is used.
     *
     * ## OPTIONS
     *
     * [--force]
     * : Overwrite existing files.
     *
     * [--skip-flush-notice]
     * : Omit the cache flush notice.
     *
     * ## EXAMPLES
     *
     *     # Enable the Redis object cache.
     *     $ wp redis enable
     *
     *     # Enable the Redis object cache and overwrite existing drop-in.
     *     $ wp redis enable --force
     *
     * @alias activate
     */
    public function enable($arguments, $options)
    {
        global $wp_filesystem;

        if (! \WP_Filesystem()) {
            WP_CLI::error('Could not gain filesystem access.');

            return;
        }

        $force = isset($options['force']);

        $dropin = \WP_CONTENT_DIR . '/object-cache.php';
        $stub = realpath(__DIR__ . '/../stubs/object-cache.php');

        if (! $force && $wp_filesystem->exists($dropin)) {
            WP_CLI::error(WP_CLI::colorize(
                'A object cache drop-in already exists. Run `%ywp redis enable --force%n` to overwrite it.'
            ));

            return;
        }

        if (! $wp_filesystem->copy($stub, $dropin, $force, \FS_CHMOD_FILE)) {
            WP_CLI::error('Redis object cache could not be enabled.');

            return;
        }

        if (function_exists('wp_opcache_invalidate')) {
            wp_opcache_invalidate($dropin, true);
        }

        WP_CLI::success('Redis object cache enabled.');

        if (! isset($options['skip-flush-notice'])) {
            WP_CLI::line(WP_CLI::colorize(
                'To avoid outdated data, flush the object cache by calling `%ywp cache flush%n`.'
            ));
        }
    }

    /**
     * Disables the Redis object cache.
     *
     * ## EXAMPLES
     *
     *     # Disable the Redis object cache.
     *     $ wp redis disable
     *
     * @alias deactivate
     */
    public function disable($arguments, $options)
    {
        global $wp_filesystem;

        if (! \WP_Filesystem()) {
            WP_CLI::error('Could not gain filesystem access.');

            return;
        }

        $dropin = \WP_CONTENT_DIR . '/object-cache.php';

        if (! $wp_filesystem->exists($dropin)) {
            WP_CLI::log('No object cache drop-in found.');

            return;
        }

        if (! $wp_filesystem->delete($dropin)) {
            WP_CLI::error('Redis object cache could not be disabled.');

            return;
        }

        WP_CLI::success('Redis object cache disabled.');
    }

    /**
     * Shows Redis object cache status and diagnostic information.
     *
     * ## EXAMPLES
     *
     *     # Show Redis object cache information.
     *     $ wp redis info
     *
     * @alias status
     */
    public function info()
    {
        global $wp_object_cache;

        $diagnostics = (new Diagnostics($wp_object_cache))->withFilesystemAccess();

        foreach ($diagnostics->toArray() as $groupName => $group) {
            if (empty($group)) {
                continue;
            }

            WP_CLI::log(WP_CLI::colorize(
                sprintf('%%b[%s]%%n', ucfirst($groupName))
            ));

            foreach ($group as $key => $diagnostic) {
                if ($groupName === Diagnostics::ERRORS) {
                    WP_CLI::log(WP_CLI::colorize("%r{$diagnostic}%n"));
                } else {
                    $value = WP_CLI::colorize($diagnostic->withComment()->cli);

                    WP_CLI::log("{$diagnostic->name}: {$value}");
                }
            }

            WP_CLI::log('');
        }
    }

    /**
     * Flushes the Redis object cache.
     *
     * Flushing the object cache will flush the cache for all sites.
     * Beware of the performance impact when flushing the object cache in production,
     * when not using asynchronous flushing.
     *
     * Errors if the object cache can't be flushed.
     *
     * ## OPTIONS
     *
     * [<id>...]
     * : One or more IDs of sites to flush.
     *
     * [--async]
     * : Force asynchronous flush.
     *
     * ## EXAMPLES
     *
     *     # Flush the entire cache.
     *     $ wp redis flush
     *     Success: The object cache was flushed.
     *
     *     # Flush multiple sites (networks only).
     *     $ wp redis flush 42 1337
     *     Success: The object cache of the site at 'https://example.org' was flushed.
     *     Success: The object cache of the site at 'https://help.example.org' was flushed.
     *
     *     # Flush site by URL (networks only).
     *     $ wp redis flush --url="https://example.org"
     *     Success: The object cache of the site at 'https://example.org' was flushed.
     *
     * @alias clear
     */
    public function flush($arguments, $options)
    {
        global $wp_object_cache;

        $diagnostics = new Diagnostics($wp_object_cache);

        if (! $diagnostics->dropinExists()) {
            WP_CLI::error(WP_CLI::colorize(
                'No object cache drop-in found. Run `%ywp redis enable%n` to enable the object cache.'
            ));

            return;
        }

        if (! $diagnostics->dropinIsValid()) {
            WP_CLI::error(WP_CLI::colorize(
                'The object cache drop-in is invalid. Run `%ywp redis enable --force%n` to enable the object cache.'
            ));

            return;
        }

        // unset site ids when environment is not a multisite
        if (! is_multisite()) {
            $arguments = [];
        }

        // flush cache of site set via `--url` option
        if (is_multisite() && empty($arguments) && get_current_blog_id() !== get_main_site_id()) {
            $arguments = [get_current_blog_id()];
        }

        if (empty($arguments)) {
            try {
                $result = $wp_object_cache->connection()->flushdb(isset($options['async']));
            } catch (Exception $exception) {
                $result = false;
            }

            $result
                ? WP_CLI::success('The object cache was flushed.')
                : WP_CLI::error('The object cache could not be flushed.');
        } else {
            foreach ($arguments as $siteId) {
                $url = get_site_url($siteId);
                $result = $wp_object_cache->flushBlog($siteId);

                $result
                    ? WP_CLI::success(WP_CLI::colorize(
                        "The object cache of the site at '%y{$url}%n' was flushed."
                    ))
                    : WP_CLI::error(WP_CLI::colorize(
                        "The object cache of the site at '%y{$url}%n' could not be flushed."
                    ));
            }
        }
    }

    /**
     * Launch redis-cli using WordPress Redis configuration.
     *
     * ## EXAMPLES
     *
     *     # Launch redis-cli.
     *     $ wp redis cli
     *     127.0.0.1:6379> ping
     *     PONG
     *
     * @alias shell
     */
    public function cli()
    {
        if (! defined('\WP_REDIS_CONFIG')) {
            WP_CLI::error(WP_CLI::colorize(
                'The %yWP_REDIS_CONFIG%n constant has not been defined.'
            ));

            return;
        }

        $cliVersion = shell_exec('redis-cli -v');

        if ($cliVersion && preg_match('/\d+\.\d+\.\d+/', $cliVersion, $matches)) {
            $cliVersion = $matches[0];
        } else {
            WP_CLI::warning('Could not detect `redis-cli` version.');

            $cliVersion = '';
        }

        $config = Configuration::from(\WP_REDIS_CONFIG);

        $scheme = strtoupper($config->scheme);
        $host = $config->host ?? '127.0.0.1';
        $port = $config->port ?? 6379;
        $database = $config->database ?? 0;
        $username = $config->username;
        $password = $config->password;

        $auth = 'no password';

        $command = 'redis-cli -n %d';
        $arguments = [$database];

        $arguments[] = $host;

        if ($config->scheme === 'unix') {
            $command .= ' -s %s';
            $server = "%y{$host}%n";
        } else {
            $command .= ' -h %s -p %s';
            $arguments[] = $port;
            $server = "%y{$host}%n:%y{$port}%n";
        }

        if ($password) {
            $command .= ' -a %s';
            $arguments[] = $password;
            $auth = 'with password';
        }

        if ($username) {
            $command .= ' --user %s';
            $arguments[] = $username;
            $auth = "as %y{$username}%n";
        }

        if ($config->scheme === 'tls') {
            $command .= ' --tls';
        }

        // The `--no-auth-warning` option was added in Redis 4.0
        if (($username || $password) && version_compare($cliVersion, '4.0', '>=')) {
            $command .= ' --no-auth-warning';
        }

        WP_CLI::log(WP_CLI::colorize(
            "Connecting via {$scheme} to {$server} ({$auth}) using database %y{$database}%n."
        ));

        $command = \WP_CLI\Utils\esc_cmd($command, ...$arguments);
        $process = \WP_CLI\Utils\proc_open_compat($command, [STDIN, STDOUT, STDERR], $pipes);

        exit(proc_close($process));
    }
}
