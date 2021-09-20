<?php

declare(strict_types=1);

namespace RedisCachePro\Extensions\QueryMonitor;

use QueryMonitor;
use QM_Backtrace;
use QM_Collector;

use RedisCachePro\Connections\Connection;
use RedisCachePro\ObjectCaches\PhpRedisObjectCache;

class CommandsCollector extends QM_Collector
{
    /**
     * Holds the ID of the collector.
     *
     * @var string
     */
    public $id = 'cache-commands';

    /**
     * Ignored functions, classes and methods in backtrace.
     *
     * @var array
     */
    protected $ignore = [
        'ignore_class' => [
            Connection::class => true,
        ],
        'ignore_method' => [
            PhpRedisObjectCache::class => [
                'add' => true,
                'set' => true,
                'get' => true,
                'get_multiple' => true,
                'decr' => true,
                'incr' => true,
                'info' => true,
                'flush' => true,
                'write' => true,
                'delete' => true,
                'replace' => true,
                'getAllOptions' => true,
                'syncAllOptions' => true,
                'deleteAllOptions' => true,
            ],
        ],
        'ignore_func' => [
            'wp_cache_add' => true,
            'wp_cache_get' => true,
            'wp_cache_get_multiple' => true,
            'wp_cache_set' => true,
            'wp_cache_decr' => true,
            'wp_cache_incr' => true,
            'wp_cache_sear' => true,
            'wp_cache_flush' => true,
            'wp_cache_delete' => true,
            'wp_cache_replace' => true,
            'wp_cache_remember' => true,
            'wp_cache_switch_to_blog' => true,
            'wp_cache_add_global_groups' => true,
            'wp_cache_add_non_persistent_groups' => true,
            'set_transient' => true,
            'get_transient' => true,
            'get_site_transient' => true,
            'get_option' => true,
            'get_site_option' => true,
            'get_network_option' => true,
        ],
    ];

    /**
     * Returns the collector name.
     *
     * Obsolete since Query Monitor 3.5.0.
     *
     * @return string
     */
    public function name()
    {
        return 'Commands';
    }

    /**
     * Populate the `data` property.
     *
     * @return void
     */
    public function process()
    {
        global $wp_object_cache;

        if (! method_exists($wp_object_cache, 'logger')) {
            return;
        }

        $logger = $wp_object_cache->logger();

        if (! method_exists($logger, 'messages')) {
            return;
        }

        $useQmBacktraces = false;
        // $qm = QueryMonitor::init();

        // if (isset($qm->file) && is_readable($qm->file)) {
        //     $qm = \get_plugin_data($qm->file, false, false);
        //     $useQmBacktraces = version_compare($qm['Version'], '3.6.1', '>=');
        // }

        $this->data['commands'] = array_map(function ($message) use ($useQmBacktraces) {
            return [
                'level' => $message['level'],
                'time' => $message['context']['time'],
                'command' => $message['context']['command'],
                'parameters' => $this->formatParameters(
                    $message['context']['parameters']
                ),
                'backtrace' => $useQmBacktraces
                    ? new QM_Backtrace($this->ignore, $message['context']['backtrace'])
                    : $this->formatBacktrace($message['context']['backtrace_summary']),
            ];
        }, $logger->messages());

        $types = array_unique(array_column($this->data['commands'], 'command'));
        $types = array_map('strtoupper', $types);

        sort($types);

        $this->data['types'] = $types;
    }

    /**
     * Converts all parameter values to JSON and trims them down to 200 characters or less.
     *
     * @param  string  $backtrace
     * @return array
     */
    protected function formatParameters($parameters)
    {
        $format = function ($value) {
            return json_encode(
                $value,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            );
        };

        return array_map(function ($parameter) use ($format) {
            $value = is_object($parameter)
                ? get_class($parameter) . $format($parameter)
                : $format($parameter);

            $value = preg_replace('/\s+/', ' ', $value);
            $value = trim((string) $value, '"');

            if (strlen($value) > 400) {
                return substr($value, 0, 400) . '...';
            }

            return $value;
        }, $parameters);
    }

    /**
     * Converts the `wp_debug_backtrace_summary()` backtrace into human readable array for display.
     *
     * @param  string  $backtrace
     * @return array
     */
    protected function formatBacktrace($backtrace)
    {
        $backtrace = str_replace('RedisCachePro\ObjectCaches\\', '', $backtrace);
        $backtrace = array_reverse(explode(', ', $backtrace));
        $backtrace = array_slice($backtrace, 0, 7);

        return array_filter($backtrace, function ($trace) {
            return ! in_array($trace, [
                'WP_Hook->apply_filters',
                'WP_Hook->do_action',
                "require_once('wp-includes/template-loader.php')",
                "require_once('wp-settings.php')",
                "require_once('wp-config.php')",
                "require_once('wp-load.php')",
                "require('wp-blog-header.php')",
                "require('index.php')",
            ]);
        });
    }
}
