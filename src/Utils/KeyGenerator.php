<?php

declare (strict_types = 1);

namespace RediSync\Utils;

use Psr\Http\Message\ServerRequestInterface;

class KeyGenerator
{
    /** @var string[] */
    private array $ignoredParams;

    public function __construct(private string $prefix = 'http:', array $ignoredParams = [])
    {
        $this->prefix        = rtrim($prefix, ':');
        $this->ignoredParams = $ignoredParams;
    }

    public function fromRequest(ServerRequestInterface $request): string
    {
        $method      = strtoupper($request->getMethod());
        $uri         = (string) $request->getUri()->getPath();
        $queryParams = $request->getQueryParams();
        if (! empty($this->ignoredParams)) {
            foreach ($this->ignoredParams as $p) {
                unset($queryParams[$p]);
            }
        }
        $queryParams = $this->ksortRecursive($queryParams);
        $query       = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
        $base        = sprintf('%s:%s:%s', $this->prefix, $method, $uri);
        $key         = $query === '' ? $base : ($base . '?' . $query);
        return md5($key);
    }

    public function fromParts(string $method, string $path, array $params = []): string
    {
        if (! empty($this->ignoredParams)) {
            foreach ($this->ignoredParams as $p) {
                unset($params[$p]);
            }
        }
        $params = $this->ksortRecursive($params);
        $query  = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $base   = sprintf('%s:%s:%s', $this->prefix, strtoupper($method), $path);
        $key    = $query === '' ? $base : ($base . '?' . $query);
        return md5($key);
    }

    /**
     * Recursively ksort an array to ensure deterministic ordering for nested params.
     *
     * @param array $arr
     * @return array
     */
    private function ksortRecursive(array $arr): array
    {
        foreach ($arr as $k => $v) {
            if (is_array($v)) {
                $arr[$k] = $this->ksortRecursive($v);
            }
        }
        ksort($arr);
        return $arr;
    }
}
