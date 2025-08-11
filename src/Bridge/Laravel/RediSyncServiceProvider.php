<?php

declare (strict_types = 1);

namespace RediSync\Bridge\Laravel;

use Illuminate\Support\ServiceProvider;
use RediSync\Bridge\Laravel\Middleware\HttpCache;
use RediSync\Cache\CacheManager;
use RediSync\Database\DatabaseManager;

final class RediSyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CacheManager::class, function () {
            $r = config('database.redis.default', []);
            return CacheManager::fromConfig([
                'host'     => $r['host'] ?? '127.0.0.1',
                'port'     => (int) ($r['port'] ?? 6379),
                'password' => $r['password'] ?? null,
                'database' => (int) ($r['database'] ?? 0),
                'prefix'   => 'redisync:',
            ]);
        });

        if (class_exists(DatabaseManager::class) && interface_exists(\Doctrine\DBAL\Connection::class)) {
            $this->app->singleton(DatabaseManager::class, function () {
                $driver = config('database.default');
                $cfg    = config("database.connections.$driver", []);

                if (($cfg['driver'] ?? '') === 'mysql') {
                    $dsn = sprintf(
                        'mysql://%s:%s@%s:%d/%s?charset=%s',
                        $cfg['username'] ?? '',
                        $cfg['password'] ?? '',
                        $cfg['host'] ?? '127.0.0.1',
                        (int) ($cfg['port'] ?? 3306),
                        $cfg['database'] ?? '',
                        $cfg['charset'] ?? 'utf8mb4'
                    );
                } else {
                    $dsn = sprintf(
                        'pgsql://%s:%s@%s:%d/%s',
                        $cfg['username'] ?? '',
                        $cfg['password'] ?? '',
                        $cfg['host'] ?? '127.0.0.1',
                        (int) ($cfg['port'] ?? 5432),
                        $cfg['database'] ?? ''
                    );
                }

                return DatabaseManager::fromDsn($dsn);
            });
        }
    }

    public function boot(): void
    {
        if (isset($this->app['router'])) {
            $this->app['router']->aliasMiddleware('redisync.cache', HttpCache::class);
        }
    }
}
