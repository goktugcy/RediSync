<?php

declare (strict_types = 1);

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use RediSync\Cache\CacheManager;
use RediSync\Middleware\CacheMiddleware;
use RediSync\Utils\KeyGenerator;

final class CacheMiddlewareIntegrationTest extends TestCase
{
    public function testCacheHitAndMiss(): void
    {
        $cache = CacheManager::fromConfig(['host' => '127.0.0.1', 'port' => 6379, 'database' => 15, 'prefix' => 'itest:']);
        $cache->clearByPattern('*');

        $keys       = new KeyGenerator('http:itest');
        $psr17      = new Psr17Factory();
        $middleware = new CacheMiddleware($cache, $keys, ttl: 30, responseFactory: $psr17, streamFactory: $psr17);

        $request = $psr17->createServerRequest('GET', 'https://example.com/users?id=1');
        $handler = new class($psr17) implements RequestHandlerInterface
        {
            private int $count = 0;
            public function __construct(private Psr17Factory $psr17)
            {}
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->count++;
                $response = $this->psr17->createResponse(200);
                $response->getBody()->write('hello-' . $this->count);
                return $response;
            }
        };

        // first pass (miss)
        $resp1 = $middleware->process($request, $handler);
        $this->assertSame(200, $resp1->getStatusCode());
        $this->assertSame('hello-1', (string) $resp1->getBody());

        // second pass (hit) should not increment handler count and return same body
        $resp2 = $middleware->process($request, $handler);
        $this->assertSame(200, $resp2->getStatusCode());
        $this->assertSame('hello-1', (string) $resp2->getBody());
    }
}
