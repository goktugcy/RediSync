<?php

declare (strict_types = 1);

namespace RediSync\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DBALException;

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
