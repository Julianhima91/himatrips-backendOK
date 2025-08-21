<?php

namespace App\Jobs;

use App\Http\Integrations\GoFlightIntegration\Requests\RetrieveFlightsApi3Request;
use App\Http\Integrations\GoFlightIntegration\Requests\RetrieveIncompleteFlights;
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
            ray('trying')->purple();
            ray($response->json())->purple();
            ray($response->json()['places'])->purple();
        } catch (Exception $e) {
            ray('Exception caught')->purple();
            ray($e->getMessage())->purple();
            if ($this->attempts() == 1) {
                $this->release(1);
            }

            $this->fail($e);
        }

        // check if context is set, and if it is incomplete, then we have to hit another endpoint
        //        if (isset($response->json()['data']['context']['status']) && $response->json()['data']['context']['status'] == 'incomplete') {
        //            $response = $this->getIncompleteResults($response->json()['data']['context']['sessionId']);
        //        }

        $logger = Log::channel('livesearch');

        try {
            $itineraries = $response->dtoOrFail();

            $logger->info("$this->batchId API 1 ITINERARIES COUNT:");
            $logger->info(count($itineraries));
            if ($itineraries->isEmpty()) {
                ray('empty itineraries 1111');
                $this->release(1);
            } else {
                ray('SUCCESS')->purple();
                cache()->put('flight_'.$this->date, $itineraries, now()->addMinutes(5));
                cache()->put('flight_'.$this->return_date, $itineraries, now()->addMinutes(5));
                Cache::put("batch:{$this->batchId}:flights", $itineraries, now()->addMinutes(180));

                if (! Cache::get("job_completed_{$this->batchId}")) {
                    Cache::put("job_completed_{$this->batchId}", true);
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

    private function getIncompleteResults($session)
    {

        $request = new RetrieveIncompleteFlights($this->adults, $this->children, $this->infants);

        $request->query()->merge([
            'sessionId' => $session,
        ]);

        $response = $request->send();

        if (isset($response->json()['context']['status']) && $response->json()['context']['status'] == 'incomplete') {
            return $this->getIncompleteResults($session);
        }

        return $response;
    }
}
