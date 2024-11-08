<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DestinationPlainResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'city' => $this->city,
            'region' => $this->region,
            'country' => $this->country,
            'show_in_homepage' => $this->show_in_homepage,
            'morning_flight_start_time' => $this->morning_flight_start_time,
            'morning_flight_end_time' => $this->morning_flight_end_time,
            'evening_flight_start_time' => $this->evening_flight_start_time,
            'evening_flight_end_time' => $this->evening_flight_end_time,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'packages_count' => $this->packages_count,
            'destination_photos' => $this->destination_photos,
            'min_nights' => $this->min_nights_stay,
            'pivot' => [
                'destination_id' => $this->origins[0]->pivot->destination_id,
                'origin_id' => $this->origins[0]->pivot->origin_id,
                'live_search' => $this->origins[0]->pivot->live_search,
            ],
        ];
    }
}
