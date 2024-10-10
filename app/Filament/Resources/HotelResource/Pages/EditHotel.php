<?php

namespace App\Filament\Resources\HotelResource\Pages;

use App\Filament\Resources\HotelResource;
use App\Models\HotelPhoto;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
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
            Action::make('Get Photos')
                ->action('getPhotos'),
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
