<?php

declare (strict_types = 1);

namespace RediSync\Middleware;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RediSync\Cache\CacheManager;
use RediSync\Utils\KeyGenerator;

class CacheMiddleware implements MiddlewareInterface
{
    public function __construct(
        private CacheManager $cache,
        private KeyGenerator $keys,
        private int $ttl = 300,
        private ?ResponseFactoryInterface $responseFactory = null,
        private ?StreamFactoryInterface $streamFactory = null,
        private array $statusWhitelist = [200],
        private array $allowedContentTypes = [],
        private array $ttlMap = [],
    ) {
        if ($this->responseFactory === null || $this->streamFactory === null) {
            $psr17                 = new Psr17Factory();
            $this->responseFactory = $this->responseFactory ?? $psr17;
            $this->streamFactory   = $this->streamFactory ?? $psr17;
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        $isHead = $method === 'HEAD';
        $isGet  = $method === 'GET';
        // Only cache idempotent GET/HEAD requests by default
        if (! $isGet && ! $isHead) {
            return $handler->handle($request);
        }
        // Allow bypass with header X-Bypass-Cache: 1
        if ($request->getHeaderLine('X-Bypass-Cache') === '1') {
            return $handler->handle($request);
        }
        // Respect request Cache-Control: no-store (do not use or write cache)
        $reqCc = $request->getHeaderLine('Cache-Control');
        if ($reqCc !== '' && stripos($reqCc, 'no-store') !== false) {
            return $handler->handle($request);
        }

        // For HEAD, use the same cache key as GET
        $requestForKey = $isHead ? $request->withMethod('GET') : $request;
        $key           = $this->keys->fromRequest($requestForKey);
        $cached        = $this->cache->get($key);
        if ($cached !== null && isset($cached['status'], $cached['headers'], $cached['body'])) {
            $response = $this->responseFactory->createResponse($cached['status']);
            foreach ($cached['headers'] as $name => $values) {
                $response = $response->withHeader($name, $values);
            }
            // Age header if timestamp exists
            if (isset($cached['ts']) && is_int($cached['ts'])) {
                $age      = max(0, time() - $cached['ts']);
                $response = $response->withHeader('Age', (string) $age);
            }
            $response = $response->withHeader('X-RediSync-Cache', 'HIT');
            // If-None-Match handling: return 304 when ETag matches
            $ifNoneMatch = $request->getHeaderLine('If-None-Match');
            $etag        = $cached['etag'] ?? null;
            if ($ifNoneMatch !== '' && $etag) {
                if ($this->ifNoneMatchSatisfied($ifNoneMatch, (string) $etag)) {
                    // 304 Not Modified: empty body, include ETag
                    $response = $response->withStatus(304)->withHeader('ETag', (string) $etag);
                    $empty    = $this->streamFactory->createStream('');
                    return $response->withBody($empty);
                }
            }
            // For HEAD, return headers only
            if ($isHead) {
                $empty = $this->streamFactory->createStream('');
                return $response->withBody($empty);
            }
            $stream = $this->streamFactory->createStream($cached['body']);
            return $response->withBody($stream);
        }

        $response = $handler->handle($request);
        $response = $response->withHeader('X-RediSync-Cache', 'MISS');

        $body    = (string) $response->getBody();
        $headers = $response->getHeaders();
        $status  = $response->getStatusCode();
        if (! $this->isCacheableResponse($response)) {
            return $response;
        }
        // Respect response Cache-Control: no-store (do not store)
        $resCc = $response->getHeaderLine('Cache-Control');
        if ($resCc !== '' && stripos($resCc, 'no-store') !== false) {
            return $response;
        }
        // Ensure ETag header exists: use origin's ETag or compute from body
        $etagHeader = $response->getHeaderLine('ETag');
        $etag       = $etagHeader !== '' ? $etagHeader : ('"' . md5($body) . '"');
        if ($etagHeader === '') {
            $response = $response->withHeader('ETag', $etag);
            // refresh headers snapshot to include ETag
            $headers = $response->getHeaders();
        }
        // Only store bodies for GET responses; HEAD uses GET key but shouldn't store bodyless responses
        if ($isGet) {
            $ttl = $this->resolveTtl($request->getUri()->getPath());
            $this->cache->set($key, [
                'status'  => $status,
                'headers' => $headers,
                'body'    => $body,
                'ts'      => time(),
                'etag'    => $etag,
            ], $ttl);
        }

        return $response;
    }

    private function ifNoneMatchSatisfied(string $ifNoneMatch, string $etag): bool
    {
        // Support multiple ETags and weak validators
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

    private function isCacheableResponse(ResponseInterface $response): bool
    {
        // status whitelist
        if (! in_array($response->getStatusCode(), $this->statusWhitelist, true)) {
            return false;
        }
        // content-type allow list (only if configured)
        if (! empty($this->allowedContentTypes)) {
            $ct = $response->getHeaderLine('Content-Type');
            if ($ct === '') {
                return false;
            }
            $ok = false;
            foreach ($this->allowedContentTypes as $allowed) {
                if (stripos($ct, $allowed) === 0) {$ok = true;
                    break;}
            }
            if (! $ok) {
                return false;
            }
        }
        return true;
    }

    private function resolveTtl(string $path): int
    {
        foreach ($this->ttlMap as $pattern => $ttl) {
            if ($this->patternMatch($path, $pattern)) {
                return (int) $ttl;
            }
        }
        return $this->ttl;
    }

    private function patternMatch(string $path, string $pattern): bool
    {
        // If pattern looks like a regex (#...#), use preg_match
        $first = substr($pattern, 0, 1);
        $last  = substr($pattern, -1);
        if (strlen($pattern) > 2 && $first === $last && ! ctype_alnum($first)) {
            return (bool) @preg_match($pattern, $path);
        }
        // otherwise use glob-like matching
        if (function_exists('fnmatch')) {
            return fnmatch($pattern, $path);
        }
        // fallback: simple prefix match
        return str_starts_with($path, rtrim($pattern, '*'));
    }
}
