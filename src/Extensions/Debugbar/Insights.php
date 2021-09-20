<?php

declare(strict_types=1);

use RedisCachePro\Loggers\LoggerInterface;

class RedisCachePro_DebugBar_Insights extends RedisCachePro_DebugBar_Panel
{
    /**
     * Holds the object cache information object.
     *
     * @var object
     */
    protected $info;

    /**
     * Holds the logger instance.
     *
     * @var \RedisCachePro\Loggers\LoggerInterface
     */
    protected $logger;

    /**
     * Create a new insights panel instance.
     *
     * @param  object  $info
     * @param  \RedisCachePro\Loggers\LoggerInterface  $logger
     */
    public function __construct($info, LoggerInterface $logger)
    {
        $this->info = $info;
        $this->logger = $logger;
    }

    /**
     * The title of the panel.
     *
     * @return  string
     */
    public function title()
    {
        return 'Object Cache';
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
     * Render the panel.
     *
     * @var void
     */
    public function render()
    {
        $logs = method_exists($this->logger, 'messages')
            ? $this->logger->messages()
            : null;

        require __DIR__ . '/templates/insights.phtml';
    }
}
