<?php

declare (strict_types = 1);

use PHPUnit\Framework\TestCase;
use RediSync\Cache\CacheManager;

final class CacheManagerTest extends TestCase
{
    public function testSetGetAndDelete(): void
    {
        $cache = CacheManager::fromConfig(['host' => '127.0.0.1', 'port' => 6379, 'database' => 15, 'prefix' => 'test:redisync:']);
        $key   = 'unit:key';
        $cache->set($key, ['a' => 1], 1);
        $this->assertSame(['a' => 1], $cache->get($key));
        $cache->delete($key);
        $this->assertNull($cache->get($key));
    }
}
