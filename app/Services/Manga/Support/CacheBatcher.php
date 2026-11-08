<?php

namespace App\Services\Manga\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Buffers many small cache writes and flushes them in one round trip.
 *
 * Providers used to call Cache::put() once per chapter (hundreds of keys).
 * With a remote Redis (Upstash) each command costs a full network round
 * trip, so a single cold aggregation could spend a minute just writing
 * chapter-url lookups. RedisStore::putMany() wraps the writes in
 * MULTI/EXEC, which collapses them into one round trip.
 */
class CacheBatcher
{
    /**
     * Pending writes grouped by TTL.
     *
     * @var array<int, array<string, mixed>>
     */
    protected static array $pending = [];

    public static function put(string $key, $value, int $ttl): void
    {
        self::$pending[$ttl][$key] = $value;
    }

    public static function flush(): void
    {
        if (empty(self::$pending)) {
            return;
        }

        $pending = self::$pending;
        self::$pending = [];

        foreach ($pending as $ttl => $values) {
            if (empty($values)) {
                continue;
            }

            try {
                Cache::putMany($values, (int) $ttl);
            } catch (\Throwable $e) {
                // Fall back to individual writes if the store cannot batch.
                foreach ($values as $key => $value) {
                    try {
                        Cache::put($key, $value, (int) $ttl);
                    } catch (\Throwable $ignored) {
                        //
                    }
                }
            }
        }
    }
}
