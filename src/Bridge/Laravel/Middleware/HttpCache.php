<?php

declare (strict_types = 1);

namespace RediSync\Bridge\Laravel\Middleware;

use Closure;
use RediSync\Cache\CacheManager;
use RediSync\Utils\KeyGenerator;

final class HttpCache
{
    public function __construct(
        private CacheManager $cache,
        private int $defaultTtl = 300
    ) {}

    public function handle($request, Closure $next)
    {
        $method = strtoupper($request->getMethod());
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return $next($request);
        }

        if ($request->headers->get('X-Bypass-Cache') === '1') {
            return $next($request);
        }

        // Respect request Cache-Control: no-store
        $reqCc = (string) $request->headers->get('Cache-Control', '');
        if ($reqCc !== '' && str_contains(strtolower($reqCc), 'no-store')) {
            return $next($request);
        }
        // Vary safety: Authorization/Cookie present -> bypass shared cache
        if ($request->headers->has('Authorization') || $request->cookies->count() > 0) {
            return $next($request);
        }

        $keyGen = new KeyGenerator('http', ['nonce', '_ts']);
        $key    = $keyGen->fromParts('GET', '/' . ltrim($request->path(), '/'), $request->query());

        if (($hit = $this->cache->get($key)) !== null) {
            // Rehydrate headers and body
            $status  = $hit['status'] ?? 200;
            $headers = $hit['headers'] ?? [];
            $body    = $hit['body'] ?? '';
            $etag    = $hit['etag'] ?? null;
            // Prefer Symfony Response if available; otherwise try Laravel helper; else bypass
            if (class_exists('Symfony\\Component\\HttpFoundation\\Response')) {
                $resp = new \Symfony\Component\HttpFoundation\Response($body, $status, $headers);
            } elseif (function_exists('response')) {
                $resp = \response($body, $status, $headers);
            } else {
                return $next($request);
            }
            // Age header
            if (isset($hit['ts']) && is_int($hit['ts'])) {
                $age = max(0, time() - $hit['ts']);
                $resp->headers->set('Age', (string) $age, true);
            }
            $resp->headers->set('X-RediSync-Cache', 'HIT', true);
            // If-None-Match -> 304
            $ifNoneMatch = (string) $request->headers->get('If-None-Match', '');
            if ($ifNoneMatch !== '' && $etag) {
                if ($this->ifNoneMatchSatisfied($ifNoneMatch, (string) $etag)) {
                    $resp->setStatusCode(304);
                    $resp->setContent('');
                    if ($etag) {
                        $resp->headers->set('ETag', (string) $etag, true);
                    }
                }
            }
            // HEAD -> no body
            if ($method === 'HEAD') {
                $resp->setContent('');
            }
            return $resp;
        }

        $response = $next($request);
        $response->headers->set('X-RediSync-Cache', 'MISS', true);

        $status = $response->getStatusCode();
        $type   = (string) $response->headers->get('Content-Type', '');

        // Cacheability checks: status/content-type JSON only by default
        if ($status === 200 && str_starts_with(strtolower($type), 'application/json')) {
            // Respect response Cache-Control: no-store/private
            $resCc = (string) $response->headers->get('Cache-Control', '');
            if ($resCc !== '') {
                $ccLower = strtolower($resCc);
                if (str_contains($ccLower, 'no-store') || str_contains($ccLower, 'private')) {
                    return $response;
                }
            }
            // Ensure ETag
            $body = (string) $response->getContent();
            $etag = (string) $response->headers->get('ETag', '');
            if ($etag === '') {
                $etag = '"' . md5($body) . '"';
                $response->headers->set('ETag', $etag, true);
            }
            // Store full payload similar to PSR-15 middleware (only for GET)
            if ($method === 'GET') {
                $payload = [
                    'status'  => $status,
                    'headers' => $response->headers->all(),
                    'body'    => $body,
                    'ts'      => time(),
                    'etag'    => $etag,
                ];
                $this->cache->set($key, $payload, $this->defaultTtl);
            }
        }

        return $response;
    }

    private function ifNoneMatchSatisfied(string $ifNoneMatch, string $etag): bool
    {
        $etags = array_map('trim', explode(',', $ifNoneMatch));
        foreach ($etags as $candidate) {
            if ($candidate === '*') {
                return true;
            }
            $c = $candidate;
            if (str_starts_with($c, 'W/')) {
                $c = substr($c, 2);
            }
            if ($c === $etag) {
                return true;
            }
        }
        return false;
    }
}
