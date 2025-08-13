<?php

declare (strict_types = 1);

namespace RediSync\Facades;

use Psr\Log\LoggerInterface;
use RediSync\Cache\CacheManager;

/**
 * Lightweight facade for framework-agnostic usage.
 * Usage: RediSync::set('key', $value, 60); RediSync::get('key');
 */
final class RediSync
{
    private static ?CacheManager $instance = null;

    /** Provide the CacheManager instance once at bootstrap. */
    public static function setInstance(CacheManager $cache): void
    {
        self::$instance = $cache;
    }

    private static function cache(): CacheManager
    {
        if (! self::$instance) {
            throw new \RuntimeException('RediSync Facade not initialized. Call RediSync::setInstance(CacheManager) first.');
        }
        return self::$instance;
    }

    public static function get(string $key): mixed
    {
        return self::cache()->get($key);
    }

    public static function set(string $key, mixed $value, ?int $ttl = null): void
    {
        self::cache()->set($key, $value, $ttl);
    }

    public static function delete(string $key): void
    {
        self::cache()->delete($key);
    }

    public static function setLogger(LoggerInterface $logger): void
    {
        self::cache()->setLogger($logger);
    }

    public static function remember(string $key, int $ttl, callable $callback): mixed
    {
        return self::cache()->remember($key, $ttl, $callback);
    }
}
