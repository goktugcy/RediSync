<?php

declare (strict_types = 1);

use PHPUnit\Framework\TestCase;
use RediSync\Cache\CacheManager;
use RediSync\Facades\RediSync;

final class FacadeRememberTest extends TestCase
{
    public function testRememberCachesValue(): void
    {
        $cache = CacheManager::fromConfig(['host' => '127.0.0.1', 'port' => 6379, 'database' => 15, 'prefix' => 'test:remember:']);
        // Framework-agnostic facade bootstrap
        RediSync::setInstance($cache);

        $key   = 'val:1';
        $count = 0;
        $val1  = RediSync::remember($key, 5, function () use (&$count) {
            $count++;
            return ['n' => 42];
        });
        $this->assertSame(['n' => 42], $val1);

        // Second call should hit cache, not increment counter
        $val2 = RediSync::remember($key, 5, function () use (&$count) {
            $count++;
            return ['n' => 99];
        });
        $this->assertSame(['n' => 42], $val2);
        $this->assertSame(1, $count);
    }
}
