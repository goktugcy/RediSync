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
    ) {
        if ($this->responseFactory === null || $this->streamFactory === null) {
            $psr17                 = new Psr17Factory();
            $this->responseFactory = $this->responseFactory ?? $psr17;
            $this->streamFactory   = $this->streamFactory ?? $psr17;
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $key    = $this->keys->fromRequest($request);
        $cached = $this->cache->get($key);
        if ($cached !== null && isset($cached['status'], $cached['headers'], $cached['body'])) {
            $response = $this->responseFactory->createResponse($cached['status']);
            foreach ($cached['headers'] as $name => $values) {
                $response = $response->withHeader($name, $values);
            }
            $stream = $this->streamFactory->createStream($cached['body']);
            return $response->withBody($stream);
        }

        $response = $handler->handle($request);

        $body    = (string) $response->getBody();
        $headers = $response->getHeaders();
        $status  = $response->getStatusCode();
        $this->cache->set($key, [
            'status'  => $status,
            'headers' => $headers,
            'body'    => $body,
        ], $this->ttl);

        return $response;
    }
}
