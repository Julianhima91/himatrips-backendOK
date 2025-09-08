<?php

namespace App\Filament\Resources\HotelResource\Pages;

use App\Filament\Resources\HotelResource;
use App\Models\Hotel;
use App\Models\HotelFacility;
use App\Models\HotelPhoto;
use App\Models\HotelReview;
use App\Models\HotelReviewSummary;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RicorocksDigitalAgency\Soap\Facades\Soap;
use Saloon\XmlWrangler\XmlReader;

class EditHotel extends EditRecord
{
    protected static string $resource = HotelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->action('save'),
            Action::make('syncBookingData')
                ->label('Sync Booking Data')
                ->icon('heroicon-o-arrow-path')
                ->modalHeading('Sync Hotel Data from Booking.com')
                ->modalWidth('md')
                ->schema([
                    TextInput::make('booking_url')
                        ->label('Hotel Booking URL')
                        ->placeholder('https://www.booking.com/hotel/ch/art-house-basel.en-gb.html')
                        ->required()
                        ->default(fn ($record) => $record->booking_url)
                        ->rules(['regex:/\.html$/'])
                        ->helperText('This will sync photos, facilities, and reviews from Booking.com'),
                ])
                ->action(function (array $data): void {
                    $bookingUrl = $data['booking_url'];
                    $recordId = $this->record->id;

                    $apiUrl = 'https://booking-com18.p.rapidapi.com/web/stays/details-by-url';

                    try {
                        $response = Http::withHeaders([
                            'x-rapidapi-host' => 'booking-com18.p.rapidapi.com',
                            'x-rapidapi-key' => 'eff37b01a1msh6090de6dea39514p108435jsnf7c09e43a0a5',
                        ])->get($apiUrl, [
                            'url' => $bookingUrl,
                        ]);

                        if (! $response->successful()) {
                            throw new \Exception('Failed to fetch data from Booking.com');
                        }

                        $data = $response->json()['data'];

                        DB::beginTransaction();
                        try {
                            // 1. Process Photos (existing functionality)
                            if (isset($data['hotelPhotos'])) {
                                $photos = $data['hotelPhotos'];
                                $highresUrls = collect($photos)->pluck('highres_url', 'id');

                                HotelPhoto::where('hotel_id', $recordId)->delete();

                                foreach ($highresUrls as $index => $url) {
                                    try {
                                        if ($url) {
                                            $fileName = $index.'.jpg';
                                            $imageContent = file_get_contents($url);
                                            $relativeFilePath = 'hotels/'.$recordId.'/'.$fileName;
                                            Storage::disk('public')->put($relativeFilePath, $imageContent);

                                            HotelPhoto::updateOrCreate([
                                                'hotel_id' => $recordId,
                                                'file_path' => $relativeFilePath,
                                            ]);
                                        }
                                    } catch (\Exception $e) {
                                        Log::error('Failed to download image', ['url' => $url, 'error' => $e->getMessage()]);
                                    }
                                }
                            }

                            // 2. Process Facilities
                            HotelFacility::where('hotel_id', $recordId)->delete();

                            if (isset($data['baseFacility']) && is_array($data['baseFacility'])) {
                                foreach ($data['baseFacility'] as $facility) {
                                    if (isset($facility['instances'][0])) {
                                        $instance = $facility['instances'][0];
                                        $chargeMode = 'UNKNOWN';

                                        if (isset($instance['attributes']['paymentInfo']['chargeMode'])) {
                                            $mode = $instance['attributes']['paymentInfo']['chargeMode'];
                                            if ($mode === 'FREE') {
                                                $chargeMode = 'FREE';
                                            } elseif ($mode === 'PAID') {
                                                $chargeMode = 'PAID';
                                            }
                                        }

                                        HotelFacility::create([
                                            'hotel_id' => $recordId,
                                            'facility_id' => $facility['id'],
                                            'facility_name' => $instance['title'] ?? 'Unknown',
                                            'facility_slug' => $facility['slug'] ?? null,
                                            'icon' => $facility['icon'] ?? null,
                                            'group_id' => $facility['groupId'] ?? null,
                                            'charge_mode' => $chargeMode,
                                            'is_offsite' => $instance['attributes']['isOffsite'] ?? false,
                                            'level' => 'property',
                                            'extended_attributes' => $instance['attributes']['extendedAttributes'] ?? null,
                                        ]);
                                    }
                                }
                            }

                            // Also process facility highlights
                            if (isset($data['genericFacilityHighlight']) && is_array($data['genericFacilityHighlight'])) {
                                foreach ($data['genericFacilityHighlight'] as $highlight) {
                                    // Check if this facility doesn't already exist
                                    $exists = HotelFacility::where('hotel_id', $recordId)
                                        ->where('facility_id', $highlight['id'])
                                        ->exists();

                                    if (! $exists) {
                                        HotelFacility::create([
                                            'hotel_id' => $recordId,
                                            'facility_id' => $highlight['id'],
                                            'facility_name' => $highlight['title'] ?? 'Unknown',
                                            'level' => $highlight['level'] ?? 'property',
                                            'charge_mode' => 'UNKNOWN',
                                        ]);
                                    }
                                }
                            }

                            // 3. Process Reviews
                            HotelReview::where('hotel_id', $recordId)->delete();

                            if (isset($data['featuredReview']) && is_array($data['featuredReview'])) {
                                foreach ($data['featuredReview'] as $review) {
                                    $customerTypeMap = [
                                        'WITH_FRIENDS' => 'GROUP',
                                        'YOUNG_COUPLE' => 'YOUNG_COUPLE',
                                        'FAMILY_WITH_YOUNG_CHILDREN' => 'FAMILY_WITH_YOUNG_CHILDREN',
                                        'FAMILY_WITH_OLDER_CHILDREN' => 'FAMILY_WITH_OLDER_CHILDREN',
                                        'SOLO_TRAVELLER' => 'SOLO_TRAVELLER',
                                        'BUSINESS' => 'BUSINESS',
                                        'GROUP' => 'GROUP',
                                        'MATURE_COUPLE' => 'MATURE_COUPLE',
                                    ];

                                    $customerType = $customerTypeMap[$review['customerType']] ?? 'OTHER';

                                    HotelReview::create([
                                        'hotel_id' => $recordId,
                                        'booking_review_id' => $review['id'],
                                        'user_id' => $review['userId'] ?? null,
                                        'reviewer_name' => $review['guestName'] ?? 'Anonymous',
                                        'reviewer_country' => $review['guestCountryCode'] ?? null,
                                        'average_score' => $review['averageScore'] ?? 0,
                                        'positive_text' => $review['positiveText'] ?? null,
                                        'negative_text' => $review['negativeText'] ?? null,
                                        'title' => $review['title'] ?? null,
                                        'customer_type' => $customerType,
                                        'purpose_type' => $review['purposeType'] ?? 'OTHER',
                                        'review_date' => isset($review['completed']) ? date('Y-m-d H:i:s', $review['completed']) : now(),
                                        'language' => $review['language'] ?? 'en',
                                        'is_anonymous' => $review['isAnonymous'] ?? false,
                                        'avatar_url' => $review['userAvatarUrl'] ?? null,
                                    ]);
                                }
                            }

                            // 4. Process Review Summary
                            if (isset($data['propertyReview'][0]['totalScore'])) {
                                $reviewData = $data['propertyReview'][0]['totalScore'];

                                HotelReviewSummary::updateOrCreate(
                                    ['hotel_id' => $recordId],
                                    [
                                        'total_score' => $reviewData['score'] ?? null,
                                        'review_count' => $reviewData['reviewsCount'] ?? 0,
                                        'score_breakdown' => $data['propertyReview'][0]['scoreBreakdown'] ?? null,
                                        'last_updated' => now(),
                                    ]
                                );
                            }

                            // 5. Update hotel booking URL
                            $hotel = Hotel::find($recordId);
                            $hotel->booking_url = $bookingUrl;
                            $hotel->save();

                            DB::commit();

                            $facilityCount = HotelFacility::where('hotel_id', $recordId)->count();
                            $reviewCount = HotelReview::where('hotel_id', $recordId)->count();

                            Notification::make()
                                ->title('Success')
                                ->body("Successfully synced: {$facilityCount} facilities and {$reviewCount} reviews")
                                ->success()
                                ->send();

                        } catch (\Exception $e) {
                            DB::rollback();
                            throw $e;
                        }

                    } catch (\Exception $e) {
                        Log::error('Failed to sync booking data', ['error' => $e->getMessage()]);

                        Notification::make()
                            ->title('Error')
                            ->body('Failed to sync data: '.$e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }

    public function getPhotos()
    {
        $hotelId = $this->record->hotel_id;

        $xmlRequestBody = <<<XML
<Root>
    <Header>
        <Agency>147255</Agency>
        <User>HIMAXMLLOOK</User>
        <Password>D25%74S#cn2!</Password>
        <Operation>HOTEL_INFO_REQUEST</Operation>
        <OperationType>Request</OperationType>
    </Header>
    <Main Version="2.2">
        <InfoHotelId>$hotelId</InfoHotelId>
        <InfoLanguage>en</InfoLanguage>
    </Main>
</Root>
XML;

        $header = Soap::header(
            'authentication',
            'random-namespace',
            [
                'API-Operation' => 'HOTEL_INFO_REQUEST',
                'API-AgencyID' => '138552',
                'Content-Type' => 'application/soap+xml; charset=utf-8',
                'User-Agent' => 'PostmanRuntime/7.32.3',
            ]
        );

        $response = Soap::to('https://hima.xml.goglobal.travel/xmlwebservice.asmx?WSDL')
            ->withOptions([
                'trace' => 1,
                'exceptions' => true,
                'soap_version' => SOAP_1_2,
                'cache_wsdl' => WSDL_CACHE_NONE,
            ])
            ->withHeaders($header)
            ->afterRequesting(function ($request, $response) {
                // Log the response
                // \Log::info('HOTEL SEARCH REQUEST');
                // \Log::info($request->getBody());
                // \Log::info("HOTEL SEARCH REQUEST END\n");
                // \Log::info("RESPONSE START\n");
                // \Log::info(json_encode($response));
            })
            ->call('MakeRequest', [
                'requestType' => 6,
                'xmlRequest' => $xmlRequestBody,
            ]);

        $reader = XmlReader::fromString($response->response->MakeRequestResult);

        // get the array of photos
        $photos = $reader->values()['Root']['Main']['Pictures']['Picture'];

        // for every photo we need to download it and save it to the database
        foreach ($photos as $photo) {
            $photoData = file_get_contents($photo);
            // generate random name for the photo
            $photoName = uniqid().'.jpg';
            $photoPath = 'hotels/'.$hotelId.'/'.$photoName;
            Storage::disk('public')->put($photoPath, $photoData);
            HotelPhoto::create([
                'file_path' => $photoPath,
                'hotel_id' => $this->record->id,
            ]);
        }

        $this->redirect('edit', $this->record->id);

    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        unset($data['reviewSummary']);
        // get the images from the data
        $images = $data['Images'];
        unset($data['Images']);
        $record->update($data);

        // delete all photos first from the database and from storage
        $record->hotelPhotos()->delete();

        // for every image we create a hotel photo
        foreach ($images as $image) {
            $record->hotelPhotos()->updateOrCreate([
                'file_path' => $image,
                'hotel_id' => $record->id,
            ]);
        }

        return $record;
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $model = static::getModel();

        $data['Images'] = $model::find($data['id'])->hotelPhotos->pluck('file_path')->toArray();
        $data['destination_id'] = $model::find($data['id'])->destination?->id;
        $data['reviewSummary'] = $this->record->reviewSummary;

        return $data;
    }
}
