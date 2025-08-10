<?php
return [
    'redis'    => [
        'host'     => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port'     => (int) (getenv('REDIS_PORT') ?: 6379),
        'password' => getenv('REDIS_PASSWORD') ?: null,
        'database' => (int) (getenv('REDIS_DB') ?: 0),
        'prefix'   => 'redisync:',
    ],
    'database' => [
        'url' => getenv('DATABASE_URL') ?: 'mysql://user:pass@127.0.0.1:3306/app',
    ],
    'cache'    => [
        'ttl' => (int) (getenv('CACHE_TTL') ?: 300),
    ],
];
