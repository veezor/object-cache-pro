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

namespace RedisCachePro\Diagnostics;

use ArrayAccess;
use RuntimeException;

use WP_Error;

use RedisCachePro\Configuration\Configuration;
use RedisCachePro\ObjectCaches\ObjectCacheInterface;

class Diagnostics implements ArrayAccess
{
    /**
     * Diagnose group: Configuration values.
     *
     * @var string
     */
    const CONFIG = 'config';

    /**
     * Diagnose group: Constants.
     *
     * @var string
     */
    const CONSTANTS = 'constants';

    /**
     * Diagnose group: Errors.
     *
     * @var string
     */
    const ERRORS = 'errors';

    /**
     * Diagnose group: General information.
     *
     * @var string
     */
    const GENERAL = 'general';

    /**
     * Diagnose group: Version numbers.
     *
     * @var string
     */
    const VERSIONS = 'versions';

    /**
     * Diagnose group: Statistics.
     *
     * @var string
     */
    const STATISTICS = 'statistics';

    /**
     * The object cache instance.
     *
     * @var \RedisCachePro\ObjectCaches\ObjectCacheInterface
     */
    protected $cache;

    /**
     * The configuration instance.
     *
     * @var \RedisCachePro\Configuration\Configuration
     */
    protected $config;

    /**
     * The connection instance.
     *
     * @var \RedisCachePro\Connections\ConnectionInterface
     */
    protected $connection;

    /**
     * Holds the Redis server info.
     *
     * @var array
     */
    protected $info;

    /**
     * Holds the diagnostics groups and their data.
     *
     * @var array
     */
    protected $data = [];

    /**
     * Create a new diagnostics instance.
     *
     * @param  \RedisCachePro\ObjectCaches\ObjectCacheInterface  $cache
     */
    public function __construct($cache)
    {
        if (is_object($cache) && in_array(ObjectCacheInterface::class, (array) class_implements($cache))) {
            $this->cache = $cache;
            $this->config = $this->cache->config();
            $this->connection = $this->cache->connection();
        }

        if (! $this->config && defined('\WP_REDIS_CONFIG')) {
            $this->config = Configuration::safelyFrom(\WP_REDIS_CONFIG);
        }

        $this->gatherGeneral();
        $this->gatherVersions();
        $this->gatherStatistics();
        $this->gatherConfiguration();
        $this->gatherConstants();
        $this->gatherErrors();
    }

    /**
     * Gathers general information, such as the used object cache class,
     * the connection status and eviction policy.
     *
     * @return void
     */
    protected function gatherGeneral()
    {
        $this->data[self::GENERAL]['status'] = $this->status();

        $this->data[self::GENERAL]['dropin'] = $this->dropin();

        if ($evictionPolicy = $this->evictionPolicy()) {
            $this->data[self::GENERAL]['eviction-policy'] = $evictionPolicy;
        }

        if ($this->cache) {
            $this->data[self::GENERAL]['multisite'] = Diagnostic::name('Multisite')->value(
                $this->cache->isMultisite() ? 'Yes' : 'No'
            );

            $this->data[self::GENERAL]['global-groups'] = Diagnostic::name('Global Groups')->values(
                $this->cache->globalGroups()
            );

            $this->data[self::GENERAL]['non-persistent-groups'] = Diagnostic::name('Non-persistent Groups')->values(
                $this->cache->nonPersistentGroups()
            );

            $this->data[self::GENERAL]['non-prefetchable-groups'] = Diagnostic::name('Non-prefetchable Groups')->values(
                $this->cache->nonPrefetchableGroups()
            );
        }

        $this->data[self::GENERAL]['compressions'] = $this->compressions();

        if ($this->isDisabledUsingEnvVar()) {
            $this->data[self::GENERAL]['disabled'] = Diagnostic::name('Disabled')
                ->error('Using WP_REDIS_DISABLED environment variable');
        }

        if ($this->isDisabledUsingConstant()) {
            $this->data[self::GENERAL]['disabled'] = Diagnostic::name('Disabled')
                ->error('Using WP_REDIS_DISABLED constant');
        }
    }

