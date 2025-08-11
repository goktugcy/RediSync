<?php

declare (strict_types = 1);

namespace RediSync\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DBALException;
use RediSync\Cache\CacheManager;

class DatabaseManager
{
    private Connection $connection;
    /** @var callable[] */
    private array $invalidationCallbacks = [];

    private function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public static function fromDsn(string $dsn): self
    {
        $connection = DriverManager::getConnection(['url' => $dsn]);
        return new self($connection);
    }

    public static function fromConfig(array $config): self
    {
        $dsn = $config['url'] ?? '';
        if ($dsn === '') {
            throw new \InvalidArgumentException('Database DSN url must be provided');
        }
        return self::fromDsn($dsn);
    }

    public function fetchOne(string $sql, array $params = []): array | null
    {
        try {
            $stmt   = $this->connection->prepare($sql);
            $result = $stmt->executeQuery($params);
            $row    = $result->fetchAssociative();
            return $row ?: null;
        } catch (DBALException $e) {
            throw $e; // bubble up for now
        }
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        try {
            $stmt   = $this->connection->prepare($sql);
            $result = $stmt->executeQuery($params);
            return $result->fetchAllAssociative();
        } catch (DBALException $e) {
            throw $e; // bubble up for now
        }
    }

    public function execute(string $sql, array $params = []): int
    {
        try {
            $affected = $this->connection->executeStatement($sql, $params);
            $this->maybeInvalidate($sql, $params);
            return $affected;
        } catch (DBALException $e) {
            throw $e; // bubble up for now
        }
    }

    /**
     * Write-through helper: perform a DB write, then update cache entries atomically on success.
     *
     * Usage:
     *  - Static plan (array of entries):
     *      $db->writeThrough(
     *          'UPDATE users SET name = :n WHERE id = :id', ['n' => $name, 'id' => $id],
     *          $cache,
     *          [ ['key' => "users:$id", 'value' => $newUserArray, 'ttl' => 300] ]
     *      );
     *
     *  - Callable plan (compute entries from result):
     *      $db->writeThrough($sql, $params, $cache, function(int $affected, array $params, Connection $conn) {
     *          if ($affected > 0) {
     *              // Example: $user = fetch from DB here based on $params
     *              $user = [];
     *              return [ ['key' => "users:{$params['id']}", 'value' => $user, 'ttl' => 300] ];
     *          }
     *          return [];
     *      });
     *
     * Entry formats supported:
     *  - [ ['key' => string, 'value' => mixed, 'ttl' => ?int], ... ]
     *  - [ 'key1' => mixed, 'key2' => mixed ]  // uses $defaultTtl
     */
    public function writeThrough(
        string $sql,
        array $params,
        CacheManager $cache,
        array | callable $cachePlan,
        ?int $defaultTtl = null
    ): int {
        try {
            return $this->connection->transactional(function (Connection $conn) use ($sql, $params, $cache, $cachePlan, $defaultTtl): int {
                $affected = $conn->executeStatement($sql, $params);

                // Invalidate as usual for compatibility with existing patterns
                $this->maybeInvalidate($sql, $params);

                // Build cache entries
                $entries = is_callable($cachePlan)
                ? (array) $cachePlan((int) $affected, $params, $conn)
                : $cachePlan;

                if ($affected > 0 && ! empty($entries)) {
                    $this->applyCacheEntries($cache, $entries, $defaultTtl);
                }

                return (int) $affected;
            });
        } catch (DBALException $e) {
            throw $e;
        }
    }

    /** @param array<int|string, mixed>|array<int, array{key:string,value:mixed,ttl?:int}> $entries */
    private function applyCacheEntries(CacheManager $cache, array $entries, ?int $defaultTtl): void
    {
        // Case 1: list of structured entries
        $isStructuredList = isset($entries[0]) && is_array($entries[0]) && array_key_exists('key', $entries[0]);
        if ($isStructuredList) {
            foreach ($entries as $entry) {
                if (! is_array($entry) || ! isset($entry['key'])) {
                    continue;
                }
                $ttl = array_key_exists('ttl', $entry) ? (is_null($entry['ttl']) ? null : (int) $entry['ttl']) : $defaultTtl;
                $cache->set((string) $entry['key'], $entry['value'] ?? null, $ttl);
            }
            return;
        }

        // Case 2: associative map key => value
        $isAssoc = fn(array $a) => array_keys($a) !== range(0, count($a) - 1);
        if ($isAssoc($entries)) {
            foreach ($entries as $key => $value) {
                $cache->set((string) $key, $value, $defaultTtl);
            }
        }
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /** Register a callback to run on data-changing statements (UPDATE/DELETE/INSERT) */
    public function onInvalidate(callable $fn): void
    {
        $this->invalidationCallbacks[] = $fn;
    }

    private function maybeInvalidate(string $sql, array $params): void
    {
        // Heuristic: if the SQL starts with UPDATE/DELETE/INSERT, trigger callbacks
        $prefix = strtoupper(ltrim($sql));
        if (str_starts_with($prefix, 'UPDATE') || str_starts_with($prefix, 'DELETE') || str_starts_with($prefix, 'INSERT')) {
            foreach ($this->invalidationCallbacks as $fn) {
                try {
                    $fn($sql, $params);
                } catch (\Throwable $e) {
                    // swallow to not break DB flow
                }
            }
        }
    }
}
