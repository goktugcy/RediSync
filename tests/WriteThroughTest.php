<?php

declare (strict_types = 1);

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use RediSync\Cache\CacheManager;
use RediSync\Database\DatabaseManager;

final class WriteThroughTest extends TestCase
{
    public function testInsertWriteThroughUpdatesCache(): void
    {
        $db = DatabaseManager::fromDsn('sqlite:///:memory:');
        $db->getConnection()->executeStatement('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');

        $cache = CacheManager::fromConfig(['host' => '127.0.0.1', 'port' => 6379, 'database' => 15, 'prefix' => 'test:redisync:']);

        $affected = $db->writeThrough(
            'INSERT INTO users (name) VALUES (:name)',
            ['name' => 'alice'],
            $cache,
            function (int $affected, array $params, Connection $conn): array {
                $id  = (int) $conn->lastInsertId();
                $row = ['id' => $id, 'name' => $params['name']];
                return [['key' => "users:$id", 'value' => $row, 'ttl' => 5]];
            },
            null
        );

        $this->assertSame(1, $affected);
        // Fetch id 1
        $data = $cache->get('users:1');
        $this->assertIsArray($data);
        $this->assertSame(['id' => 1, 'name' => 'alice'], $data);
    }
}
