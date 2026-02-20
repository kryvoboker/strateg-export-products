<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class ProcessQueueOnceCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:process-queue-once-command';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process the queue once and then exit. This is useful for running a single worker process in environments where you want to control the lifecycle of the worker, such as in a containerized environment.';

    public function handle(): int
    {
        $this->info('Starting queue processing...');

        $exit_code = Artisan::call('queue:work', [
            '--stop-when-empty' => true,
            '--tries'           => 1,
            '--max-jobs'        => 1000, // Optional: Limit the number of jobs processed in one run to prevent long-running processes
            '--max-time'        => 10800, // Maximum total execution time (3 hours)
        ]);

        if ($exit_code === 0) {
            $this->info('Queue processing completed successfully.');
        } else {
            $this->error('Queue processing failed with exit code: '.$exit_code);
        }

        return $exit_code;
    }
}
