<?php

declare(strict_types=1);

namespace App\Supports\Services\Ai;

use Illuminate\Support\Facades\Cache;

final class OpenAiRateLimiterService
{
    public function throttle(): void
    {
        $maxCalls = (int) config('open-ai.api_max_calls', 1);
        $waitSec = (int) config('open-ai.api_wait_time_seconds', 1);

        if ($maxCalls < 1) {
            $maxCalls = 1;
        }
        if ($waitSec < 0) {
            $waitSec = 0;
        }

        // A window of some time is enough to keep a counter between requests
        $key = config('open-ai.api_call_counter_cache_key');
        $ttl = (int) config('open-ai.api_call_counter_cache_ttl_seconds');

        $count = Cache::increment($key);

        if ($count === 1) {
            Cache::put($key, 1, $ttl);
        }

        if ($count > $maxCalls) {
            if ($waitSec > 0) {
                sleep($waitSec);
            }

            Cache::put($key, 1, $ttl);
        }
    }
}
