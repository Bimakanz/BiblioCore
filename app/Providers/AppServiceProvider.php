<?php

namespace App\Providers;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->ensureCacheStoreIsUsable();
    }

    /**
     * Vercel deployments default to Redis, but a fresh project often has no
     * Upstash/Redis credentials attached. When the Redis host is missing or
     * unreachable every Cache:: call throws, the catalogue silently falls back
     * to the mock data and all covers turn into placeholders. Detect that case
     * once and use the /tmp file cache instead of breaking the whole site.
     */
    protected function ensureCacheStoreIsUsable(): void
    {
        if (config('cache.default') !== 'redis') {
            return;
        }

        $hasClient = extension_loaded('redis') || class_exists(\Predis\Client::class);

        $redis = (array) config('database.redis.default', []);
        $url = trim((string) ($redis['url'] ?? ''));
        $host = trim((string) ($redis['host'] ?? ''));
        $port = (int) ($redis['port'] ?? 6379);

        if ($url !== '') {
            $parsedHost = parse_url($url, PHP_URL_HOST);
            $parsedPort = parse_url($url, PHP_URL_PORT);

            if (is_string($parsedHost) && $parsedHost !== '') {
                $host = $parsedHost;
            }
            if (is_int($parsedPort)) {
                $port = $parsedPort;
            }
        }

        if (! $hasClient || $host === '') {
            $this->useFileCache();
            return;
        }

        // A short TCP probe avoids paying Redis' full connection timeout on
        // every request when the database behind the URL is gone.
        $reachable = false;

        if (function_exists('fsockopen')) {
            try {
                $socket = @fsockopen('tcp://' . $host, $port, $errno, $errstr, 0.6);
                $reachable = $socket !== false;

                if ($socket !== false) {
                    fclose($socket);
                }
            } catch (\Throwable $e) {
                $reachable = false;
            }
        }

        if (! $reachable) {
            $this->useFileCache();
        }
    }

    protected function useFileCache(): void
    {
        config(['cache.default' => 'file']);

        Log::warning('Redis cache is not reachable; falling back to the file cache store.');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Carbon::setLocale('id');
        setlocale(LC_TIME, 'id_ID.UTF-8');
    }
}
