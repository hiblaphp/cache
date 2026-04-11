# Hibla Cache

**Async-first cache primitives for the Hibla ecosystem.**

A Promise-based cache abstraction built on top of [hiblaphp/promise](https://github.com/hiblaphp/promise) inspired by psr16 cache interface. All operations return a `Promise` rather than a raw value, making cache reads and writes first-class participants in async workflows, composable with `await()`, `Promise::all()`, and the rest of the Hibla promise API.

Ships with an in-memory `ArrayCache` implementation with LRU eviction and nanosecond-precision TTL, and a `CacheInterface` for building or swapping in other backends.

[![Latest Release](https://img.shields.io/github/release/hiblaphp/cache.svg?style=flat-square)](https://github.com/hiblaphp/cache/releases)
[![Tests](https://github.com/hiblaphp/cache/actions/workflows/test.yml/badge.svg)](https://github.com/hiblaphp/cache/actions/workflows/test.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/hiblaphp/cache.svg?style=flat-square)](https://packagist.org/packages/hiblaphp/cache)
[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](./LICENSE)

---

## Contents

**Getting started**
- [Installation](#installation)
- [Introduction](#introduction)

**ArrayCache**
- [Basic Usage](#basic-usage)
- [TTL — Expiring Items](#ttl--expiring-items)
  - [Integer and float TTL](#integer-and-float-ttl)
  - [DateInterval TTL](#dateinterval-ttl)
- [Size Limit and LRU Eviction](#size-limit-and-lru-eviction)
  - [Eviction priority](#eviction-priority)
- [Bulk Operations](#bulk-operations)
  - [`getMultiple()`](#getmultiple)
  - [`setMultiple()`](#setmultiple)
  - [`deleteMultiple()`](#deletemultiple)
- [`has()` — Existence Check](#has--existence-check)

**Async integration**
- [Using with `await()`](#using-with-await)
- [Using with Promise Combinators](#using-with-promise-combinators)
- [No Cancellation Support](#no-cancellation-support)

**Reference**
- [CacheInterface](#cacheinterface)
- [API Reference](#api-reference)

**Meta**
- [Development](#development)
- [License](#license)

---

## Installation
```bash
composer require hiblaphp/cache
```

**Requirements:**
- PHP 8.3+
- `hiblaphp/promise`

---

## Introduction

Most cache APIs in PHP return raw values directly. This works fine for synchronous code, but as soon as you want to use a cache inside an async workflow (alongside HTTP requests, database queries, or other in-flight work) a synchronous return value breaks the composition model. You cannot pass it to `Promise::all()`, you cannot `await()` it alongside other operations, and you cannot swap to a remote cache backend later without changing every call site.

`hiblaphp/cache` makes every cache operation return a `Promise`. For the built-in `ArrayCache` all operations resolve immediately. There is no actual async work happening under the hood. But the interface is identical to what a Redis or Memcached backend would expose. This means your application code composes cleanly with the rest of the Hibla async stack today, and swapping to a network-backed cache later requires no changes at the call site.

---

## Basic Usage
```php
use Hibla\Cache\ArrayCache;
use function Hibla\await;

$cache = new ArrayCache();

// Store a value
await($cache->set('user:1', $user));

// Retrieve a value — resolves with $default on cache miss
$user = await($cache->get('user:1'));

// Delete a value
await($cache->delete('user:1'));

// Clear all values
await($cache->clear());
```

All methods return a `Promise`. In a synchronous context you can use `await()` or `->wait()` to retrieve the resolved value. Inside an `async()` block use `await()` to suspend cooperatively:
```php
async(function () use ($cache) {
    $user = await($cache->get('user:1'));

    if ($user === null) {
        $user = await(fetchUserFromDatabase(1));
        await($cache->set('user:1', $user, ttl: 300));
    }

    return $user;
});
```

---

## TTL — Expiring Items

Pass a TTL as the third argument to `set()` and `setMultiple()`. Items are not actively garbage collected. Expiry is checked lazily on the next read (`get()`, `getMultiple()`, `has()`). Expired items are also prioritized for eviction when the cache is full. See [Eviction priority](#eviction-priority).

TTL uses `hrtime()` internally, a monotonic clock that is immune to system clock adjustments. This means TTLs are accurate even if the system time changes while the cache is running.

### Integer and float TTL

Pass an `int` or `float` for a TTL in seconds. Floats allow sub-second precision:
```php
// Expire after 5 minutes
await($cache->set('session:abc', $session, 300));

// Expire after 500 milliseconds
await($cache->set('rate-limit:user:1', 1, 0.5));

// Expire after 1.5 seconds
await($cache->set('lock:resource', true, 1.5));
```

### DateInterval TTL

Pass a `DateInterval` when you want to express TTL in calendar terms:
```php
// Expire after 1 hour
await($cache->set('report:daily', $report, new DateInterval('PT1H')));

// Expire after 7 days
await($cache->set('token:refresh', $token, new DateInterval('P7D')));

// Expire after 30 minutes
await($cache->set('session:tmp', $data, new DateInterval('PT30M')));
```

---

## Size Limit and LRU Eviction

Pass a `$limit` to the constructor to cap the number of items the cache will hold. When the limit is reached and a new item is added, the cache evicts one item to make room before storing the new one:
```php
// Hold at most 1000 items
$cache = new ArrayCache(limit: 1000);
```

Without a limit the cache grows without bound:
```php
// Unlimited — default
$cache = new ArrayCache();
```

The minimum valid limit is `1`. Passing `0` or a negative value throws an `\InvalidArgumentException`:
```php
$cache = new ArrayCache(limit: 0);  // throws InvalidArgumentException
$cache = new ArrayCache(limit: -1); // throws InvalidArgumentException
```

### Eviction priority

When the cache is over the limit, the eviction strategy prefers expired items over live ones:

1. **Expired item available:** the item with the earliest expiry timestamp is evicted first, regardless of when it was last accessed. Clearing out a stale item is always preferable to evicting a live one.
2. **No expired items:** the least recently used item is evicted. Read operations (`get()`, `getMultiple()`) update LRU order on each access. `has()` intentionally does not. See [`has()`](#has--existence-check).

```php
$cache = new ArrayCache(limit: 3);

await($cache->set('a', 1));
await($cache->set('b', 2, ttl: 0.001)); // expires almost immediately
await($cache->set('c', 3));

usleep(2000); // let 'b' expire

// Adding a fourth item — 'b' is evicted first because it is expired,
// even though 'a' is the least recently used live item
await($cache->set('d', 4));

$b = await($cache->get('b')); // null — evicted
$a = await($cache->get('a')); // 1 — still present
```

---

## Bulk Operations

### `getMultiple()`

Fetch multiple keys in a single call. Returns an associative array of `key => value` pairs. Keys not found in the cache resolve to `$default`:
```php
$results = await($cache->getMultiple(['user:1', 'user:2', 'user:3']));

// $results = [
//   'user:1' => $user1,   // found
//   'user:2' => null,     // not found — default
//   'user:3' => $user3,   // found
// ]
```

Pass a custom default for missing keys:
```php
$results = await($cache->getMultiple(['config:a', 'config:b'], default: []));
```

### `setMultiple()`

Store multiple key-value pairs in a single call. All entries share the same TTL:
```php
await($cache->setMultiple([
    'user:1' => $user1,
    'user:2' => $user2,
    'user:3' => $user3,
], ttl: 300));
```

### `deleteMultiple()`

Delete multiple keys in a single call:
```php
await($cache->deleteMultiple(['user:1', 'user:2', 'user:3']));
```

---

## `has()` — Existence Check

`has()` checks whether a key exists in the cache and has not expired. It returns `false` for keys that are present but have passed their TTL:
```php
$exists = await($cache->has('user:1')); // true or false
```

> **Note:** `has()` intentionally does not update LRU order. It is a lightweight existence check with no side effects. Only `get()` and `getMultiple()` promote an item to most-recently-used when the value is actually retrieved.

This means checking whether a key exists before fetching it does not disturb the eviction order. If you check for a key and then immediately get it, only the `get()` updates the LRU position:
```php
if (await($cache->has('user:1'))) {       // LRU order unchanged
    $user = await($cache->get('user:1')); // LRU order updated here
}
```

---

## Using with `await()`

`await()` is provided by [`hiblaphp/async`](https://github.com/hiblaphp/async), the async/await layer of the Hibla stack. If you have not used it before, it is a plain PHP function that suspends the current Fiber until a promise settles, or falls back to blocking synchronously when called outside a Fiber. See the [hiblaphp/async documentation](https://github.com/hiblaphp/async) for a full introduction.

All `ArrayCache` methods resolve immediately. There is no network round trip. `await()` returns the value on the same tick without suspending the Fiber:
```php
use function Hibla\async;
use function Hibla\await;

async(function () use ($cache, $userId) {
    // Cache-aside pattern — check cache, fall back to source
    $user = await($cache->get("user:$userId"));

    if ($user === null) {
        $user = await(Http::get("/users/$userId"));
        await($cache->set("user:$userId", $user, ttl: 60));
    }

    return $user;
});
```

---

## Using with Promise Combinators

Because every operation returns a `Promise`, cache calls compose naturally with `Promise::all()` and other combinators. Warm multiple cache entries or read multiple keys concurrently alongside other async work:
```php
use Hibla\Promise\Promise;
use function Hibla\await;

// Warm multiple cache entries concurrently
await(Promise::all([
    $cache->set('user:1', $user1, 300),
    $cache->set('user:2', $user2, 300),
    $cache->set('user:3', $user3, 300),
]));

// Read multiple keys concurrently alongside other async work
[$cached, $liveConfig] = await(Promise::all([
    $cache->get('user:1'),
    Http::get('/config'),
]));
```

---

## No Cancellation Support

`ArrayCache` does not support cancellation and none of its returned promises have `onCancel()` handlers registered. This is intentional, not an oversight.

Cancellation is only meaningful when there is real async work in flight: an HTTP request that can be aborted, a timer that can be cancelled, a stream read that can be stopped. A `CancellationToken` or `promise->cancel()` call is useful precisely because it can reach into that in-flight work and stop it before it completes.

`ArrayCache` operations are entirely synchronous. Every method completes its work (the array read, the LRU update, the expiry check) and calls `Promise::resolved()` before returning. The promise is already fulfilled by the time the caller receives it. There is nothing in flight to cancel:
```php
$promise = $cache->get('user:1');
// By this line, the array has already been read and the promise is
// already fulfilled. Calling cancel() on it is a no-op — the work
// is done.
$promise->cancel(); // no-op — isFulfilled() is already true
```

This is the same reason `Promise::resolved()` and `Promise::rejected()` do not support cancellation. Once a promise is already settled, its result is final and cancellation has nothing to act on.

If you are building a cache backend that performs real async I/O (a Redis client, a Memcached adapter, or any network-backed implementation of `CacheInterface`) you should register `onCancel()` handlers on the deferred promises your methods return, wiring cancellation to the underlying connection or request abort mechanism:
```php
public function get(string $key, mixed $default = null): PromiseInterface
{
    $promise   = new Promise();
    $requestId = $this->redis->get($key, function (?string $value) use ($promise, $default) {
        $promise->resolve($value !== null ? unserialize($value) : $default);
    });

    // Real async work — cancellation can actually stop something
    $promise->onCancel(function () use ($requestId) {
        $this->redis->cancel($requestId);
    });

    return $promise;
}
```

For `ArrayCache` specifically, there is simply nothing to wire cancellation to. The work is always already done.

---

## `CacheInterface`

`CacheInterface` is the contract all cache implementations in the Hibla ecosystem implement. All methods return a `PromiseInterface`. This is the only requirement that distinguishes it from a PSR-16 synchronous cache.

Type-annotate against the interface rather than a concrete class so you can swap implementations without changing call sites:
```php
use Hibla\Cache\Interfaces\CacheInterface;

class UserRepository
{
    public function __construct(private CacheInterface $cache) {}

    public function find(int $id): PromiseInterface
    {
        return async(function () use ($id) {
            $cached = await($this->cache->get("user:$id"));

            if ($cached !== null) {
                return $cached;
            }

            $user = await($this->db->query("SELECT * FROM users WHERE id = ?", $id));
            await($this->cache->set("user:$id", $user, ttl: 300));

            return $user;
        });
    }
}

// Use ArrayCache in development, swap to RedisCache in production —
// UserRepository does not change
$repo = new UserRepository(new ArrayCache(limit: 500));
```

### Implementing your own backend

Implement `CacheInterface` to add a new backend. All methods must return a `PromiseInterface`. Use `Promise::resolved()` for synchronous results and a deferred `Promise` for genuinely async operations. Register `onCancel()` handlers on any deferred promises that wrap real in-flight work:
```php
use Hibla\Cache\Interfaces\CacheInterface;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

class RedisCache implements CacheInterface
{
    public function get(string $key, mixed $default = null): PromiseInterface
    {
        $promise   = new Promise();
        $requestId = $this->redis->get($key, function (?string $value) use ($promise, $default) {
            $promise->resolve($value !== null ? unserialize($value) : $default);
        });

        $promise->onCancel(function () use ($requestId) {
            $this->redis->cancel($requestId);
        });

        return $promise;
    }

    // ... implement remaining methods
}
```

---

## API Reference

### `ArrayCache`

| Method | Description |
|---|---|
| `__construct(?int $limit = null)` | Create a cache. Pass a limit to cap the number of items. Null for unlimited. Throws `\InvalidArgumentException` if limit is less than 1. |
| `get(string $key, mixed $default = null): PromiseInterface` | Resolve with the cached value, or `$default` on miss. Checks expiry lazily. Updates LRU order on hit. |
| `set(string $key, mixed $value, mixed $ttl = null): PromiseInterface` | Store a value. TTL accepts `int`, `float`, or `DateInterval`. Resolves with `true`. Evicts if over limit. |
| `delete(string $key): PromiseInterface` | Remove a key. Resolves with `true`. |
| `clear(): PromiseInterface` | Remove all keys. Resolves with `true`. |
| `getMultiple(iterable $keys, mixed $default = null): PromiseInterface` | Fetch multiple keys. Resolves with `array<string, mixed>`. Missing keys resolve to `$default`. Updates LRU order on each hit. |
| `setMultiple(iterable $values, mixed $ttl = null): PromiseInterface` | Store multiple key-value pairs with a shared TTL. Resolves with `true`. |
| `deleteMultiple(iterable $keys): PromiseInterface` | Delete multiple keys. Resolves with `true`. |
| `has(string $key): PromiseInterface` | Resolve with `true` if the key exists and has not expired. Does NOT update LRU order. |

### TTL types

| Type | Example | Behavior |
|---|---|---|
| `null` | `set('k', $v)` | No expiry, item lives until evicted or deleted |
| `int` | `set('k', $v, 300)` | Expires after N seconds |
| `float` | `set('k', $v, 0.5)` | Expires after N seconds, sub-second precision |
| `DateInterval` | `set('k', $v, new DateInterval('PT1H'))` | Expires after the interval |

---

## Development
```bash
git clone https://github.com/hiblaphp/cache.git
cd cache
composer install
```
```bash
./vendor/bin/pest
```
```bash
./vendor/bin/phpstan analyse
```

---

## License

MIT License. See [LICENSE](./LICENSE) for more information.