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
            $config = $this->app->make('config');
            $r      = $config->get('database.redis.default', []);
            $cache  = CacheManager::fromConfig([
                'host'     => $r['host'] ?? '127.0.0.1',
                'port'     => (int) ($r['port'] ?? 6379),
                'password' => $r['password'] ?? null,
                'database' => (int) ($r['database'] ?? 0),
                'prefix'   => 'redisync:',
            ]);
            if (interface_exists(\Psr\Log\LoggerInterface::class)) {
                try { $cache->setLogger($this->app->make(\Psr\Log\LoggerInterface::class));} catch (\Throwable $e) {}
            }
            return $cache;
        });

        if (class_exists(DatabaseManager::class) && interface_exists(\Doctrine\DBAL\Connection::class)) {
            $this->app->singleton(DatabaseManager::class, function () {
                $config = $this->app->make('config');
                $driver = $config->get('database.default');
                $cfg    = $config->get("database.connections.$driver", []);

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

                $db = DatabaseManager::fromDsn($dsn);
                if (interface_exists(\Psr\Log\LoggerInterface::class)) {
                    try { $db->setLogger($this->app->make(\Psr\Log\LoggerInterface::class));} catch (\Throwable $e) {}
                }
                return $db;
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
