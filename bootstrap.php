<?php

defined('ABSPATH') || exit;

array_map(function ($class) {
    require_once __DIR__ . "/src/{$class}.php";
}, [
    'Configuration/Concerns/Cluster',
    'Configuration/Concerns/Replication',
    'Configuration/Configuration',
    'Connections/Connection',
    'Connections/ConnectionInterface',
    'Connections/PhpRedisConnection',
    'Connections/PhpRedisClusterConnection',
    'Connections/PhpRedisReplicatedConnection',
    'Connections/RelayConnection',
    'Connectors/Connector',
    'Connectors/PhpRedisConnector',
    'Connectors/RelayConnector',
    'Exceptions/ObjectCacheException',
    'Exceptions/ConfigurationException',
    'Exceptions/ConfigurationInvalidException',
    'Exceptions/ConfigurationMissingException',
    'Exceptions/ConnectionDetailsMissingException',
    'Exceptions/ConnectionException',
    'Exceptions/PhpRedisMissingException',
    'Exceptions/PhpRedisOutdatedException',
    'Exceptions/RelayMissingException',
    'Diagnostics/Diagnostic',
    'Diagnostics/Diagnostics',
    'Loggers/LoggerInterface',
    'Loggers/Logger',
    'Loggers/NullLogger',
    'Loggers/CallbackLogger',
    'Loggers/ErrorLogLogger',
    'Loggers/BacktraceLogger',
    'Loggers/ArrayLogger',
    'ObjectCaches/ObjectCache',
    'ObjectCaches/ObjectCacheInterface',
    'ObjectCaches/ArrayObjectCache',
    'ObjectCaches/Concerns/FlushesNetworks',
    'ObjectCaches/Concerns/PrefetchesKeys',
    'ObjectCaches/Concerns/SplitsAllOptions',
    'ObjectCaches/PhpRedisObjectCache',
    'ObjectCaches/RelayObjectCache',
]);