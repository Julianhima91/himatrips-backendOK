<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheapestDateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $requests;

    /**
     * Create a new job instance.
     */
    public function __construct($requests)
    {
        $this->requests = $requests;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        foreach ($this->requests as $request) {
            Log::info('Request');
        }
    }
}
