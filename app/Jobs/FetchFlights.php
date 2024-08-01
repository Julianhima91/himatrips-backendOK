<?php

namespace App\Jobs;

use App\Http\Integrations\GoFlightIntegration\Requests\RetrieveFlightsRequest;
use App\Models\Airline;
use App\Models\Airport;
use App\Models\FlightData;
use App\Models\FlightItinerary;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use JetBrains\PhpStorm\NoReturn;
use Sammyjo20\LaravelHaystack\Concerns\Stackable;
use Sammyjo20\LaravelHaystack\Contracts\StackableJob;

class FetchFlights implements ShouldQueue, StackableJob
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Stackable;

    protected $config;

    protected $date;

    protected $origin;

    protected $destination;

    public int $tries = 5;

    public function backoff(): array
    {
        return [10, 30, 60, 300, 600]; // Wait times in seconds for each retry
    }

    /**
     * Create a new job instance.
     */
    public function __construct($config, $date, $origin, $destination)
    {
        $this->config = $config;
        $this->date = $date;
        $this->origin = $origin;
        $this->destination = $destination;
    }

    /**
     * Execute the job.
     *
     * @throws \Exception
     */
    #[NoReturn]
    public function handle(): void
    {
        $request = new RetrieveFlightsRequest;

        $originAirport = Airport::where('id', $this->origin)->first();
        $destinationAirport = Airport::where('id', $this->destination)->first();

        $request->query()->merge([
            'originSkyId' => $originAirport->sky_id,
            'destinationSkyId' => $destinationAirport->sky_id,
            'originEntityId' => $originAirport->entity_id,
            'destinationEntityId' => $destinationAirport->entity_id,
            'date' => $this->date->format('Y-m-d'),
        ]);

        $response = $request->send();

        $itineraries = $response->dtoOrFail();

        //filter all itineraries by stop count
        $itineraries = $itineraries->filter(function ($itinerary) {
            return $itinerary->stopCount <= $this->config->max_stop_count;
        });

        //if stop count is more than 0, we need to make sure that the same airline is used for both flights
        $itineraries = $itineraries->filter(function ($itinerary) {
            if ($itinerary->stopCount > 0) {
                //return only where count of carriers is 1
                return count($itinerary->carriers) == 1;
            }

            return true;
        });

        if ($this->config->max_transit_time) {
            //filter all where the transit time is not more than max_transit time
            $itineraries = $itineraries->filter(function ($itinerary) {
                //go through timeBetweenFlights and return false if one element is more than max_transit_time
                return collect($itinerary->timeBetweenFlights)->filter(function ($time) {
                    return $time > $this->config->max_transit_time;
                })->isEmpty();
            });
        }

        //save the first itinerary
        $selectedItinerary = $itineraries->first();

        if (! $selectedItinerary) {
            Log::info('No itineraries found for '.$originAirport->name.' to '.$destinationAirport->name.' on '.$this->date->format('Y-m-d'));

            return;
        }

        $firstFlight = FlightData::create([
            'price' => $selectedItinerary->price,
            'departure' => $selectedItinerary->departure,
            'arrival' => $selectedItinerary->arrival,
            'airline' => $selectedItinerary->airline,
            'stop_count' => $selectedItinerary->stopCount,
            'origin' => $originAirport->sky_id,
            'destination' => $destinationAirport->sky_id,
            'extra_data' => json_encode($selectedItinerary),
            'package_config_id' => $this->config->id,
        ]);

        //remove the first element from the collection
        $itineraries->shift();

        if ($itineraries->isEmpty()) {
            return;
        }

        //save the rest of the itineraries
        $itineraries->each(function ($itinerary) use ($firstFlight) {
            FlightItinerary::create([
                'flight_data_id' => $firstFlight->id,
                'departure' => $itinerary->departure,
                'arrival' => $itinerary->arrival,
                'price' => $itinerary->price,
                'airline' => $itinerary->airline,
                'stop_count' => $itinerary->stopCount,
            ]);
        });

    }
}
