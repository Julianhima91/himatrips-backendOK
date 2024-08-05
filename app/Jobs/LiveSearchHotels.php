<?php

namespace App\Jobs;

use App\Data\HotelDataDTO;
use App\Data\HotelOfferDTO;
use App\Models\Hotel;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RicorocksDigitalAgency\Soap\Facades\Soap;
use Spatie\LaravelData\Optional;

use function Sentry\addBreadcrumb;

class LiveSearchHotels implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    private $checkin_date;

    private $nights;

    private $destination;

    private $adults;

    private $children;

    private $infants;

    /**
     * Create a new job instance.
     */
    public function __construct($checkin_date, $nights, $destination, $adults, $children, $infants)
    {
        $this->checkin_date = $checkin_date;
        $this->nights = $nights;
        $this->destination = $destination;
        $this->adults = $adults;
        $this->children = $children;
        $this->infants = $infants;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        //get the hotel IDs
        //        $hotelIds = Hotel::where('destination_id', $this->destination)->pluck('hotel_id');

        $hotelIds = Hotel::whereHas('destinations', function ($query) {
            $query->where('destination_id', $this->destination);
        })->pluck('hotel_id');

        //implode the array to form strings like this   <HotelId>226</HotelId>
        $hotelIds = implode('', array_map(function ($hotelId) {
            return "<HotelId>{$hotelId}</HotelId>";
        }, $hotelIds->toArray()));

        try {
            $response = $this->getHotelData($hotelIds, $this->checkin_date, $this->nights, $this->adults, $this->children, $this->infants);
        } catch (\Exception $e) {
            //if it's the first time, we retry
            if ($this->attempts() == 1) {
                addBreadcrumb('message', 'Hotel Attempts', ['attempts' => $this->attempts()]);
                $this->release(1);
            }
            $this->fail($e);
        }

        if (! isset(json_decode($response->MakeRequestResult)->Hotels)) {
            \Log::info('No hotels found');
            if ($this->attempts() == 1) {
                addBreadcrumb('message', 'Hotel Attempts', ['attempts' => $this->attempts()]);
                $this->release(1);
            }
        }

        $hotel_results = [];

        try {
            foreach (json_decode($response->MakeRequestResult)->Hotels as $hotelOffer) {

                $hotel = Hotel::where('hotel_id', $hotelOffer->HotelCode)->first()->id;

                $hotel = new HotelDataDTO(
                    id: new Optional,
                    hotel_id: $hotel,
                    check_in_date: Carbon::createFromFormat('Y-m-d', $this->checkin_date),
                    number_of_nights: $this->nights,
                    room_count: 1,
                    adults: $this->adults,
                    children: $this->children,
                    infants: $this->infants,
                    hotel_offers: $hotelOffer->Offers
                );

                $offers = [];

                //save all offers
                foreach ($hotelOffer->Offers as $offer) {

                    $reservation_deadline = Carbon::createFromFormat('d/M/Y', $offer->CxlDeadLine);

                    //if price is 0, we skip this offer
                    if ($offer->TotalPrice == 0 || $offer->TotalPrice > 1000000) {
                        continue;
                    }

                    $offers[] = new HotelOfferDTO(
                        id: new Optional,
                        hotel_data_id: 0,
                        room_basis: $offer->RoomBasis,
                        room_type: $offer->Rooms[0],
                        price: $offer->TotalPrice,
                        reservation_deadline: $reservation_deadline,
                        remark: $offer->Remark,
                    );
                }

                $hotel->hotel_offers = $offers;

                $hotel_results[] = $hotel;

            }
        } catch (\Exception $e) {
            //if its the first time, we retry
            if ($this->attempts() == 1) {
                addBreadcrumb('message', 'Hotel Attempts', ['attempts' => $this->attempts()]);
                $this->release(1);
            }
        }

        //save the hotel results in cache
        Cache::put($this->batchId.'_hotels', $hotel_results, now()->addMinutes(5));
    }

    public function getHotelData(string $hotelIds, mixed $arrivalDate, mixed $nights, $adults, $children, $infants): mixed
    {
        //$children and infants will be an integer
        //for every children we need to create this string
        //<ChildAge>9</ChildAge>
        //for every infant we need to create this string
        //<ChildAge>1</ChildAge>

        $childrenString = '';
        for ($i = 0; $i < $children; $i++) {
            $childrenString .= '<ChildAge>9</ChildAge>';
        }

        $infantsString = '';

        for ($i = 0; $i < $infants; $i++) {
            $infantsString .= '<ChildAge>1</ChildAge>';
        }

        $totalChildren = $infants + $children;

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
            <Room Adults="{$adults}" RoomCount="1" ChildCount="{$totalChildren}">
            "{$childrenString}"
            "{$infantsString}"
            </Room>
        </Rooms>
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
                //ray($request->getBody());
                //\Log::info("HOTEL SEARCH REQUEST END\n");
                //\Log::info("RESPONSE START\n");
                //\Log::info(json_encode($response));
            })
            ->call('MakeRequest', [
                'requestType' => 11,
                'xmlRequest' => $xmlRequestBody,
            ]);
    }
}
