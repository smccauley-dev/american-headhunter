<?php

namespace App\Http\Resources\Property;

use Illuminate\Http\Resources\Json\JsonResource;

class PropertyResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                        => $this->id,
            'title'                     => $this->title,
            'slug'                      => $this->slug,
            'status'                    => $this->status,
            'state_code'                => $this->state_code,
            'county'                    => $this->county,
            'total_acres'               => $this->total_acres,
            'huntable_acres'            => $this->huntable_acres,
            // Document ID only — URL resolution via DocumentService::getUrl() is wired in Step 3.
            'primary_photo_document_id' => $this->primary_photo_document_id,
            'species'                   => $this->whenLoaded('species', fn() =>
                $this->species->map(fn($s) => [
                    'code'       => $s->species_code,
                    'is_primary' => $s->is_primary,
                ])->values()
            ),
            'listings'                  => $this->whenLoaded('activeListings', fn() =>
                $this->activeListings->map(fn($l) => [
                    'id'               => $l->id,
                    'listing_type'     => $l->listing_type,
                    'season_start'     => $l->season_start?->toDateString(),
                    'season_end'       => $l->season_end?->toDateString(),
                    'max_hunters'      => $l->max_hunters,
                    'price_per_hunter' => $l->price_per_hunter,
                    'price_total'      => $l->price_total,
                    'deposit_percent'  => $l->deposit_percent,
                    'visibility'       => $l->visibility,
                ])->values()
            ),
        ];
    }
}
