<?php

declare (strict_types = 1);

namespace RediSync\Bridge\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use RediSync\Cache\CacheManager;
use RediSync\Utils\KeyGenerator;

final class HttpCache
{
    public function __construct(
        private CacheManager $cache,
        private int $defaultTtl = 300
    ) {}

    public function handle(Request $request, Closure $next)
    {
        if (strtoupper($request->getMethod()) !== 'GET') {
            return $next($request);
        }

        if ($request->headers->get('X-Bypass-Cache') === '1') {
            return $next($request);
        }

        $keyGen = new KeyGenerator('http', ['nonce', '_ts']);
        $key    = $keyGen->fromParts('GET', '/' . ltrim($request->path(), '/'), $request->query());

        if (($hit = $this->cache->get($key)) !== null) {
            return response()->json($hit);
        }

        $response = $next($request);

        $status = $response->getStatusCode();
        $type   = $response->headers->get('Content-Type', '');

        if ($status === 200 && str_starts_with(strtolower($type), 'application/json')) {
            $payload = json_decode($response->getContent(), true);
            if ($payload !== null) {
                $this->cache->set($key, $payload, $this->defaultTtl);
            }
        }

        return $response;
    }
}