    /**
     * Gathers version numbers for PHP, extensions, Redis and the drop-in.
     *
     * @return void
     */
    protected function gatherVersions()
    {
        $this->data[self::VERSIONS]['php'] = $this->phpVersion();
        $this->data[self::VERSIONS]['igbinary'] = $this->igbinary();
        $this->data[self::VERSIONS]['phpredis'] = $this->phpredis();
        $this->data[self::VERSIONS]['relay'] = $this->relay();

        if ($redisVersion = $this->redisVersion()) {
            $this->data[self::VERSIONS]['redis'] = $redisVersion;
        }

        if ($pluginVersion = $this->pluginVersion()) {
            $this->data[self::VERSIONS]['plugin'] = $pluginVersion;
        }

        if ($dropinVersion = $this->dropinVersion()) {
            $this->data[self::VERSIONS]['dropin'] = $dropinVersion;
        }
    }

    /**
     * Gathers memory and usage statistics.
     *
     * @return void
     */
    protected function gatherStatistics()
    {
        $diagnostic = Diagnostic::name('Memory');
        $usedMemory = $this->usedMemory();

        if ($usedMemory) {
            $maxMemory = $this->maxMemory();

            $memory = $maxMemory
                ? sprintf('%s of %s', size_format($usedMemory), size_format($maxMemory))
                : size_format($usedMemory, 2);

            $diagnostic->value($memory);
        }

        $this->data[self::STATISTICS]['memory'] = $diagnostic;
    }

    /**
     * Gathers configuration values from the config instance.
     *
     * @return void
     */
    protected function gatherConfiguration()
    {
        if ($this->config) {
            foreach ($this->config->diagnostics() as $option => $value) {
                $name = ucwords(str_replace('_', ' ', $option));
                $this->data[self::CONFIG][$option] = Diagnostic::name($name)->value($value);
            }
        }
    }

    /**
     * Gathers relevant constants.
     *
     * @return void
     */
    protected function gatherConstants()
    {
        $constants = [
            'WP_DEBUG',
            'SAVEQUERIES',
            'WP_REDIS_DIR',
            'WP_REDIS_DISABLED',
            'WP_REDIS_CONFIG',
        ];

        foreach ($constants as $constant) {
            $diagnostic = Diagnostic::name($constant);

            if (defined($constant)) {
                $value = constant($constant);

                if (is_string($value)) {
                    $diagnostic->value($value);
                } elseif (is_array($value)) {
                    $diagnostic->prettyJson($value);
                } else {
                    $diagnostic->json($value);
                }
            } else {
                $diagnostic->value('undefined');
            }

            $this->data[self::CONSTANTS][$constant] = $diagnostic;
        }
    }

    /**
     * Gathers all occurred errors.
     *
     * @return void
     */
    protected function gatherErrors()
    {
        global $wp_object_cache_errors;

        if ($this->config->initException ?? false) {
            $this->data[self::ERRORS][] = sprintf(
                'The configuration could not be instantiated: %s',
                $this->config->initException->getMessage()
            );
        }

        if (empty($wp_object_cache_errors)) {
            return;
        }

        foreach ($wp_object_cache_errors as $error) {
            $this->data[self::ERRORS][] = $error;
        }
    }

    /**
     * Append filesystem access diagnostic to general group.
     *
     * @return self
     */
    public function withFilesystemAccess()
    {
        $fs = $this->filesystemAccess();

        $diagnostic = Diagnostic::name('Filesystem');

        if (is_wp_error($fs)) {
            $diagnostic->error($fs->get_error_message());
        } else {
            $diagnostic->value('Accessible');
        }

        $this->data[self::GENERAL]['filesystem'] = $diagnostic;

        return $this;
    }

    /**
     * Return the object cache drop-in status.
     *
     * @return \RedisCachePro\Diagnostic
     */
    protected function dropin()
    {
        $diagnostic = Diagnostic::name('Drop-in');

        if (! $this->dropinExists()) {
            return $diagnostic->error('Not enabled');
        }

        if (! $this->dropinIsValid()) {
            return $diagnostic->error('Invalid');
        }

        if (! $this->dropinIsUpToDate()) {
            return $diagnostic->warning('Outdated');
        }

        return $diagnostic->value('Valid');
    }

