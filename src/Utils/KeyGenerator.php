<?php

declare (strict_types = 1);

namespace RediSync\Utils;

use Psr\Http\Message\ServerRequestInterface;

class KeyGenerator
{
    public function __construct(private string $prefix = 'http:')
    {
        $this->prefix = rtrim($prefix, ':');
    }

    public function fromRequest(ServerRequestInterface $request): string
    {
        $method      = strtoupper($request->getMethod());
        $uri         = (string) $request->getUri()->getPath();
        $queryParams = $request->getQueryParams();
        ksort($queryParams);
        $query = http_build_query($queryParams);
        $key   = sprintf('%s:%s:%s?%s', $this->prefix, $method, $uri, $query);
        return md5($key);
    }

    public function fromParts(string $method, string $path, array $params = []): string
    {
        ksort($params);
        $query = http_build_query($params);
        $key   = sprintf('%s:%s:%s?%s', $this->prefix, strtoupper($method), $path, $query);
        return md5($key);
    }
}
