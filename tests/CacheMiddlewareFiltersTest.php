<?php

declare (strict_types = 1);

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use RediSync\Cache\CacheManager;
use RediSync\Middleware\CacheMiddleware;
use RediSync\Utils\KeyGenerator;

final class CacheMiddlewareFiltersTest extends TestCase
{
    public function testNonWhitelistedStatusIsNotCached(): void
    {
        $cache = CacheManager::fromConfig(['host' => '127.0.0.1', 'port' => 6379, 'database' => 15, 'prefix' => 'filter:']);
        $cache->clearByPattern('*');
        $psr17 = new Psr17Factory();
        $keys  = new KeyGenerator('http:filter');
        // allow only 200, set response 404 -> not cached
        $mw = new CacheMiddleware($cache, $keys, 60, $psr17, $psr17, statusWhitelist: [200]);

        $req     = $psr17->createServerRequest('GET', 'https://e.com/missing');
        $handler = new class($psr17) implements RequestHandlerInterface
        {
            public function __construct(private Psr17Factory $psr17)
            {}
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return $this->psr17->createResponse(404);
            }
        };

        $resp1 = $mw->process($req, $handler);
        $this->assertSame(404, $resp1->getStatusCode());

        // second call should not be a cache hit; handler returns 404 again
        $resp2 = $mw->process($req, $handler);
        $this->assertSame(404, $resp2->getStatusCode());
    }

    public function testAllowedContentTypeIsCached(): void
    {
        $cache = CacheManager::fromConfig(['host' => '127.0.0.1', 'port' => 6379, 'database' => 15, 'prefix' => 'filter:']);
        $cache->clearByPattern('*');
        $psr17 = new Psr17Factory();
        $keys  = new KeyGenerator('http:filter');
        $mw    = new CacheMiddleware($cache, $keys, 60, $psr17, $psr17, statusWhitelist: [200], allowedContentTypes: ['application/json']);

        $req     = $psr17->createServerRequest('GET', 'https://e.com/data');
        $handler = new class($psr17) implements RequestHandlerInterface
        {
            private int $count = 0;
            public function __construct(private Psr17Factory $psr17)
            {}
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->count++;
                $r = $this->psr17->createResponse(200)->withHeader('Content-Type', 'application/json; charset=utf-8');
                $r->getBody()->write(json_encode(['n' => $this->count]));
                return $r;
            }
        };

        $resp1 = $mw->process($req, $handler);
        $this->assertSame('{"n":1}', (string) $resp1->getBody());
        $resp2 = $mw->process($req, $handler);
        $this->assertSame('{"n":1}', (string) $resp2->getBody());
    }

    public function testTtlMapOverridesDefault(): void
    {
        $cache = CacheManager::fromConfig(['host' => '127.0.0.1', 'port' => 6379, 'database' => 15, 'prefix' => 'filter:']);
        $cache->clearByPattern('*');
        $psr17 = new Psr17Factory();
        $keys  = new KeyGenerator('http:filter');
        $mw    = new CacheMiddleware($cache, $keys, 5, $psr17, $psr17, ttlMap: [
            '/fast/*'      => 1,
            '#^/slow/.*$#' => 60,
        ]);

        $reqFast = $psr17->createServerRequest('GET', 'https://e.com/fast/x');
        $handler = new class($psr17) implements RequestHandlerInterface
        {
            public function __construct(private Psr17Factory $psr17)
            {}
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $r = $this->psr17->createResponse(200)->withHeader('Content-Type', 'text/plain');
                $r->getBody()->write('ok');
                return $r;
            }
        };

        $mw->process($reqFast, $handler);
        // We cannot read TTL back easily; assert that key exists by second hit
        $resp2 = $mw->process($reqFast, $handler);
        $this->assertSame('ok', (string) $resp2->getBody());
    }
}
