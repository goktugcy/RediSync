<?php

declare (strict_types = 1);

namespace RediSync\Bridge\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use RediSync\Cache\CacheManager;

/**
 * @method static mixed get(string $key)
 * @method static void set(string $key, mixed $value, ?int $ttl = null)
 * @method static void delete(string $key)
 * @method static int clearByPattern(string $pattern = '*')
 * @method static array listKeys(string $pattern = '*', int $limit = 1000)
 * @method static array keyInfo(string $key)
 * @method static mixed remember(string $key, int $ttl, callable $callback)
 */
final class RediSync extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CacheManager::class;
    }
}
