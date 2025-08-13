<?php

declare (strict_types = 1);

namespace RediSync\Cache;

use Predis\Client as PredisClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class CacheManager
{
    private PredisClient $client;
    private string $prefix;
    private LoggerInterface $logger;

    public function __construct(PredisClient $client, string $prefix = 'redisync:')
    {
        $this->client = $client;
        $this->prefix = rtrim($prefix, ':') . ':';
        $this->logger = new NullLogger();
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
        $redisKey = $this->key($key);
        $value    = $this->client->get($redisKey);
        if ($value === null) {
            $this->logger->info('cache.miss', ['key' => $key]);
            return null;
        }
        $decoded = json_decode((string) $value, true);
        $this->logger->info('cache.hit', ['key' => $key]);
        return $decoded;
    }

    /**
     * Set a value in cache.
     *
     * Note: Passing null evicts the key (delete). This avoids ambiguity, since get() returns null
     * both for missing keys and JSON null values.
     */
    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        if ($value === null) {
            $this->logger->info('cache.evict', ['key' => $key]);
            $this->delete($key);
            return;
        }
        $payload  = json_encode($value);
        $redisKey = $this->key($key);
        if ($ttl !== null && $ttl > 0) {
            $this->client->setex($redisKey, $ttl, $payload);
            $this->logger->info('cache.set', ['key' => $key, 'ttl' => $ttl]);
        } else {
            $this->client->set($redisKey, $payload);
            $this->logger->info('cache.set', ['key' => $key, 'ttl' => null]);
        }
    }

    /**
     * Get from cache or compute and store.
     *
     * @template T
     * @param string   $key
     * @param int      $ttl   TTL in seconds
     * @param callable():T $callback  Returns the value to cache on miss
     * @return mixed Returns cached or computed value
     */
    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $hit = $this->get($key);
        if ($hit !== null) {
            return $hit;
        }
        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    public function delete(string $key): void
    {
        $this->client->del([$this->key($key)]);
        $this->logger->info('cache.delete', ['key' => $key]);
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
        $this->logger->info('cache.clear_by_pattern', ['pattern' => $pattern, 'deleted' => $count]);
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

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }
}