    /**
     * Returns the drop-in version, if present.
     *
     * @return \RedisCachePro\Diagnostic
     */
    protected function dropinVersion()
    {
        $diagnostic = Diagnostic::name('Drop-in');

        if (! $this->dropinExists()) {
            return $diagnostic;
        }

        $dropin = \get_plugin_data(
            WP_CONTENT_DIR . '/object-cache.php',
            false,
            false
        );

        $stub = \get_plugin_data(
            realpath(__DIR__ . '/../../stubs/object-cache.php'),
            false,
            false
        );

        if ($dropin['Version'] !== $stub['Version']) {
            return $diagnostic->error($dropin['Version'])->comment('Outdated');
        }

        return $diagnostic->value($dropin['Version']);
    }

    /**
     * Returns Redis' eviction policy, if a connection is established.
     *
     * @return \RedisCachePro\Diagnostic
     */
    protected function evictionPolicy()
    {
        $policy = $this->maxMemoryPolicy();
        $diagnostic = Diagnostic::name('Eviction Policy');

        if ($policy === 'noeviction' && ! $this->config->maxttl) {
            return $diagnostic->error($policy);
        }

        return $diagnostic->value($policy);
    }

    /**
     * Returns the used memory, if a connection is established.
     *
     * @return string|null
     */
    public function usedMemory()
    {
        return $this->redisInfo('used_memory');
    }

    /**
     * Returns the max memory, if a connection is established.
     *
     * @return string|null
     */
    public function maxMemory()
    {
        return $this->redisInfo('maxmemory');
    }

    /**
     * Return the `maxmemory_policy` from Redis.
     *
     * @return string|null
     */
    public function maxMemoryPolicy()
    {
        return $this->redisInfo('maxmemory_policy');
    }

    /**
     * Returns details about the igbinary extension.
     *
     * @return \RedisCachePro\Diagnostic
     */
    protected function igbinary()
    {
        $diagnostic = Diagnostic::name('igbinary');

        if (! extension_loaded('igbinary')) {
            return $diagnostic->error('Not installed');
        }

        $version = phpversion('igbinary');

        if (! defined('\Redis::SERIALIZER_IGBINARY')) {
            return $diagnostic->value($version)->comment('No PhpRedis support');
        }

        return $diagnostic->value($version);
    }

    /**
     * Returns details about the PhpRedis extension.
     *
     * @return \RedisCachePro\Diagnostic
     */
    protected function phpredis()
    {
        $diagnostic = Diagnostic::name('PhpRedis');

        if (! extension_loaded('redis')) {
            return $diagnostic->error('Not installed');
        }

        $version = phpversion('redis');

        if (version_compare($version, '3.1.1', '<')) {
            return $diagnostic->error($version)->comment('Unsupported');
        }

        return $diagnostic->value($version);
    }

    /**
     * Returns details about the Relay extension.
     *
     * @return \RedisCachePro\Diagnostic
     */
    protected function relay()
    {
        $diagnostic = Diagnostic::name('Relay');

        if (! extension_loaded('relay')) {
            return $diagnostic->error('Not installed');
        }

        $version = phpversion('relay');

        return $diagnostic->value($version);
    }

    /**
     * Returns details about the PHP version.
     *
     * @return \RedisCachePro\Diagnostic
     */
    protected function phpVersion()
    {
        $diagnostic = Diagnostic::name('PHP');

        $version = phpversion();

        if (version_compare($version, '7.4', '<')) {
            return $diagnostic->warning($version)->comment('Outdated');
        }

        return $diagnostic->value($version);
    }

    /**
     * Returns whether Redis responds to a PING command.
     *
     * @return bool
     */
    public function ping()
    {
        if ($this->connection) {
            return (bool) $this->cache->connection()->ping();
        }

        return false;
    }

