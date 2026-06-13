<?php

namespace App\Http\Resources\Property;

use Illuminate\Http\Resources\Json\JsonResource;

class PropertyDetailResource extends JsonResource
{
    public function toArray($request): array
    {
        $boundaryMap = app(\App\Services\Property\PropertyMapService::class)->getBoundaryImage($this->id);

        return [
            'id'             => $this->id,
            'title'          => $this->title,
            'slug'           => $this->slug,
            'description'    => $this->description,
            'status'         => $this->status,
            'state_code'     => $this->state_code,
            'county'         => $this->county,
            'total_acres'    => $this->total_acres,
            'huntable_acres' => $this->huntable_acres,
            // Boundary ships via GET /api/properties/{id}/boundary (separate GeoJSON endpoint).
            // This flag tells the client whether to request it.
            'has_boundary'   => $this->boundary_geospatial_id !== null,
            // Raster boundary map (public route) — parity with the web detail page.
            'boundary_map_url' => $boundaryMap
                ? route('property-maps.show', $boundaryMap->document_id)
                : null,
            // GPS reference point only when the admin opted in (SEC-024).
            'boundary_map_coords' => (
                $boundaryMap
                && $boundaryMap->show_coords_publicly
                && $boundaryMap->latitude !== null
                && $boundaryMap->longitude !== null
            ) ? ['lat' => $boundaryMap->latitude, 'lng' => $boundaryMap->longitude] : null,
            'photos'         => $this->whenLoaded('photos', fn() =>
                $this->photos->map(fn($p) => [
                    'document_id' => $p->document_id,
                    'url'         => route('property-photos.show', $p->document_id),
                    'sort_order'  => $p->sort_order,
                    'caption'     => $p->caption,
                    'is_primary'  => $p->is_primary,
                ])->values()
            ),
            'species'        => $this->whenLoaded('species', fn() =>
                $this->species->map(fn($s) => [
                    'code'       => $s->species_code,
                    'is_primary' => $s->is_primary,
                ])->values()
            ),
            'amenities'      => $this->whenLoaded('amenities', fn() =>
                $this->amenities->map(fn($a) => [
                    'name'     => $a->name,
                    'category' => $a->category,
                    'icon'     => $a->icon_name,
                ])->values()
            ),
            'rules'          => $this->whenLoaded('rules', fn() =>
                $this->rules->map(fn($r) => [
                    'text'       => $r->rule_text,
                    'sort_order' => $r->sort_order,
                ])->values()
            ),
            'listings'       => $this->whenLoaded('activeListings', fn() =>
                $this->activeListings->map(fn($l) => [
                    'id'               => $l->id,
                    'listing_type'     => $l->listing_type,
                    'status'           => $l->status,
                    'season_start'     => $l->season_start?->toDateString(),
                    'season_end'       => $l->season_end?->toDateString(),
                    'min_hunters'      => $l->min_hunters,
                    'max_hunters'      => $l->max_hunters,
                    'price_per_hunter' => $l->price_per_hunter,
                    'price_total'      => $l->price_total,
                    'deposit_amount'   => $l->deposit_amount,
                    'deposit_percent'  => $l->deposit_percent,
                    'auto_renew'       => $l->auto_renew,
                    'visibility'       => $l->visibility,
                ])->values()
            ),
        ];
    }
}
