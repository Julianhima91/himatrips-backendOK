<?php

namespace App\Jobs;

use App\Models\Airport;
use App\Models\FlightData;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Sammyjo20\LaravelHaystack\Concerns\Stackable;
use Sammyjo20\LaravelHaystack\Contracts\StackableJob;

class FetchHotelData implements ShouldQueue, StackableJob
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Stackable;

    public int $tries = 5;

    protected $config;

    protected $nights;

    public function backoff(): array
    {
        return [10, 30, 60, 300, 900]; // Wait times in seconds for each retry
    }

    /**
     * Create a new job instance.
     */
    public function __construct($config, $nights)
    {
        $this->config = $config;
        $this->nights = $nights;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $originAirports = Airport::whereIn('id', $this->config->origin_airports)->get()->map(function ($airport) {
            return $airport->codeIataAirport;
        });

        $flights = FlightData::where('package_config_id', $this->config->id)
            ->whereIn('origin', $originAirports)
            ->get();

        $combos = [];

        foreach ($flights as $flight) {
            foreach ($this->nights as $night) {
                $returnDate = $flight->departure->addDays($night);

                // Find potential return flights
                $returnFlights = FlightData::where('origin', $flight->destination)
                    ->where('destination', $flight->origin)
                    ->whereDate('departure', $returnDate)
                    ->get();

                foreach ($returnFlights as $returnFlight) {
                    $combos[] = [
                        'outbound' => $flight,
                        'return' => $returnFlight,
                    ];
                }
            }
        }

        $hotelIds = $this->config->destination_origin->destination->hotels->map(function ($hotel) {
            return $hotel->hotel_id;
        });

        //implode the array to form strings like this   <HotelId>226</HotelId>
        $hotelIds = implode('', array_map(function ($hotelId) {
            return "<HotelId>{$hotelId}</HotelId>";
        }, $hotelIds->toArray()));

        foreach ($combos as $combo) {
            //arrival date is the date of departure from the outbound flight
            $arrivalDate = $combo['outbound']->arrival->format('Y-m-d');
            //number of nights is the number of nights in the first combo
            $nights = $combo['outbound']->arrival->diffInDays($combo['return']->departure->endOfDay());

            FetchSeparateHotelData::dispatch($hotelIds, $arrivalDate, $nights, $combo, $this->config);
        }
    }
}