    /**
     * Returns the status of the Redis connection.
     *
     * @return \RedisCachePro\Diagnostic
     */
    protected function status()
    {
        $diagnostic = Diagnostic::name('Status');

        if ($this->isDisabled()) {
            return $diagnostic->warning('Disabled');
        }

        return $this->ping()
            ? $diagnostic->success('Connected')
            : $diagnostic->error('Not connected');
    }

    /**
     * Returns a list of supported compression algorithms.
     *
     * @return \RedisCachePro\Diagnostic
     */
    protected function compressions()
    {
        $algorithms = array_filter([
            defined('\Redis::COMPRESSION_LZF') ? 'LZF' : null,
            defined('\Redis::COMPRESSION_LZ4') ? 'LZ4' : null,
            defined('\Redis::COMPRESSION_ZSTD') ? 'Zstd' : null,
        ]);

        $diagnostic = Diagnostic::name('Supported compressions');

        return empty($algorithms)
            ? $diagnostic->value('None')
            : $diagnostic->values($algorithms);
    }

    /**
     * Returns the plugin version, if installed.
     *
     * @return \RedisCachePro\Diagnostic
     */
    protected function pluginVersion()
    {
        $plugin = \get_plugin_data(
            realpath(__DIR__ . '/../../redis-cache-pro.php'),
            false,
            false
        );

        return Diagnostic::name('Plugin')->value(
            $plugin['Version'] ?? null
        );
    }

    /**
     * Returns the Redis server version, if a connection is established.
     *
     * @return \RedisCachePro\Diagnostic
     */
    public function redisVersion()
    {
        return Diagnostic::name('Redis')->value(
            $this->redisInfo('redis_version')
        );
    }

    /**
     * Returns information and statistics about the Redis server.
     *
     * @return array|null
     */
    protected function redisInfo($name = null)
    {
        if (! $this->info && $this->connection) {
            $this->info = $this->cache->connection()->info();
        }

        if ($name && $this->info) {
            return $this->info[$name] ?? null;
        }

        return $this->info;
    }

    /**
     * Whether the object cache is disabled.
     *
     * @return bool
     */
    public function isDisabled()
    {
        return $this->isDisabledUsingEnvVar()
            || $this->isDisabledUsingConstant();
    }

    /**
     * Whether the object cache is disabled using the `WP_REDIS_DISABLED` constant.
     *
     * @return bool
     */
    public function isDisabledUsingConstant()
    {
        return defined('\WP_REDIS_DISABLED') && \WP_REDIS_DISABLED;
    }

    /**
     * Whether the object cache is disabled using the `WP_REDIS_DISABLED` environment variable.
     *
     * @return bool
     */
    public function isDisabledUsingEnvVar()
    {
        return ! empty(getenv('WP_REDIS_DISABLED'));
    }

    /**
     * Whether the object cache drop-in file exists.
     *
     * @return bool
     */
    public function dropinExists()
    {
        global $wp_filesystem;

        if (! \WP_Filesystem()) {
            return file_exists(WP_CONTENT_DIR . '/object-cache.php');
        }

        return $wp_filesystem->exists(WP_CONTENT_DIR . '/object-cache.php');
    }

    /**
     * Whether the object cache drop-in is valid.
     *
     * @return bool
     */
    public function dropinIsValid()
    {
        $plugin = \get_plugin_data(
            realpath(__DIR__ . '/../../redis-cache-pro.php'),
            false,
            false
        );

        $dropin = \get_plugin_data(
            WP_CONTENT_DIR . '/object-cache.php',
            false,
            false
        );

        /*
         * Filter the drop-in validation result.
         *
         * @param  bool  $is_valid  Whether the drop-in is valid.
         * @param  array  $dropin  The drop-in metadata.
         * @param  array  $plugin  The plugin metadata.
         */
        return \apply_filters(
            'rediscache_validate_dropin',
            $dropin['PluginURI'] === $plugin['PluginURI'] ||
            $dropin['PluginURI'] === 'https://wprediscache.com',
            $dropin,
            $plugin
        );
    }

