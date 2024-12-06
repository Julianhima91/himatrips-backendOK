<?php

namespace App\Filament\Resources\HotelResource\Pages;

use App\Filament\Resources\HotelResource;
use App\Models\Hotel;
use App\Models\HotelPhoto;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
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
            Action::make('getPhotos')
                ->label('Get Photos')
                ->icon('heroicon-o-link')
                ->modalHeading('Paste Photo URL')
                ->modalWidth('md')
                ->form([
                    TextInput::make('photo_url')
                        ->label('Photo URL')
                        ->placeholder('https://example.com/photo.jpg')
                        ->required()
                        ->default(fn ($record) => $record->booking_url)
                        ->rules(['regex:/\.html$/']),
                ])
                ->action(function (array $data): void {
                    $photoUrl = $data['photo_url'];

                    $apiUrl = 'https://booking-com18.p.rapidapi.com/web/stays/details-by-url';
                    $response = Http::withHeaders([
                        'x-rapidapi-host' => 'booking-com18.p.rapidapi.com',
                        'x-rapidapi-key' => 'eff37b01a1msh6090de6dea39514p108435jsnf7c09e43a0a5',
                    ])->get($apiUrl, [
                        'url' => $photoUrl,
                    ]);

                    if ($response->successful()) {
                        $photos = $response->json()['data']['hotelPhotos'];
                        $highresUrls = collect($photos)->pluck('highres_url', 'id');
                        $recordId = $this->record->id;

                        foreach ($highresUrls as $index => $url) {
                            try {
                                if ($url) {
                                    $fileName = $index.'.jpg';
                                    $imageContent = file_get_contents($url);
                                    $relativeFilePath = 'hotels/'.$recordId.'/'.$fileName;
                                    Storage::disk('public')->put($relativeFilePath, $imageContent);

                                    Log::info('Downloaded image', ['url' => $url, 'filePath' => $relativeFilePath]);

                                    HotelPhoto::updateOrCreate([
                                        'hotel_id' => $recordId,
                                        'file_path' => $relativeFilePath,
                                    ]);
                                }
                            } catch (\Exception $e) {
                                Log::error('Failed to download image', ['url' => $url, 'error' => $e->getMessage()]);
                            }
                        }

                        $hotel = Hotel::find($recordId);
                        $hotel->booking_url = $data['photo_url'];
                        $hotel->save();

                        Notification::make()
                            ->title('Success')
                            ->body('The photos were successfully retrieved.')
                            ->success()
                            ->send();
                    } else {
                        Notification::make()
                            ->title('Error')
                            ->body('Failed to retrieve photos. Please check the URL or try again later.')
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
                //\Log::info('HOTEL SEARCH REQUEST');
                //\Log::info($request->getBody());
                //\Log::info("HOTEL SEARCH REQUEST END\n");
                //\Log::info("RESPONSE START\n");
                //\Log::info(json_encode($response));
            })
            ->call('MakeRequest', [
                'requestType' => 6,
                'xmlRequest' => $xmlRequestBody,
            ]);

        $reader = XmlReader::fromString($response->response->MakeRequestResult);

        //get the array of photos
        $photos = $reader->values()['Root']['Main']['Pictures']['Picture'];

        //for every photo we need to download it and save it to the database
        foreach ($photos as $photo) {
            $photoData = file_get_contents($photo);
            //generate random name for the photo
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
        //get the images from the data
        $images = $data['Images'];
        unset($data['Images']);
        $record->update($data);

        //delete all photos first from the database and from storage
        $record->hotelPhotos()->delete();

        //for every image we create a hotel photo
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

        return $data;
    }
}
