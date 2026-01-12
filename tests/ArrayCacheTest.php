<?php

declare(strict_types=1);

use Hibla\Cache\ArrayCache;
use Hibla\Promise\Interfaces\PromiseInterface;

describe('Array Cache', function () {
    describe('constructor', function () {
        it('creates cache without limit by default', function () {
            $cache = new ArrayCache();

            expect($cache)->toBeInstanceOf(ArrayCache::class);
        });

        it('creates cache with specified limit', function () {
            $cache = new ArrayCache(5);

            expect($cache)->toBeInstanceOf(ArrayCache::class);
        });

        it('throws exception for zero limit', function () {
            expect(fn () => new ArrayCache(0))
                ->toThrow(InvalidArgumentException::class, 'Cache limit must be at least 1')
            ;
        });

        it('throws exception for negative limit', function () {
            expect(fn () => new ArrayCache(-5))
                ->toThrow(InvalidArgumentException::class, 'Cache limit must be at least 1')
            ;
        });
    });

    describe('get', function () {
        it('returns default value when key does not exist', function () {
            $cache = new ArrayCache();
            $result = $cache->get('nonexistent', 'default')->wait();

            expect($result)->toBe('default');
        });

        it('returns stored value when key exists', function () {
            $cache = new ArrayCache();
            $cache->set('key', 'value')->wait();
            $result = $cache->get('key')->wait();

            expect($result)->toBe('value');
        });

        it('returns default value when item has expired', function () {
            $cache = new ArrayCache();
            $cache->set('key', 'value', 0.001)->wait();
            usleep(2000);

            $result = $cache->get('key', 'default')->wait();

            expect($result)->toBe('default');
        });

        it('returns promise interface', function () {
            $cache = new ArrayCache();
            $promise = $cache->get('key');

            expect($promise)->toBeInstanceOf(PromiseInterface::class);
        });

        it('handles null default value', function () {
            $cache = new ArrayCache();
            $result = $cache->get('nonexistent')->wait();

            expect($result)->toBeNull();
        });

        it('handles different data types', function () {
            $cache = new ArrayCache();
            $testData = [
                'string' => 'test',
                'int' => 42,
                'float' => 3.14,
                'array' => ['a', 'b', 'c'],
                'object' => (object)['prop' => 'value'],
                'null' => null,
                'bool' => true,
            ];

            foreach ($testData as $key => $value) {
                $cache->set($key, $value)->wait();
                $result = $cache->get($key)->wait();

                expect($result)->toBe($value);
            }
        });

        it('updates LRU order when accessed', function () {
            $cache = new ArrayCache(3);

            $cache->set('key1', 'value1')->wait();
            $cache->set('key2', 'value2')->wait();
            $cache->set('key3', 'value3')->wait();

            // Access key1, making it most recently used
            $cache->get('key1')->wait();

            // Add key4, should evict key2 (least recently used)
            $cache->set('key4', 'value4')->wait();

            expect($cache->get('key1')->wait())->toBe('value1');
            expect($cache->get('key2', 'default')->wait())->toBe('default');
            expect($cache->get('key3')->wait())->toBe('value3');
            expect($cache->get('key4')->wait())->toBe('value4');
        });
    });

    describe('set', function () {
        it('stores value without expiry', function () {
            $cache = new ArrayCache();
            $result = $cache->set('key', 'value')->wait();

            expect($result)->toBeTrue();
            expect($cache->get('key')->wait())->toBe('value');
        });

        it('stores value with integer TTL in seconds', function () {
            $cache = new ArrayCache();
            $cache->set('key', 'value', 1)->wait();

            expect($cache->get('key')->wait())->toBe('value');
        });

        it('stores value with float TTL in seconds', function () {
            $cache = new ArrayCache();
            $cache->set('key', 'value', 0.5)->wait();

            expect($cache->get('key')->wait())->toBe('value');
        });

        it('stores value with DateInterval TTL', function () {
            $cache = new ArrayCache();
            $interval = new DateInterval('PT1H');
            $cache->set('key', 'value', $interval)->wait();

            expect($cache->get('key')->wait())->toBe('value');
        });

        it('overwrites existing value', function () {
            $cache = new ArrayCache();
            $cache->set('key', 'value1')->wait();
            $cache->set('key', 'value2')->wait();

            expect($cache->get('key')->wait())->toBe('value2');
        });

        it('respects cache limit using LRU eviction', function () {
            $cache = new ArrayCache(3);

            $cache->set('key1', 'value1')->wait();
            $cache->set('key2', 'value2')->wait();
            $cache->set('key3', 'value3')->wait();
            $cache->set('key4', 'value4')->wait();

            // key1 should be evicted (least recently used)
            expect($cache->get('key1', 'default')->wait())->toBe('default');
            expect($cache->get('key2')->wait())->toBe('value2');
            expect($cache->get('key3')->wait())->toBe('value3');
            expect($cache->get('key4')->wait())->toBe('value4');
        });

        it('does not count overwrite against limit', function () {
            $cache = new ArrayCache(2);

            $cache->set('key1', 'value1')->wait();
            $cache->set('key2', 'value2')->wait();
            $cache->set('key1', 'updated')->wait();

            expect($cache->get('key1')->wait())->toBe('updated');
            expect($cache->get('key2')->wait())->toBe('value2');
        });

        it('updates LRU position when setting existing key', function () {
            $cache = new ArrayCache(3);

            $cache->set('key1', 'value1')->wait();
            $cache->set('key2', 'value2')->wait();
            $cache->set('key3', 'value3')->wait();

            // Update key1, making it most recently used
            $cache->set('key1', 'updated')->wait();

            // Add key4, should evict key2 (now least recently used)
            $cache->set('key4', 'value4')->wait();

            expect($cache->get('key1')->wait())->toBe('updated');
            expect($cache->get('key2', 'default')->wait())->toBe('default');
            expect($cache->get('key3')->wait())->toBe('value3');
            expect($cache->get('key4')->wait())->toBe('value4');
        });

        it('prioritizes evicting expired items over LRU', function () {
            $cache = new ArrayCache(3);

            $cache->set('key1', 'value1')->wait();
            $cache->set('key2', 'value2', 0.001)->wait(); // Will expire soon
            $cache->set('key3', 'value3')->wait();

            usleep(2000); // Wait for key2 to expire

            // Add key4, should evict expired key2 instead of key1 (LRU)
            $cache->set('key4', 'value4')->wait();

            expect($cache->get('key1')->wait())->toBe('value1');
            expect($cache->get('key2', 'default')->wait())->toBe('default');
            expect($cache->get('key3')->wait())->toBe('value3');
            expect($cache->get('key4')->wait())->toBe('value4');
        });
    });

    describe('delete', function () {
        it('removes existing key', function () {
            $cache = new ArrayCache();
            $cache->set('key', 'value')->wait();
            $result = $cache->delete('key')->wait();

            expect($result)->toBeTrue();
            expect($cache->get('key', 'default')->wait())->toBe('default');
        });

        it('returns true even when key does not exist', function () {
            $cache = new ArrayCache();
            $result = $cache->delete('nonexistent')->wait();

            expect($result)->toBeTrue();
        });

        it('returns promise interface', function () {
            $cache = new ArrayCache();
            $promise = $cache->delete('key');

            expect($promise)->toBeInstanceOf(PromiseInterface::class);
        });

        it('removes both data and expiration info', function () {
            $cache = new ArrayCache();
            $cache->set('key', 'value', 10)->wait();
            $cache->delete('key')->wait();

            expect($cache->has('key')->wait())->toBeFalse();
        });
    });

    describe('clear', function () {
        it('removes all items from cache', function () {
            $cache = new ArrayCache();
            $cache->set('key1', 'value1')->wait();
            $cache->set('key2', 'value2')->wait();
            $cache->set('key3', 'value3')->wait();

            $result = $cache->clear()->wait();

            expect($result)->toBeTrue();
            expect($cache->get('key1', 'default')->wait())->toBe('default');
            expect($cache->get('key2', 'default')->wait())->toBe('default');
            expect($cache->get('key3', 'default')->wait())->toBe('default');
        });

        it('works on empty cache', function () {
            $cache = new ArrayCache();
            $result = $cache->clear()->wait();

            expect($result)->toBeTrue();
        });

        it('clears both data and expiration info', function () {
            $cache = new ArrayCache();
            $cache->set('key1', 'value1', 10)->wait();
            $cache->set('key2', 'value2')->wait();

            $cache->clear()->wait();

            expect($cache->has('key1')->wait())->toBeFalse();
            expect($cache->has('key2')->wait())->toBeFalse();
        });
    });

    describe('getMultiple', function () {
        it('returns multiple values with existing keys', function () {
            $cache = new ArrayCache();
            $cache->set('key1', 'value1')->wait();
            $cache->set('key2', 'value2')->wait();
            $cache->set('key3', 'value3')->wait();

            $result = $cache->getMultiple(['key1', 'key2', 'key3'])->wait();

            expect($result)->toBe([
                'key1' => 'value1',
                'key2' => 'value2',
                'key3' => 'value3',
            ]);
        });

        it('returns default for missing keys', function () {
            $cache = new ArrayCache();
            $cache->set('key1', 'value1')->wait();

            $result = $cache->getMultiple(['key1', 'key2'], 'default')->wait();

            expect($result)->toBe([
                'key1' => 'value1',
                'key2' => 'default',
            ]);
        });

        it('returns default for expired keys', function () {
            $cache = new ArrayCache();
            $cache->set('key1', 'value1', 0.001)->wait();
            $cache->set('key2', 'value2')->wait();
            usleep(2000);

            $result = $cache->getMultiple(['key1', 'key2'], 'default')->wait();

            expect($result)->toBe([
                'key1' => 'default',
                'key2' => 'value2',
            ]);
        });

        it('handles empty keys array', function () {
            $cache = new ArrayCache();
            $result = $cache->getMultiple([])->wait();

            expect($result)->toBe([]);
        });

        it('updates LRU order for all accessed keys', function () {
            $cache = new ArrayCache(4);

            $cache->set('key1', 'value1')->wait();
            $cache->set('key2', 'value2')->wait();
            $cache->set('key3', 'value3')->wait();
            $cache->set('key4', 'value4')->wait();

            // Access key1 and key2, making them most recently used
            $cache->getMultiple(['key1', 'key2'])->wait();

            // Add key5, should evict key3 (least recently used)
            $cache->set('key5', 'value5')->wait();

            expect($cache->get('key1')->wait())->toBe('value1');
            expect($cache->get('key2')->wait())->toBe('value2');
            expect($cache->get('key3', 'default')->wait())->toBe('default');
            expect($cache->get('key4')->wait())->toBe('value4');
            expect($cache->get('key5')->wait())->toBe('value5');
        });
    });

    describe('setMultiple', function () {
        it('stores multiple values without TTL', function () {
            $cache = new ArrayCache();
            $values = [
                'key1' => 'value1',
                'key2' => 'value2',
                'key3' => 'value3',
            ];

            $result = $cache->setMultiple($values)->wait();

            expect($result)->toBeTrue();
            expect($cache->get('key1')->wait())->toBe('value1');
            expect($cache->get('key2')->wait())->toBe('value2');
            expect($cache->get('key3')->wait())->toBe('value3');
        });

        it('stores multiple values with TTL', function () {
            $cache = new ArrayCache();
            $values = [
                'key1' => 'value1',
                'key2' => 'value2',
            ];

            $cache->setMultiple($values, 1)->wait();

            expect($cache->get('key1')->wait())->toBe('value1');
            expect($cache->get('key2')->wait())->toBe('value2');
        });

        it('respects cache limit with LRU eviction', function () {
            $cache = new ArrayCache(2);

            $values = [
                'key1' => 'value1',
                'key2' => 'value2',
                'key3' => 'value3',
            ];

            $cache->setMultiple($values)->wait();

            // key1 should be evicted (least recently used)
            expect($cache->get('key1', 'default')->wait())->toBe('default');
            expect($cache->get('key2')->wait())->toBe('value2');
            expect($cache->get('key3')->wait())->toBe('value3');
        });
    });

    describe('deleteMultiple', function () {
        it('removes multiple keys', function () {
            $cache = new ArrayCache();
            $cache->set('key1', 'value1')->wait();
            $cache->set('key2', 'value2')->wait();
            $cache->set('key3', 'value3')->wait();

            $result = $cache->deleteMultiple(['key1', 'key3'])->wait();

            expect($result)->toBeTrue();
            expect($cache->get('key1', 'default')->wait())->toBe('default');
            expect($cache->get('key2')->wait())->toBe('value2');
            expect($cache->get('key3', 'default')->wait())->toBe('default');
        });

        it('handles non-existent keys gracefully', function () {
            $cache = new ArrayCache();
            $result = $cache->deleteMultiple(['nonexistent1', 'nonexistent2'])->wait();

            expect($result)->toBeTrue();
        });

        it('handles empty keys array', function () {
            $cache = new ArrayCache();
            $result = $cache->deleteMultiple([])->wait();

            expect($result)->toBeTrue();
        });
    });

    describe('has', function () {
        it('returns true when key exists', function () {
            $cache = new ArrayCache();
            $cache->set('key', 'value')->wait();

            $result = $cache->has('key')->wait();

            expect($result)->toBeTrue();
        });

        it('returns false when key does not exist', function () {
            $cache = new ArrayCache();
            $result = $cache->has('nonexistent')->wait();

            expect($result)->toBeFalse();
        });

        it('returns false when key has expired', function () {
            $cache = new ArrayCache();
            $cache->set('key', 'value', 0.001)->wait();
            usleep(2000);

            $result = $cache->has('key')->wait();

            expect($result)->toBeFalse();
        });

        it('removes expired key from cache', function () {
            $cache = new ArrayCache();
            $cache->set('key', 'value', 0.001)->wait();
            usleep(2000);

            $cache->has('key')->wait();

            expect($cache->get('key', 'default')->wait())->toBe('default');
        });

        it('does NOT update LRU order when checking existence', function () {
            $cache = new ArrayCache(3);

            $cache->set('key1', 'value1')->wait();
            $cache->set('key2', 'value2')->wait();
            $cache->set('key3', 'value3')->wait();

            // Check key1 existence (should NOT update LRU)
            $cache->has('key1')->wait();

            // Add key4, key1 should still be evicted (it's still least recently used)
            $cache->set('key4', 'value4')->wait();

            expect($cache->get('key1', 'default')->wait())->toBe('default');
            expect($cache->get('key2')->wait())->toBe('value2');
            expect($cache->get('key3')->wait())->toBe('value3');
            expect($cache->get('key4')->wait())->toBe('value4');
        });
    });

    describe('TTL and expiration', function () {
        it('expires item after TTL with integer seconds', function () {
            $cache = new ArrayCache();
            $cache->set('key', 'value', 0.01)->wait();

            expect($cache->has('key')->wait())->toBeTrue();

            usleep(15000);

            expect($cache->has('key')->wait())->toBeFalse();
        });

        it('expires item after TTL with float seconds', function () {
            $cache = new ArrayCache();
            $cache->set('key', 'value', 0.005)->wait();

            expect($cache->has('key')->wait())->toBeTrue();

            usleep(10000);

            expect($cache->has('key')->wait())->toBeFalse();
        });

        it('handles DateInterval correctly', function () {
            $cache = new ArrayCache();
            $interval = new DateInterval('PT2S');
            $cache->set('key', 'value', $interval)->wait();

            expect($cache->has('key')->wait())->toBeTrue();

            sleep(1);
            expect($cache->has('key')->wait())->toBeTrue();
        });

        it('keeps item indefinitely when TTL is null', function () {
            $cache = new ArrayCache();
            $cache->set('key', 'value', null)->wait();

            usleep(10000);

            expect($cache->has('key')->wait())->toBeTrue();
            expect($cache->get('key')->wait())->toBe('value');
        });
    });

    describe('LRU eviction behavior', function () {
        it('evicts least recently used item when limit reached', function () {
            $cache = new ArrayCache(3);

            $cache->set('key1', 'value1')->wait();
            $cache->set('key2', 'value2')->wait();
            $cache->set('key3', 'value3')->wait();

            // All three items are in cache
            expect($cache->has('key1')->wait())->toBeTrue();
            expect($cache->has('key2')->wait())->toBeTrue();
            expect($cache->has('key3')->wait())->toBeTrue();

            // Add fourth item, key1 should be evicted
            $cache->set('key4', 'value4')->wait();

            expect($cache->has('key1')->wait())->toBeFalse();
            expect($cache->has('key2')->wait())->toBeTrue();
            expect($cache->has('key3')->wait())->toBeTrue();
            expect($cache->has('key4')->wait())->toBeTrue();
        });

        it('maintains correct order after multiple accesses', function () {
            $cache = new ArrayCache(3);

            $cache->set('key1', 'value1')->wait();
            $cache->set('key2', 'value2')->wait();
            $cache->set('key3', 'value3')->wait();

            // Access in specific order
            $cache->get('key2')->wait(); // key2 is now most recent
            $cache->get('key1')->wait(); // key1 is now most recent

            // Add key4, key3 should be evicted (least recently used)
            $cache->set('key4', 'value4')->wait();

            expect($cache->has('key1')->wait())->toBeTrue();
            expect($cache->has('key2')->wait())->toBeTrue();
            expect($cache->has('key3')->wait())->toBeFalse();
            expect($cache->has('key4')->wait())->toBeTrue();
        });

        it('handles limit of 1 correctly with LRU', function () {
            $cache = new ArrayCache(1);

            $cache->set('key1', 'value1')->wait();
            expect($cache->get('key1')->wait())->toBe('value1');

            $cache->set('key2', 'value2')->wait();
            expect($cache->get('key1', 'default')->wait())->toBe('default');
            expect($cache->get('key2')->wait())->toBe('value2');
        });
    });

    describe('edge cases', function () {
        it('handles very large cache limit', function () {
            $cache = new ArrayCache(1000000);

            $cache->set('key', 'value')->wait();

            expect($cache->get('key')->wait())->toBe('value');
        });

        it('has() does not interfere with LRU order', function () {
            $cache = new ArrayCache(3);

            $cache->set('key1', 'value1')->wait();
            $cache->set('key2', 'value2')->wait();
            $cache->set('key3', 'value3')->wait();

            // Multiple has() calls should not affect LRU order
            $cache->has('key1')->wait();
            $cache->has('key1')->wait();
            $cache->has('key1')->wait();

            // Add key4, key1 should still be evicted
            $cache->set('key4', 'value4')->wait();

            expect($cache->has('key1')->wait())->toBeFalse();
            expect($cache->has('key2')->wait())->toBeTrue();
            expect($cache->has('key3')->wait())->toBeTrue();
            expect($cache->has('key4')->wait())->toBeTrue();
        });
    });
});
