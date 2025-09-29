<?php

namespace App\Jobs;

use App\Http\Integrations\GoFlightIntegration\Requests\RetrieveFlightsApi3Request;
use Exception;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class LiveSearchFlightsApi3 implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $date;

    protected $origin;

    protected $destination;

    public int $tries = 2;

    protected $adults;

    protected $children;

    protected $infants;

    public $batchId;

    protected $return_date;

    /**
     * Create a new job instance.
     */
    public function __construct($date, $return_date, $origin_airport, $destination_airport, $adults, $children, $infants, $batchId)
    {
        $this->date = $date;
        $this->return_date = $return_date;
        $this->origin = $origin_airport;
        $this->destination = $destination_airport;
        $this->adults = $adults;
        $this->children = $children;
        $this->infants = $infants;
        $this->batchId = $batchId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (Cache::get("job_completed_{$this->batchId}")) {
            $this->delete();

            return;
        }

        $request = new RetrieveFlightsApi3Request(
            departure_airport_code: $this->origin->codeIataAirport,
            arrival_airport_code: $this->destination->codeIataAirport,
            departure_date: $this->date,
            arrival_date: $this->return_date,
            number_of_adults: $this->adults,
            number_of_childrens: $this->children,
            number_of_infants: $this->infants,
        );

        try {
            $response = $request->send();
        } catch (\Exception $e) {
            if ($this->attempts() == 1) {
                $this->release(1);
            }

            $this->fail($e);
        }

        $logger = Log::channel('livesearch');

        try {
            $itineraries = $response->dtoOrFail();

            $logger->info("$this->batchId API 3 ITINERARIES COUNT: ".count($itineraries));
            if ($itineraries->isEmpty()) {
                $this->release(1);
            } else {
                Cache::put("flight:{$this->batchId}:{$this->date}", $itineraries, now()->addMinutes(5));
                Cache::put("flight:{$this->batchId}:{$this->return_date}", $itineraries, now()->addMinutes(5));
                Cache::put("batch:{$this->batchId}:flights", $itineraries, now()->addMinutes(180));

                if (! Cache::get("job_completed_{$this->batchId}")) {
                    Cache::put("job_completed_{$this->batchId}", true);
                } else {
                    Cache::put("flight3:{$this->batchId}:{$this->date}", $itineraries, now()->addMinutes(5));
                    Cache::put("flight3:{$this->batchId}:{$this->return_date}", $itineraries, now()->addMinutes(5));
                    Cache::put("flight:{$this->batchId}:latest", 'api3');
                    $this->broadcastFlightResults($itineraries);
                }
            }
        } catch (Exception $e) {
            // if its the first attempt, retry
            if ($this->attempts() == 1) {
                $this->release(1);
            }

            $this->fail($e);
        }
    }

    private function broadcastFlightResults(mixed $itineraries): void
    {
        $syncFlightsAction = app(\App\Actions\SyncFlightsAction::class);

        $syncFlightsAction->handle($itineraries, $this->batchId, $this->date, $this->return_date, $this->destination, $this->origin->id);
    }
}
