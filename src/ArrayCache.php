<?php

declare(strict_types=1);

namespace Hibla\Cache;

use DateInterval;
use DateTimeImmutable;
use Hibla\Cache\Interfaces\CacheInterface;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

class ArrayCache implements CacheInterface
{
    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    /**
     * @var array<string, int>
     */
    private array $expires = [];

    private ?int $limit;

    /**
     * @var (callable(string, mixed): void)|null
     */
    private $onEvict;

    /**
     * Simple array-based cache with LRU eviction policy.
     *
     * @param int|null $limit Maximum number of items to keep in cache.
     *                        When limit is reached, least recently used items are evicted (LRU).
     *                        Expired items are prioritized for eviction before LRU items.
     *                        Null for unlimited cache.
     * @param (callable(string, mixed): void)|null $onEvict Optional callback executed when an item is
     *                                                      automatically evicted due to TTL expiration or capacity limits.
     *                                                      Receives the evicted key and value.
     * @throws \InvalidArgumentException If limit is less than 1
     */
    public function __construct(?int $limit = null, ?callable $onEvict = null)
    {
        if ($limit !== null && $limit < 1) {
            throw new \InvalidArgumentException(
                "Cache limit must be at least 1 or null for unlimited. Got: {$limit}"
            );
        }

        $this->limit = $limit;
        $this->onEvict = $onEvict;
    }

    /**
     * @template TValue
     *
     * @inheritDoc
     *
     * @param string $key The unique key of this item in the cache.
     * @param TValue $default Default value to return if the key does not exist.
     * @return PromiseInterface<TValue> Resolves with the value of the item from the cache, or $default in case of cache miss.
     */
    public function get(string $key, mixed $default = null): PromiseInterface
    {
        if (isset($this->expires[$key]) && hrtime(true) > $this->expires[$key]) {
            $this->triggerEviction($key);
        }

        if (! \array_key_exists($key, $this->data)) {
            return Promise::resolved($default);
        }

        $value = $this->data[$key];
        unset($this->data[$key]);
        $this->data[$key] = $value;

        /** @var PromiseInterface<TValue> */
        return Promise::resolved($value);
    }

    /**
     * {@inheritDoc}
     */
    public function set(string $key, mixed $value, mixed $ttl = null): PromiseInterface
    {
        unset($this->data[$key]);
        $this->data[$key] = $value;

        unset($this->expires[$key]);
        if ($ttl !== null) {
            $expiresAt = $this->calculateExpiry($ttl);
            if ($expiresAt !== null) {
                $this->expires[$key] = $expiresAt;
                \asort($this->expires);
            }
        }

        if ($this->limit !== null && \count($this->data) > $this->limit) {
            \reset($this->expires);
            $expiredKey = \key($this->expires);

            if ($expiredKey === null || hrtime(true) < $this->expires[$expiredKey]) {
                \reset($this->data);
                $expiredKey = \key($this->data);
            }

            if ($expiredKey !== null) {
                $this->triggerEviction($expiredKey);
            }
        }

        return Promise::resolved(true);
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $key): PromiseInterface
    {
        unset($this->data[$key], $this->expires[$key]);

        return Promise::resolved(true);
    }

    /**
     * {@inheritDoc}
     */
    public function clear(): PromiseInterface
    {
        $this->data = [];
        $this->expires = [];

        return Promise::resolved(true);
    }

    /**
     * @template TDefault
     *
     * @inheritDoc
     *
     * @param iterable<string> $keys A list of keys that can obtained in a single operation.
     * @param TDefault $default Default value to return for keys that do not exist.
     * @return PromiseInterface<iterable<string, TDefault>> Resolves with a list of key => value pairs.
     */
    public function getMultiple(iterable $keys, mixed $default = null): PromiseInterface
    {
        /** @var array<string, TDefault> */
        $result = [];
        $now = hrtime(true);

        foreach ($keys as $key) {
            if (isset($this->expires[$key]) && $now > $this->expires[$key]) {
                $this->triggerEviction($key);
            }

            if (! \array_key_exists($key, $this->data)) {
                $result[$key] = $default;

                continue;
            }

            $value = $this->data[$key];
            unset($this->data[$key]);
            $this->data[$key] = $value;

            /** @var TDefault */
            $typedValue = $value;
            $result[$key] = $typedValue;
        }

        /** @var iterable<string, TDefault> $iterableResult */
        $iterableResult = $result;

        return Promise::resolved($iterableResult);
    }

    /**
     * {@inheritDoc}
     */
    public function setMultiple(iterable $values, mixed $ttl = null): PromiseInterface
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return Promise::resolved(true);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteMultiple(iterable $keys): PromiseInterface
    {
        foreach ($keys as $key) {
            unset($this->data[$key], $this->expires[$key]);
        }

        return Promise::resolved(true);
    }

    /**
     * {@inheritDoc}
     */
    public function has(string $key): PromiseInterface
    {
        if (isset($this->expires[$key]) && hrtime(true) > $this->expires[$key]) {
            $this->triggerEviction($key);
        }

        if (! \array_key_exists($key, $this->data)) {
            return Promise::resolved(false);
        }

        // NOTE: Intentionally does NOT update LRU order.
        // Following standard cache semantics where has()/containsKey()
        // is a lightweight existence check without side effects.
        // Only get() and getMultiple() update LRU when values are actually retrieved.

        return Promise::resolved(true);
    }

    /**
     * Removes an item from the cache and triggers the eviction hook.
     */
    private function triggerEviction(string $key): void
    {
        if (! \array_key_exists($key, $this->data)) {
            return;
        }

        $value = $this->data[$key];
        unset($this->data[$key], $this->expires[$key]);

        if ($this->onEvict !== null) {
            ($this->onEvict)($key, $value);
        }
    }

    /**
     * Helper to normalize TTL to a monotonic high-resolution timestamp (nanoseconds).
     *
     * @return int|null Timestamp in nanoseconds or null if no expiry
     */
    private function calculateExpiry(mixed $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        $current = hrtime(true);

        if ($ttl instanceof DateInterval) {
            $now = new DateTimeImmutable();
            $seconds = (float) $now->add($ttl)->format('U.u') - (float) $now->format('U.u');

            return $current + (int) ($seconds * 1_000_000_000);
        }

        if (\is_int($ttl) || \is_float($ttl)) {
            return $current + (int) ($ttl * 1_000_000_000);
        }

        return null;
    }
}
