<?php

namespace App\Jobs;

use App\Models\Hotel;
use App\Models\HotelData;
use App\Models\Package;
use DateTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use RicorocksDigitalAgency\Soap\Facades\Soap;

class FetchSeparateHotelData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $hotelIds;

    public $arrivalDate;

    public $nights;

    public $combo;

    public $config;

    public int $tries = 5;

    public function backoff(): array
    {
        return [10, 30, 60, 300, 900]; // Wait times in seconds for each retry
    }

    /**
     * Create a new job instance.
     */
    public function __construct($hotelIds, $arrivalDate, $nights, $combo, $config)
    {
        $this->hotelIds = $hotelIds;
        $this->arrivalDate = $arrivalDate;
        $this->nights = $nights;
        $this->combo = $combo;
        $this->config = $config;
    }

    /*    public function middleware(): array
        {
            //return [new RateLimited('hotelData')];
        }*/

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        //check if we need to filter on room basis
        $boardBasis = '';

        if ($this->config->room_basis) {
            $boardBasis = "                    <FilterRoomBasises>
                        <FilterRoomBasis>{$this->config->room_basis}</FilterRoomBasis>
                    </FilterRoomBasises>";
        }

        $response = $this->getHotelData($this->hotelIds, $this->arrivalDate, $this->nights, $boardBasis);

        //check if response is successful and if not wait and retry in a bit

        if (! isset(json_decode($response->MakeRequestResult)->Hotels)) {
            $this->fail('No hotels found for this request');

            return;
        }

        foreach (json_decode($response->MakeRequestResult)->Hotels as $hotelOffer) {

            $cheapestOffer = collect($hotelOffer->Offers)->sortBy('TotalPrice')->first();

            $hotel = HotelData::create([
                'hotel_id' => Hotel::where('hotel_id', $hotelOffer->HotelCode)->first()->id,
                'check_in_date' => $this->arrivalDate,
                'number_of_nights' => $this->nights,
                'adults' => 2,
                'children' => 0,
                'package_config_id' => $this->config->id,
                //'room_details' => json_encode($cheapestOffer->Rooms),
                //'reservation_deadline' => DateTime::createFromFormat('d/M/Y', $cheapestOffer->CxlDeadLine)->format('Y-m-d'),
                //'price' => $cheapestOffer->TotalPrice,
                //'room_basis' => $cheapestOffer->RoomBasis,
            ]);

            //save all offers
            foreach ($hotelOffer->Offers as $offer) {
                $hotel->offers()->create([
                    'room_basis' => $offer->RoomBasis,
                    'room_type' => $offer->Rooms,
                    'price' => $offer->TotalPrice,
                    'reservation_deadline' => DateTime::createFromFormat('d/M/Y', $offer->CxlDeadLine)->format('Y-m-d'),
                    'remark' => $offer->Remark,
                ]);
            }

            if ($hotel) {
                //create the package here
                Package::create([
                    'outbound_flight_id' => $this->combo['outbound']->id,
                    'inbound_flight_id' => $this->combo['return']->id,
                    'hotel_data_id' => $hotel->id,
                    'commission' => $this->getTotalCommissionAmount($this->combo, $hotel),
                    'package_config_id' => $this->config->id,
                    'total_price' => $this->combo['outbound']->price + $this->combo['return']->price + $hotel->offers()->first()->price + $this->getTotalCommissionAmount($this->combo, $hotel),
                ]);
            }

        }
    }

    public function getHotelData(string $hotelIds, mixed $arrivalDate, mixed $nights, $boardBasis): mixed
    {
        $xmlRequestBody = <<<XML
<Root>
    <Header>
        <Agency>147255</Agency>
        <User>HIMAXMLLOOK</User>
        <Password>Fh12!@67GDtn</Password>
        <Operation>HOTEL_SEARCH_REQUEST</Operation>
        <OperationType>Request</OperationType>
    </Header>
    <Main Version="2.4" ResponseFormat="JSON" IncludeGeo="false" Currency="EUR">
        <Hotels>
            {$hotelIds}
        </Hotels>
        <MaximumWaitTime>1500</MaximumWaitTime>
        <Nationality>AL</Nationality>
        <ArrivalDate>{$arrivalDate}</ArrivalDate>
        <Nights>{$nights}</Nights>
        <Rooms>
            <Room Adults="2" RoomCount="1" ChildCount="0"/>
        </Rooms>
        {$boardBasis}
    </Main>
</Root>
XML;

        $header = Soap::header(
            'authentication',
            'random-namespace',
            [
                'API-Operation' => 'HOTEL_SEARCH_REQUEST',
                'API-AgencyID' => '138552',
                'Content-Type' => 'application/soap+xml; charset=utf-8',
                'User-Agent' => 'PostmanRuntime/7.32.3',
            ]
        );

        return Soap::to('https://hima.xml.goglobal.travel/xmlwebservice.asmx?WSDL')
            ->withOptions([
                'trace' => 1,
                'exceptions' => true,
                'soap_version' => SOAP_1_2,
                'cache_wsdl' => WSDL_CACHE_NONE,
            ])
            ->withHeaders($header)
            ->afterRequesting(function ($request, $response) {
                // Log the response
                //\Log::info('HOTEL SEARCH REQUEST');
                //\Log::info($request->getBody());
                //\Log::info("HOTEL SEARCH REQUEST END\n");
                //\Log::info("RESPONSE START\n");
                //\Log::info(json_encode($response));
            })
            ->call('MakeRequest', [
                'requestType' => 11,
                'xmlRequest' => $xmlRequestBody,
            ]);
    }

    public function getPercentageCommisionAmount(mixed $combo, HotelData $hotel): int|float
    {
        return round(($this->config->commission_amount * ($combo['outbound']->price + $combo['return']->price + $hotel->price)) / 100, 0, PHP_ROUND_HALF_UP);
    }

    public function getTotalCommissionAmount(mixed $combo, HotelData $hotel): int|float
    {
        return $this->config->commission_type == 'fixed' ? $this->config->commission_amount : $this->getPercentageCommisionAmount($combo, $hotel);
    }
}
