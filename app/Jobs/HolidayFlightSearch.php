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

class HolidayFlightSearch implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $origin;

    protected $destination;

    protected $adults;

    protected $children;

    protected $infants;

    public int $tries = 3;

    public int $backoff = 3;

    public $baseBatchId;

    public $adConfigId;

    public $request;

    public function __construct($request, $origin_airport, $destination_airport, $adults, $children, $infants, $batchId, $adConfigId)
    {
        $this->origin = $origin_airport;
        $this->destination = $destination_airport;
        $this->adults = $adults;
        $this->children = $children;
        $this->infants = $infants;
        $this->baseBatchId = $batchId;
        $this->adConfigId = $adConfigId;
        $this->request = $request;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $logger = Log::channel('holiday');

        $request = new RetrieveFlightsRequest;

        $request->query()->merge([
            'fromEntityId' => $this->origin->rapidapi_id,
            'toEntityId' => $this->destination->rapidapi_id,
            'departDate' => $this->request['date'],
            'returnDate' => $this->request['return_date'],
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

                if ($this->attempts() < $this->tries) {
                    $this->release(5);
                } else {
                    $logger->error('==FAIL== EMPTY ITINERARIES | Attempt: '.$this->attempts());
                    $this->fail('Itineraries are empty after 3 attempts');
                }

                return;
            } else {
                $logger->warning("DONE================================: $this->baseBatchId");
                $logger->warning('Itineraries count: '.count($itineraries));

                Cache::put("batch:{$this->baseBatchId}:flights", $itineraries, now()->addMinutes(180));

                $csvCache = Cache::get("$this->adConfigId:holiday_create_csv", []);
                $csvCache[] = (string) $this->baseBatchId;
                Cache::put("$this->adConfigId:holiday_create_csv", $csvCache, now()->addMinutes(180));
            }
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
        $logger = Log::channel('holiday');

        $request = new RetrieveIncompleteFlights($this->adults, $this->children, $this->infants);
        $logger->warning("Retrieving incomplete results for batch: $this->baseBatchId");

        $request->query()->merge([
            'sessionId' => $session,
        ]);

        $response = $request->send();

        if (isset($response->json()['data']['context']['status']) &&
            $response->json()['data']['context']['status'] === 'incomplete') {
            return $this->getIncompleteResults($session);
        }

        return $response;
    }
}
