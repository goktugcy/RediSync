# RediSync

Smart caching middleware for PHP using Redis and MySQL/PostgreSQL.

## Install

Require via Composer in your project:

```
composer require redisync/core
```

## Usage

- Configure `config/config.php` or via env variables.
- Build `CacheManager`, `DatabaseManager`, and wire `CacheMiddleware` into your PSR-15 stack.

### CacheManager

```php
use RediSync\Cache\CacheManager;
$cache = CacheManager::fromConfig([
  'host' => '127.0.0.1',
  'port' => 6379,
  'database' => 0,
  'prefix' => 'redisync:'
]);
$cache->set('users:1', ['id' => 1, 'name' => 'Ada'], ttl: 300);
$data = $cache->get('users:1');
$cache->delete('users:1');
```

### CLI

```
./vendor/bin/redisync clear-cache users:*
```

### Tests

```
composer test
```
