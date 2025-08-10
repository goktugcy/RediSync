<?php

declare (strict_types = 1);

namespace RediSync\Cache;

use Predis\Client as PredisClient;

class CacheManager
{
    private PredisClient $client;
    private string $prefix;

    public function __construct(PredisClient $client, string $prefix = 'redisync:')
    {
        $this->client = $client;
        $this->prefix = rtrim($prefix, ':') . ':';
    }

    public static function fromConfig(array $config): self
    {
        $parameters = [
            'host'     => $config['host'] ?? '127.0.0.1',
            'port'     => $config['port'] ?? 6379,
            'database' => $config['database'] ?? 0,
        ];
        if (! empty($config['password'])) {
            $parameters['password'] = $config['password'];
        }
        $client = new PredisClient($parameters);
        $prefix = $config['prefix'] ?? 'redisync:';
        return new self($client, $prefix);
    }

    private function key(string $key): string
    {
        return $this->prefix . $key;
    }

    public function get(string $key): mixed
    {
        $value = $this->client->get($this->key($key));
        if ($value === null) {
            return null;
        }
        return json_decode((string) $value, true);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        $payload  = json_encode($value);
        $redisKey = $this->key($key);
        if ($ttl !== null && $ttl > 0) {
            $this->client->setex($redisKey, $ttl, $payload);
        } else {
            $this->client->set($redisKey, $payload);
        }
    }

    public function delete(string $key): void
    {
        $this->client->del([$this->key($key)]);
    }

    public function clearByPattern(string $pattern = '*'): int
    {
        $cursor      = 0;
        $count       = 0;
        $fullPattern = $this->prefix . $pattern;
        do {
            [$cursor, $keys] = $this->client->scan($cursor, ['match' => $fullPattern, 'count' => 100]);
            if (! empty($keys)) {
                $this->client->del($keys);
                $count += count($keys);
            }
        } while ($cursor != 0);
        return $count;
    }

    public function listKeys(string $pattern = '*', int $limit = 1000): array
    {
        $cursor      = 0;
        $fullPattern = $this->prefix . $pattern;
        $results     = [];
        do {
            [$cursor, $keys] = $this->client->scan($cursor, ['match' => $fullPattern, 'count' => 100]);
            foreach ($keys as $k) {
                $results[] = substr($k, strlen($this->prefix));
                if (count($results) >= $limit) {
                    return $results;
                }
            }
        } while ($cursor != 0);
        return $results;
    }

    public function keyInfo(string $key): array
    {
        $full = $this->key($key);
        $ttl  = $this->client->ttl($full);
        $type = $this->client->type($full);
        $size = $this->client->strlen($full);
        return [
            'key'    => $key,
            'ttl'    => $ttl,
            'type'   => $type,
            'size'   => $size,
            'exists' => $this->client->exists($full) === 1,
        ];
    }
}
