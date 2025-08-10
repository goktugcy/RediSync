<?php

declare (strict_types = 1);

use PHPUnit\Framework\TestCase;
use RediSync\Database\DatabaseManager;

final class DatabaseManagerTest extends TestCase
{
    public function testFromDsnBuildsConnection(): void
    {
        $this->expectNotToPerformAssertions();
        // This only ensures no exception on constructing with a typical DSN format.
        DatabaseManager::fromDsn('sqlite:///:memory:');
    }
}
