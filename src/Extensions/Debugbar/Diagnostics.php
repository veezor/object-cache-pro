<?php

declare(strict_types=1);

use RedisCachePro\Diagnostics\Diagnostics;

class RedisCachePro_DebugBar_Diagnostics extends RedisCachePro_DebugBar_Panel
{
    /**
     * The diagnostics instance.
     *
     * @var  \RedisCachePro\Diagnostics\Diagnostics
     */
    protected $diagnostics;

    /**
     * Create a new diagnostics panel instance.
     *
     * @param  \RedisCachePro\Diagnostics\Diagnostics  $diagnostics
     */
    public function __construct(Diagnostics $diagnostics)
    {
        $this->diagnostics = $diagnostics;

        add_filter('debug_bar_classes', [$this, 'classes']);
    }

    /**
     * The title of the panel.
     *
     * @return  string
     */
    public function title()
    {
        return 'Redis Diagnostics';
    }

    /**
     * Whether the panel is visible.
     *
     * @return  bool
     */
    public function is_visible()
    {
        return true;
    }

    /**
     * Highlight "Debug" tab when errors occurred.
     *
     * @param  array  $classes
     * @return array
     */
    public function classes($classes)
    {
        $diagnostics = $this->diagnostics->toArray();

        if (! empty($diagnostics['errors'])) {
            $classes[] = 'debug-bar-php-warning-summary';
        }

        return $classes;
    }

    /**
     * Render the panel.
     *
     * @var void
     */
    public function render()
    {
        $diagnostics = $this->diagnostics->toArray();

        require __DIR__ . '/templates/diagnostics.phtml';
    }
}
