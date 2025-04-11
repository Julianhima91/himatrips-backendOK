<?php

namespace App\Jobs;

use App\Data\HotelDataDTO;
use App\Data\HotelOfferDTO;
use App\Models\Destination;
use App\Models\Hotel;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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

    private $rooms;

    public $batchId;

    private $countryCode;

    /**
     * Create a new job instance.
     */
    public function __construct($checkin_date, $nights, $destination, $adults, $children, $infants, $rooms, $batchId, $countryCode)
    {
        $this->checkin_date = $checkin_date;
        $this->nights = $nights;
        $this->destination = $destination;
        $this->adults = $adults;
        $this->children = $children;
        $this->infants = $infants;
        $this->rooms = $rooms;
        $this->batchId = $batchId;
        $this->countryCode = $countryCode;
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

        $boardOptions = Destination::find($this->destination)->board_options;
        try {
            $response = $this->getHotelData($hotelIds, $this->checkin_date, $this->nights, $this->adults, $this->children, $this->infants, $this->rooms, $boardOptions, $this->countryCode);
        } catch (\Exception $e) {
            //if it's the first time, we retry
            if ($this->attempts() == 1) {
                addBreadcrumb('message', 'Hotel Attempts', ['attempts' => $this->attempts()]);
                $this->release(1);
            }
            $this->fail($e);
        }

        if (! isset(json_decode($response->MakeRequestResult)->Hotels)) {
            //            Log::info('No hotels found');
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
                    room_count: count($this->rooms),
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
                        room_type: $offer->Rooms,
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
        Cache::put('hotels', $hotel_results, now()->addMinutes(5));
        Cache::put("batch:{$this->batchId}:hotels", $hotel_results, now()->addMinutes(5));
        Cache::put("hotel_job_completed_{$this->batchId}", true, now()->addMinutes(1));
    }

    public function getHotelData(string $hotelIds, mixed $arrivalDate, mixed $nights, $adults, $children, $infants, $rooms, $boardOptions, $countryCode): mixed
    {
        //todo: Add All possible variations
        $oneRoom = [
            [1, 0],  // 1 adult, 0 children
            [1, 1],  // 1 adult, 1 child
            [1, 2],  // 1 adult, 2 children
            [1, 3],  // 1 adult, 3 children
            [2, 0],  // 2 adults, 0 children
            [2, 1],  // 2 adults, 1 child
            [2, 2],  // 2 adults, 2 children
            [3, 0],  // 3 adults, 0 children
        ];

        $doubleSearch = [
            [2, 3],  // 2 adults, 3 children
            [3, 1],  // 3 adults, 1 child
            [3, 2],  // 3 adults, 2 children
            [4, 0],  // 4 adults, 0 children
            [4, 1],  // 4 adults, 1 child
            [4, 2],  // 4 adults, 2 children
        ];

        $split = [
            [2, 4],  // 2 adults, 4 children
            [2, 5],  // 2 adults, 5 children
            [3, 3],  // 3 adults, 3 children
            [3, 4],  // 3 adults, 4 children
            [3, 5],  // 3 adults, 5 children
            [4, 3],  // 4 adults, 3 children
            [4, 4],  // 4 adults, 4 children
            [4, 5],  // 4 adults, 5 children
            [5, 0],  // 5 adults, 0 children
            [5, 1],  // 5 adults, 1 child
            [5, 2],  // 5 adults, 2 children
            [5, 3],  // 5 adults, 3 children
            [5, 4],  // 5 adults, 4 children
            [5, 5],  // 5 adults, 5 children
        ];

        $roomsXml = '';
        $roomsTwoXml = '';
        $combinationType = [];

        foreach ($rooms as $room) {
            foreach ($oneRoom as $combination) {
                [$roomAdults, $roomChildren] = $combination;
                if ($room['adults'] == $roomAdults && $room['children'] == $roomChildren) {
                    $combinationType[] = 'oneRoom';

                    $roomsXml = $this->prepareRooms($room, $roomsXml);
                    $roomsTwoXml = $this->prepareRooms($room, $roomsTwoXml);

                    break;
                }
            }

            foreach ($doubleSearch as $combination) {
                [$roomAdults, $roomChildren] = $combination;
                if ($room['adults'] == $roomAdults && $room['children'] == $roomChildren) {
                    $combinationType[] = 'doubleSearch';

                    $roomsXml = $this->prepareRooms($room, $roomsXml);

                    $totalRooms = $this->dividePeopleIntoRooms($room['adults'], $room['children'], $room['infants']);
                    foreach ($totalRooms as $room) {
                        $roomsTwoXml = $this->prepareRooms($room, $roomsTwoXml);
                    }
                }
            }

            foreach ($split as $combination) {
                [$roomAdults, $roomChildren] = $combination;
                if ($room['adults'] == $roomAdults && $room['children'] == $roomChildren) {
                    $combinationType[] = 'split';

                    $totalRooms = $this->dividePeopleIntoRooms($room['adults'], $room['children'], $room['infants']);
                    foreach ($totalRooms as $room) {
                        $roomsXml = $this->prepareRooms($room, $roomsXml);
                        $roomsTwoXml = $this->prepareRooms($room, $roomsTwoXml);
                    }
                }
            }
        }

        if (in_array('doubleSearch', $combinationType)) {
            $normalSearch = $this->sendXmlRequest($boardOptions, $hotelIds, $arrivalDate, $nights, $roomsXml, $countryCode);

            $splitSearch = $this->sendXmlRequest($boardOptions, $hotelIds, $arrivalDate, $nights, $roomsTwoXml, $countryCode);

            $normalSearchHotels = json_decode($normalSearch->MakeRequestResult)->Hotels;
            $splitSearchHotels = json_decode($splitSearch->MakeRequestResult)->Hotels;

            $allHotels = array_merge($normalSearchHotels, $splitSearchHotels);

            $groupedHotels = [];
            foreach ($allHotels as $hotel) {
                $hotelId = $hotel->HotelCode;
                $offerPrice = $hotel->Offers[0]->TotalPrice;

                if (! isset($groupedHotels[$hotelId])) {
                    $groupedHotels[$hotelId] = $hotel;
                } else {
                    $existingOfferPrice = $groupedHotels[$hotelId]->Offers[0]->TotalPrice;

                    if ($offerPrice < $existingOfferPrice) {
                        $groupedHotels[$hotelId] = $hotel;
                    }
                }
            }

            $finalHotels = array_values($groupedHotels);

            $normalSearchDecoded = json_decode($normalSearch->MakeRequestResult, true);
            $normalSearchDecoded['Hotels'] = $finalHotels;

            $normalSearch->MakeRequestResult = json_encode($normalSearchDecoded);

            return $normalSearch;
        } else {
            return $this->sendXmlRequest($boardOptions, $hotelIds, $arrivalDate, $nights, $roomsXml, $countryCode);
        }
    }

    private function prepareRooms($room, $roomsXml)
    {
        $childrenString = '';
        for ($i = 0; $i < $room['children']; $i++) {
            $childrenString .= '<ChildAge>9</ChildAge>';
        }

        $infantsString = '';
        for ($i = 0; $i < ($room['infants']); $i++) {
            $infantsString .= '<ChildAge>1</ChildAge>';
        }

        $totalChildren = $room['children'] + $room['infants'];

        $roomsXml .= "<Room Adults=\"{$room['adults']}\" RoomCount=\"1\" ChildCount=\"{$totalChildren}\">".
            "{$childrenString}{$infantsString}".
            '</Room>';

        return $roomsXml;
    }

    private function sendXmlRequest($boardOptions, $hotelIds, $arrivalDate, $nights, $roomsXml, $countryCode)
    {
        $filterRoomBasisesXml = '<FilterRoomBasises>';

        if ($boardOptions) {
            foreach ($boardOptions as $boardOption) {
                $filterRoomBasisesXml .= "<FilterRoomBasis>{$boardOption}</FilterRoomBasis>";
            }
        }
        $filterRoomBasisesXml .= '</FilterRoomBasises>';

        $xmlRequestBody = <<<XML
<Root>
    <Header>
        <Agency>147255</Agency>
        <User>HIMAXMLLOOK</User>
        <Password>D25%74S#cn2!</Password>
        <Operation>HOTEL_SEARCH_REQUEST</Operation>
        <OperationType>Request</OperationType>
    </Header>
    <Main Version="2.4" ResponseFormat="JSON" IncludeGeo="false" Currency="EUR">
        <Hotels>
            {$hotelIds}
        </Hotels>
        <MaximumWaitTime>1500</MaximumWaitTime>
        {$filterRoomBasisesXml}
        <Nationality>{$countryCode}</Nationality>
        <ArrivalDate>{$arrivalDate}</ArrivalDate>
        <Nights>{$nights}</Nights>
        <Rooms>
            $roomsXml
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
                //ray($response);
                //\Log::info("HOTEL SEARCH REQUEST END\n");
                //\Log::info("RESPONSE START\n");
                //\Log::info(json_encode($response));
            })
            ->call('MakeRequest', [
                'requestType' => 11,
                'xmlRequest' => $xmlRequestBody,
            ]);
    }

    private function dividePeopleIntoRooms($adults, $children, $infants)
    {
        $rooms = 2;
        $adultsPerRoom = intdiv($adults, $rooms);
        $remainingAdults = $adults % $rooms;

        $childrenPerRoom = intdiv($children, $rooms);
        $remainingChildren = $children % $rooms;

        // Distributing them into rooms
        $roomAssignments = [];
        for ($i = 0; $i < $rooms; $i++) {
            $adultsInRoom = $adultsPerRoom + ($i < $remainingAdults ? 1 : 0);
            $childrenInRoom = $childrenPerRoom + ($i < $remainingChildren ? 1 : 0);
            $infantsInRoom = 0;

            if ($i == 0) {
                $infantsInRoom = (int) $infants;
            }

            $roomAssignments[] = [
                'adults' => $adultsInRoom,
                'children' => $childrenInRoom,
                'infants' => $infantsInRoom,
            ];
        }

        return $roomAssignments;
    }
}
