<?php

declare(strict_types=1);

namespace Hibla\Cache\Interfaces;

use Hibla\Promise\Interfaces\PromiseInterface;

interface CacheInterface
{
    /**
     * Fetches a value from the cache.
     *
     * @template TValue
     * @param string $key The unique key of this item in the cache.
     * @param TValue $default Default value to return if the key does not exist.
     * @return PromiseInterface<TValue> Resolves with the value of the item from the cache, or $default in case of cache miss.
     */
    public function get(string $key, mixed $default = null): PromiseInterface;

    /**
     * Persists data in the cache, uniquely referenced by a key with an optional expiration TTL time.
     *
     * @param string $key The key of the item to store.
     * @param mixed $value The value of the item to store. Must be serializable.
     * @param null|int|\DateInterval|float $ttl Optional. The TTL value of this item.
     * @return PromiseInterface<bool> Resolves with true on success and false on failure.
     */
    public function set(string $key, mixed $value, mixed $ttl = null): PromiseInterface;

    /**
     * Delete an item from the cache by its unique key.
     *
     * @param string $key The unique cache key of the item to delete.
     * @return PromiseInterface<bool> Resolves with true if the item was successfully removed. False if there was an error.
     */
    public function delete(string $key): PromiseInterface;

    /**
     * Wipes clean the entire cache's keys.
     *
     * @return PromiseInterface<bool> Resolves with true on success and false on failure.
     */
    public function clear(): PromiseInterface;

    /**
     * Obtains multiple cache items by their unique keys.
     *
     * @template TDefault
     * @param iterable<string> $keys A list of keys that can obtained in a single operation.
     * @param TDefault $default Default value to return for keys that do not exist.
     * @return PromiseInterface<iterable<string, TDefault>> Resolves with a list of key => value pairs.
     */
    public function getMultiple(iterable $keys, mixed $default = null): PromiseInterface;

    /**
     * Persists a set of key => value pairs in the cache, with an optional TTL.
     *
     * @param iterable<string, mixed> $values A list of key => value pairs for a multiple-set operation.
     * @param null|int|\DateInterval|float $ttl Optional. The TTL value of this item.
     * @return PromiseInterface<bool> Resolves with true on success and false on failure.
     */
    public function setMultiple(iterable $values, mixed $ttl = null): PromiseInterface;

    /**
     * Deletes multiple cache items in a single operation.
     *
     * @param iterable<string> $keys A list of string-based keys to be deleted.
     * @return PromiseInterface<bool> Resolves with true if the items were successfully removed. False if there was an error.
     */
    public function deleteMultiple(iterable $keys): PromiseInterface;

    /**
     * Determines whether an item is present in the cache.
     *
     * @param string $key The cache item key.
     * @return PromiseInterface<bool> Resolves with true if the item exists, false otherwise.
     */
    public function has(string $key): PromiseInterface;
}
