<?php

declare(strict_types=1);

namespace RedisCachePro\Extensions\QueryMonitor;

use QM_Collector;

class ObjectCacheCollector extends QM_Collector
{
    /**
     * Holds the ID of the collector.
     *
     * @var string
     */
    public $id = 'cache';

    /**
     * Returns the collector name.
     *
     * Obsolete since Query Monitor 3.5.0.
     *
     * @return string
     */
    public function name()
    {
        return 'Object Cache';
    }

    /**
     * Populate the `data` property.
     *
     * @return void
     */
    public function process()
    {
        global $wp_object_cache;

        $this->process_defaults();

        $diagnostics = $GLOBALS['RedisCachePro']->diagnostics();

        $this->data['has-dropin'] = $diagnostics->dropinExists();
        $this->data['valid-dropin'] = $this->data['has-dropin'] && $diagnostics->dropinIsValid();
        $this->data['status'] = $diagnostics['general']['status']->html;

        if (! method_exists($wp_object_cache, 'info')) {
            return;
        }

        $info = $wp_object_cache->info();

        $this->data['hits'] = $info->hits;
        $this->data['misses'] = $info->misses;
        $this->data['ratio'] = $info->ratio;

        if (isset($info->prefetches)) {
            $this->data['prefetches'] = $info->prefetches;
        }

        $this->data['errors'] = $info->errors;
        $this->data['meta'] = $info->meta;
        $this->data['bytes'] = $info->bytes;
        $this->data['groups'] = $info->groups;

        // Used by QM itself
        $this->data['stats']['cache_hits'] = $info->hits;
        $this->data['stats']['cache_misses'] = $info->misses;
        $this->data['cache_hit_percentage'] = $info->ratio;

        if (! method_exists($wp_object_cache, 'logger')) {
            return;
        }

        $logger = $wp_object_cache->logger();

        if (! method_exists($logger, 'messages')) {
            return;
        }

        $this->data['commands'] = count(array_filter($logger->messages(), function ($message) {
            return isset($message['context']['command']);
        }));
    }

    /**
     * Adds required default values to the `data` property.
     *
     * @return void
     */
    public function process_defaults()
    {
        $this->data['status'] = 'Unknown';
        $this->data['ratio'] = 0;
        $this->data['hits'] = 0;
        $this->data['misses'] = 0;
        $this->data['bytes'] = 0;

        // Used by QM itself
        $this->data['object_cache_extensions'] = [];
        $this->data['opcode_cache_extensions'] = [];

        if (function_exists('extension_loaded')) {
            $this->data['object_cache_extensions'] = array_map('extension_loaded', [
                'APCu' => 'APCu',
                'Memcache' => 'Memcache',
                'Memcached' => 'Memcached',
                'Redis' => 'Redis',
            ]);

            $this->data['opcode_cache_extensions'] = array_map('extension_loaded', [
                'APC' => 'APC',
                'Zend OPcache' => 'Zend OPcache',
            ]);
        }

        $this->data['has_object_cache'] = (bool) wp_using_ext_object_cache();
        $this->data['has_opcode_cache'] = array_filter($this->data['opcode_cache_extensions']) ? true : false;

        $this->data['display_hit_rate_warning'] = false;
        $this->data['ext_object_cache'] = $this->data['has_object_cache'];
    }
}
