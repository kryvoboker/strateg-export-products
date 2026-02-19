<?php

return [
    'api_key'                            => env('OPEN_AI_API_KEY'),
    'api_model'                          => env('OPEN_AI_API_MODEL'),
    'api_temperature'                    => env('OPEN_AI_API_TEMPERATURE'),
    'api_max_tokens'                     => env('OPEN_AI_API_MAX_TOKENS'),
    'system_prompt'                      => env('OPEN_AI_SYSTEM_PROMPT'),
    'api_wait_time_seconds'              => env('OPEN_AI_API_WAIT_TIME_SECONDS'),
    'api_max_calls'                      => env('OPEN_AI_API_MAX_CALLS'),
    'api_call_counter_cache_key'         => env('OPEN_AI_CALL_COUNTER_CACHE_KEY'),
    'api_call_counter_cache_ttl_seconds' => env('OPEN_AI_CALL_COUNTER_CACHE_TTL_SECONDS'),
    'api_max_retries'                    => env('OPEN_AI_API_MAX_RETRIES'),
    'api_max_retry_wait_time_seconds'    => env('OPEN_AI_API_MAX_RETRY_WAIT_TIME_SECONDS'),
];
