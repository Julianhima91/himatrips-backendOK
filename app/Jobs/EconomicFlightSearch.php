<?php

namespace App\Jobs;

use App\Http\Integrations\GoFlightIntegration\Requests\RetrieveFlightsRequest;
use App\Http\Integrations\GoFlightIntegration\Requests\RetrieveIncompleteFlights;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EconomicFlightSearch implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $origin;

    protected $destination;

    protected $adults;

    protected $children;

    protected $infants;

    public int $tries = 3;

    public int $backoff = 3;

    public $batchId;

    public $adConfigId;

    public $yearMonth;

    /**
     * Create a new job instance.
     */
    public function __construct($yearMonth, $origin_airport, $destination_airport, $adults, $children, $infants, $batchId, $adConfigId)
    {
        $this->origin = $origin_airport;
        $this->destination = $destination_airport;
        $this->adults = $adults;
        $this->children = $children;
        $this->infants = $infants;
        $this->batchId = $batchId;
        $this->adConfigId = $adConfigId;
        $this->yearMonth = $yearMonth;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $logger = Log::channel('economic');

        $request = new RetrieveFlightsRequest;

        $cheapest = Cache::get("$this->adConfigId:$this->batchId:cheapest_combination");
        $date = $this->yearMonth.'-'.$cheapest['outbound']['date'];
        $returnDate = $this->yearMonth.'-'.$cheapest['return']['date'];
        $logger->warning("Processing batch: $this->batchId");

        $request->query()->merge([
            'fromEntityId' => $this->origin->rapidapi_id,
            'toEntityId' => $this->destination->rapidapi_id,
            'departDate' => $date,
            'returnDate' => $returnDate,
            'adults' => $this->adults,
            'children' => $this->children,
            'infants' => $this->infants,
        ]);

        try {
            $response = $request->send();

            if (isset($response->json()['data']['context']['status']) &&
                $response->json()['data']['context']['status'] === 'incomplete') {
                $response = $this->getIncompleteResults($response->json()['data']['context']['sessionId']);
            }

            $itineraries = $response->dtoOrFail();

            if ($itineraries->isEmpty()) {
                $logger->error('EMPTY ITINERARIES | Attempt: '.$this->attempts());

                if ($this->attempts() < 3) {
                    $this->release(5);
                } else {
                    $this->fail('Itineraries are empty after 3 attempts');
                }

                return;
            }

            Cache::put("batch:{$this->batchId}:flights", $itineraries, now()->addMinutes(5));

            $csvCache = Cache::get("$this->adConfigId:economic_create_csv", []);
            $csvCache[] = (string) $this->batchId;
            Cache::put("$this->adConfigId:economic_create_csv", $csvCache);
        } catch (\Exception $e) {
            $logger->info($e->getMessage());
            if ($this->attempts() < $this->tries) {
                $this->release(5);
            } else {
                $this->fail($e);
            }
        }
    }

    private function getIncompleteResults($session)
    {
        $logger = Log::channel('economic');

        $request = new RetrieveIncompleteFlights($this->adults, $this->children, $this->infants);
        $logger->warning("Retrieving incomplete results for batch: $this->batchId");

        $request->query()->merge([
            'sessionId' => $session,
        ]);

        $response = $request->send();

        if (isset($response->json()['data']['context']['status']) &&
            $response->json()['data']['context']['status'] === 'incomplete') {
            return $this->getIncompleteResults($session);
        }

        $logger->warning("DONE================================: $this->batchId");

        return $response;
    }
}
