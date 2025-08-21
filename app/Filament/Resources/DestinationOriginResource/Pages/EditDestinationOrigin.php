<?php

namespace App\Filament\Resources\DestinationOriginResource\Pages;

use App\Filament\Resources\DestinationOriginResource;
use App\Models\Tag;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Log;

class EditDestinationOrigin extends EditRecord
{
    protected static string $resource = DestinationOriginResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        Log::info('Request Data:', $data);
        Log::info('Request Data:', request()->input('photos', []));
        $images = $data['photos'] ?? [];
        unset($data['photos']);

        $record->update($data);

        $record->photos()->delete();

        foreach ($images as $image) {
            $photo = $record->photos()->create([
                'file_path' => $image['file_path'] ?? null,
            ]);

            if (! empty($image['tags'])) {
                $tagIds = collect($image['tags'])->map(function ($tagName) {
                    return Tag::whereJsonContains('name->en', $tagName)->value('id');
                });

                $photo->tags()->attach($tagIds);
            }
        }

        return $record;
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $model = static::getModel();

        $photos = $model::find($data['id'])->photos;

        $data['photos'] = $photos->map(function ($photo) {
            return [
                'file_path' => $photo->file_path,
                'tags' => $photo->tags->pluck('name')->toArray(),
            ];
        })->toArray();

        return $data;
    }
}