    /**
     * Whether the object cache drop-in is up-to-date.
     *
     * @return bool
     */
    public function dropinIsUpToDate()
    {
        $plugin = \get_plugin_data(
            realpath(__DIR__ . '/../../redis-cache-pro.php'),
            false,
            false
        );

        $dropin = \get_plugin_data(
            WP_CONTENT_DIR . '/object-cache.php',
            false,
            false
        );

        /*
         * Filter the drop-in version check result.
         *
         * @param  bool  $is_uptodate  Whether the drop-in is up-to-date.
         * @param  array  $dropin  The drop-in metadata.
         * @param  array  $plugin  The plugin metadata.
         */
        return \apply_filters(
            'rediscache_validate_dropin_version',
            version_compare($dropin['Version'], $plugin['Version'], '>='),
            $dropin,
            $plugin
        );
    }

    /**
     * Test whether the filesystem access can be obtained and is working.
     *
     * @return WP_Error|true
     */
    public function filesystemAccess()
    {
        global $wp_filesystem;

        if (! WP_Filesystem()) {
            return new WP_Error('fs', 'Failed to obtain filesystem access.');
        }

        $stub = realpath(__DIR__ . '/../../stubs/object-cache.php');
        $temp = WP_CONTENT_DIR . '/.object-cache-test.tmp';

        if (! $wp_filesystem->exists($stub)) {
            return new WP_Error('fs', 'Stub file doesn’t exist.');
        }

        if (! $wp_filesystem->is_writable(WP_CONTENT_DIR)) {
            return new WP_Error('fs', 'Unable to write to content directory.');
        }

        if ($wp_filesystem->exists($temp)) {
            if (! $wp_filesystem->delete($temp)) {
                return new WP_Error('fs', 'Unable to delete existing test file.');
            }
        }

        if (! $wp_filesystem->copy($stub, $temp, true, FS_CHMOD_FILE)) {
            return new WP_Error('fs', 'Failed to copy test file.');
        }

        if (! $wp_filesystem->exists($temp)) {
            return new WP_Error('fs', 'Unable to verify existence of copied test file.');
        }

        if (! $wp_filesystem->is_readable($temp)) {
            return new WP_Error('fs', 'Unable to read copied test file.');
        }

        if ($wp_filesystem->size($stub) !== $wp_filesystem->size($temp)) {
            return new WP_Error('fs', 'Size of copied test file doesn’t match.');
        }

        if (! $wp_filesystem->delete($temp)) {
            return new WP_Error('fs', 'Unable to delete copied test file.');
        }

        return true;
    }

    /**
     * Returns the diagnostic information.
     *
     * @return array
     */
    public function toArray()
    {
        return [
            self::GENERAL => $this->data[self::GENERAL] ?? null,
            self::ERRORS => $this->data[self::ERRORS] ?? null,
            self::VERSIONS => $this->data[self::VERSIONS] ?? null,
            self::STATISTICS => $this->data[self::STATISTICS] ?? null,
            self::CONFIG => $this->data[self::CONFIG] ?? null,
            self::CONSTANTS => $this->data[self::CONSTANTS] ?? null,
        ];
    }

    /**
     * Interface method to provide accessing diagnostics as array.
     *
     * @param  mixed  $group
     * @return bool
     */
    public function offsetExists($group)
    {
        return isset($this->data[$group]);
    }

    /**
     * Interface method to provide accessing diagnostics as array.
     *
     * @param  mixed  $group
     * @return mixed
     */
    public function offsetGet($group)
    {
        return $this->data[$group] ?: null;
    }

    /**
     * Interface method to provide accessing diagnostics as array.
     *
     * @param  mixed  $offset
     * @param  mixed  $value
     *
     * @return void
     */
    public function offsetSet($offset, $value)
    {
        throw new RuntimeException('Diagnostics cannot be set.');
    }

    /**
     * Interface method to provide accessing diagnostics as array.
     *
     * @param  mixed  $offset
     * @return void
     */
    public function offsetUnset($offset)
    {
        throw new RuntimeException('Diagnostics cannot be unset.');
    }
}
