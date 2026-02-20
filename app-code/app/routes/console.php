<?php

declare(strict_types=1);

use App\Console\Commands\ProcessQueueOnceCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(ProcessQueueOnceCommand::class)
    ->everyMinute() // Run every minute. If CRON is set to run every minute, this will check the queue every minute and process jobs if there are any.
    ->withoutOverlapping(181); // The process will not start if the previous one is still running. The lock will be released after 181 minutes (3.01 hours) to prevent deadlocks in case of unexpected failures.
