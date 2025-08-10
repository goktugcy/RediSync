# RediSync

Smart caching middleware for PHP using Redis and MySQL/PostgreSQL.

## ✨ Features

- PSR-15 middleware: automatic HTTP cache hit/miss flow.
- PSR-7/17 support via nyholm/psr7.
- GET-only caching by default, optional bypass with the X-Bypass-Cache header.
- Safe caching via status whitelist (default: [200]) and Content-Type allow list.
- TTL map by path pattern/regex for per-endpoint TTL control.
- CLI for cache ops: clear-cache, list-keys, key-info, warmup.
- Doctrine DBAL-based DatabaseManager with invalidation hooks.

## 🔧 Install

Add to your project:

```bash
composer require redisync/core
```

Requirements: PHP 8.1+, Redis (via Predis), optional DB drivers pdo_mysql/pdo_pgsql.

## ⚙️ Configuration

Configure programmatically (ENV not required):

```php
use RediSync\Cache\CacheManager;

$cache = CacheManager::fromConfig([
  'host' => '127.0.0.1',
  'port' => 6379,
  'database' => 0,
  'prefix' => 'redisync:'
]);
```

DatabaseManager (optional):

```php
use RediSync\Database\DatabaseManager;

$db = DatabaseManager::fromDsn('mysql://user:pass@127.0.0.1:3306/app?charset=utf8mb4');

// Cache invalidation after data changes
$db->onInvalidate(function (string $sql, array $params) use ($cache) {
  if (str_starts_with(strtoupper(ltrim($sql)), 'UPDATE USERS')
    || str_starts_with(strtoupper(ltrim($sql)), 'DELETE FROM USERS')
    || str_starts_with(strtoupper(ltrim($sql)), 'INSERT INTO USERS')
  ) {
    $cache->clearByPattern('users:*');
  }
});
```

## 🧩 Middleware Usage

```php
use Nyholm\Psr7\Factory\Psr17Factory;
use RediSync\Middleware\CacheMiddleware;
use RediSync\Utils\KeyGenerator;

$psr17 = new Psr17Factory();

$middleware = new CacheMiddleware(
  cache: $cache,
  keys: new KeyGenerator('http', ignoredParams: ['nonce', '_ts']),
  ttl: 300,
  responseFactory: $psr17,
  streamFactory: $psr17,
  statusWhitelist: [200],
  allowedContentTypes: ['application/json'],
  ttlMap: [
    '/public/*' => 60,
    '#^/users/\\d+$#' => 300,
  ],
);

// Add it to your PSR-15 stack (Mezzio, Slim, etc.). Middleware caches only GET requests.
// To bypass caching: send header X-Bypass-Cache: 1
```

## 🔑 Key Strategy

KeyGenerator builds a deterministic cache key:

- Method + path + sorted query params
- Use ignoredParams to exclude volatile params (e.g., nonce, \_ts)

```php
$keys = new KeyGenerator('http', ignoredParams: ['nonce']);
```

## 🧰 CLI

```bash
./vendor/bin/redisync clear-cache 'users:*'
./vendor/bin/redisync list-keys 'users:*' 50
./vendor/bin/redisync key-info 'users:1'
printf '%s\n' 'warm:key:1' 'warm:key:2' | ./vendor/bin/redisync warmup 120
```

## 🧪 Tests & Quality

```bash
composer test
composer stan
composer lint
```

## 🔌 Framework Integration (Laravel example)

Use a Service Provider to reuse Laravel’s Redis/DB config:

```php
$this->app->singleton(RediSync\Cache\CacheManager::class, function () {
  $r = config('database.redis.default');
  return RediSync\Cache\CacheManager::fromConfig([
    'host' => $r['host'] ?? '127.0.0.1',
    'port' => $r['port'] ?? 6379,
    'password' => $r['password'] ?? null,
    'database' => $r['database'] ?? 0,
    'prefix' => 'redisync:',
  ]);
});
```

## 📝 Notes

- Middleware caches only GET requests by default.
- Use status whitelist and Content-Type filters for safe caching.
- TTL map allows per-path TTL control.
- Before publishing to Packagist, consider adding CI (PHPUnit, PHPStan, CS-Fixer).
